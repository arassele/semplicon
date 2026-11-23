<?php

namespace App\Domain;

use App\Domain\Import\FatturaPaSchema;
use App\Domain\Scadenziario\FiscalService;
use App\Domain\Scadenziario\Ledger;
use App\Domain\Scadenziario\ScheduleService;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\InvoiceIntegration;
use App\Models\User;
use App\Models\VatRegistration;
use App\Support\Decimal;
use App\Support\InvoiceIdentity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Fatture manuali: fatture senza importazione FatturaPA (es. carta, PDF, estero), allegato facoltativo.
 * Poi valgono verifica e registrazione come per le fatture importate.
 */
final class ManualInvoiceService
{
    public const ATTACHMENT_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'xml'];

    public const MAX_KB = 10240;

    /**
     * @param  array{direction: string, doc_type: string, number: string, doc_date: string, counterparty_name: string, counterparty_vat?: ?string, counterparty_cf?: ?string, description?: ?string, lines: list<array{rate: string, natura?: ?string, taxable: string, tax?: ?string}>}  $d
     */
    public function create(Company $company, User $actor, array $d, ?UploadedFile $file = null): Invoice
    {
        $p = $this->prepare($company, $d);

        return DB::transaction(function () use ($company, $actor, $d, $file, $p) {
            $document = $file ? $this->storeAttachment($company, $actor, $file) : null;
            $invoice = Invoice::create([...$this->attributes($company, $d, $p), 'document_id' => $document?->id, 'source' => 'manuale', 'body_index' => 1, 'format' => 'MANUALE',
                'status' => InvoiceStatus::Importata, 'issues' => []]);
            $this->writeSummaries($invoice, $p['lines']);
            app(ScheduleService::class)->createFor($invoice);
            Audit::record('invoice.created_manual', $actor, $company, $invoice, data: ['number' => $p['number'], 'direction' => $p['direction']->value, 'attachment' => $document?->original_name]);

            return $invoice;
        });
    }

    /**
     * Modifica una fattura manuale, anche dopo la registrazione (indicazione del committente, 19.11.2026). Se è registrata,
     * la registrazione viene ricalcolata con gli stessi dati, conti e % detraibile; se non corrisponde più
     * (es. numero diverso di righe di riepilogo), non viene salvato nulla.
     */
    public function update(Company $company, Invoice $invoice, User $actor, array $d, ?UploadedFile $file = null, bool $removeAttachment = false): Invoice
    {
        if ($invoice->source !== 'manuale') {
            RuleViolation::fail(['invoice' => 'Si possono modificare solo le fatture inserite manualmente. Una fattura importata si può eliminare e importare di nuovo.']);
        }
        if ($invoice->status === InvoiceStatus::Scartata) {
            RuleViolation::fail(['invoice' => 'La fattura è scartata: ripristinala prima di modificarla.']);
        }
        $p = $this->prepare($company, $d, $invoice->id);

        return DB::transaction(function () use ($company, $invoice, $actor, $d, $file, $removeAttachment, $p) {
            $invoice->load('vatSummaries');
            $before = ['number' => $invoice->number, 'doc_type' => $invoice->doc_type, 'doc_date' => $invoice->doc_date->toDateString(), 'direction' => $invoice->direction?->value,
                'counterparty' => $invoice->counterpartyName(), 'taxable' => (string) $invoice->taxable_total, 'tax' => (string) $invoice->tax_total, 'document_id' => $invoice->document_id];
            $active = $invoice->activeRegistration();
            $regData = $active ? self::registrationData($active) : null;
            $oldDocument = $invoice->document_id;
            $documentId = $file ? $this->storeAttachment($company, $actor, $file)->id : ($removeAttachment ? null : $oldDocument);
            $invoice->update([...$this->attributes($company, $d, $p), 'document_id' => $documentId]);
            $invoice->vatSummaries()->delete();
            $this->writeSummaries($invoice, $p['lines']);
            if ($active) {
                try {
                    app(RegistrationService::class)->update($company, $active, $actor, $regData);
                } catch (ValidationException $e) {
                    RuleViolation::fail(['registration' => 'La registrazione '.$active->protocol.'/'.$active->year.' non è compatibile con le modifiche ('
                        .collect($e->errors())->flatten()->first().'). Elimina prima la registrazione oppure mantieni le stesse righe di riepilogo.']);
                }
            }
            $this->resyncSchedule($invoice);
            if ($oldDocument !== $documentId) {
                InvoiceService::cleanupDocument($company, $oldDocument);
            }
            Audit::record('invoice.updated', $actor, $company, $invoice, data: ['before' => $before, 'registered' => (bool) $active]);

            return $invoice->fresh();
        });
    }

    /** Dati di una registrazione esistente per il ricalcolo dopo una modifica della fattura. */
    public static function registrationData(VatRegistration $r): array
    {
        $r->load('lines');

        return [
            'direction' => $r->direction->value, 'receipt_date' => $r->receipt_date?->toDateString(), 'registration_date' => $r->registration_date->toDateString(),
            'year' => $r->year, 'period' => $r->period, 'notes' => $r->notes,
            'lines' => $r->lines->map(fn ($l) => ['account_id' => $l->account_id, 'deductible_pct' => (string) $l->deductible_pct])->all(),
            'acknowledged' => array_merge($r->acknowledged ?? [], ['Nota di credito con importi negativi: verrà registrata come riduzione con gli importi in valore assoluto.']),
        ] + ($r->integration_id && ($int = InvoiceIntegration::find($r->integration_id)) ? ['integration_id' => $int->id,
            'integration' => collect($int->groups)->mapWithKeys(fn ($g) => [$g['summary_index'] => ['rate' => $g['rate']]])->all()] : []);
    }

    /** Verifica i dati inseriti e calcola righe, parti e identità sostanziale. */
    private function prepare(Company $company, array $d, ?int $ignoreId = null): array
    {
        $direction = InvoiceDirection::from($d['direction']);
        $errors = [];
        if (FatturaPaSchema::label('TipoDocumentoType', $d['doc_type']) === null) {
            $errors['doc_type'] = 'Tipo documento non valido.';
        }
        if ($d['doc_date'] > now()->toDateString()) {
            $errors['doc_date'] = 'La data del documento non può essere nel futuro.';
        }
        // Valuta estera (sezione 6): importo originale, cambio «1 EUR = x valuta», controvalore in euro per riga.
        $currency = strtoupper(($d['currency'] ?? '') ?: 'EUR');
        $rateFx = $currency === 'EUR' ? null : (($d['exchange_rate'] ?? '') !== '' ? str_replace(',', '.', (string) $d['exchange_rate']) : null);
        if ($currency !== 'EUR' && ($rateFx === null || (float) $rateFx <= 0)) {
            $errors['exchange_rate'] = 'Per la valuta '.$currency.' indica il cambio applicato (1 EUR = … '.$currency.').';
        }
        $lines = [];
        foreach (array_values($d['lines']) as $i => $l) {
            $natura = ($l['natura'] ?? '') ?: null;
            $rate = Decimal::normalize(str_replace(',', '.', (string) $l['rate']));
            $original = Decimal::toMinor(str_replace(',', '.', (string) $l['taxable']));
            $taxable = $rateFx ? self::toEur($original, $rateFx) : $original;
            if ($currency !== 'EUR' && $natura === null) {
                $errors["lines.{$i}.natura"] = 'Fattura in valuta estera: senza IVA italiana indica la natura (es. N2.2); l’IVA italiana si calcola con “Integra IVA”.';
            }
            if ($natura !== null && FatturaPaSchema::label('NaturaType', $natura) === null) {
                $errors["lines.{$i}.natura"] = 'Natura non valida.';
            }
            if ($natura !== null && Decimal::toMinor($rate) !== 0) {
                $errors["lines.{$i}.rate"] = 'Con la natura l’aliquota è 0.';
            }
            if ($natura === null && Decimal::toMinor($rate) === 0) {
                $errors["lines.{$i}.natura"] = 'Senza imposta indica la natura (es. N2.2, N4).';
            }
            // Imposta = imponibile × aliquota, arrotondata al centesimo (mezzo centesimo in valore assoluto); l'importo inserito deve coincidere.
            $expected = RegistrationService::percentOf($taxable, Decimal::toMinor($rate));
            $tax = ($l['tax'] ?? '') === '' || $l['tax'] === null ? $expected : Decimal::toMinor(str_replace(',', '.', (string) $l['tax']));
            if ($tax !== $expected) {
                $errors["lines.{$i}.tax"] = 'L’imposta non corrisponde a imponibile × aliquota (attesa '.str_replace('.', ',', Decimal::fromMinor($expected)).').';
            }
            $lines[] = ['rate' => $rate, 'natura' => $natura, 'taxable' => $taxable, 'tax' => $tax, 'original' => $rateFx ? $original : null,
                'description' => trim((string) ($l['description'] ?? '')) ?: (trim((string) ($d['description'] ?? '')) ?: 'Riga '.($i + 1))];
        }
        if ($errors) {
            RuleViolation::fail($errors);
        }
        $own = ['name' => $company->name, 'vat' => 'IT'.$company->partita_iva, 'cf' => $company->codice_fiscale];
        $cp = ['name' => trim($d['counterparty_name']), 'vat' => self::vat($d['counterparty_vat'] ?? null, $d['counterparty_country'] ?? null), 'cf' => ($d['counterparty_cf'] ?? '') ? mb_strtoupper(trim($d['counterparty_cf'])) : null];
        $cpCountry = strtoupper(($d['counterparty_country'] ?? '') ?: (($cp['vat'] && preg_match('/^([A-Z]{2})/', $cp['vat'], $mm)) ? $mm[1] : 'IT'));
        $cpAddress = array_filter(['indirizzo' => trim((string) ($d['counterparty_address'] ?? '')), 'cap' => trim((string) ($d['counterparty_zip'] ?? '')), 'comune' => trim((string) ($d['counterparty_city'] ?? '')),
            'provincia' => strtoupper(trim((string) ($d['counterparty_province'] ?? ''))), 'nazione' => $cpCountry], fn ($v) => $v !== '');
        [$supplier, $customer] = $direction === InvoiceDirection::Vendita ? [$own, $cp] : [$cp, $own];
        $number = trim($d['number']);
        // Stessa identità sostanziale dell'importazione (ticket 08): emittente (cedente), tipo, anno, numero.
        $identity = InvoiceIdentity::key($supplier, $d['doc_type'], $d['doc_date'], $number);
        if ($company->invoices()->where('identity_key', $identity)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            RuleViolation::fail(['number' => 'Esiste già una fattura dello stesso emittente con lo stesso tipo, numero e anno.']);
        }

        return compact('direction', 'lines', 'supplier', 'customer', 'number', 'identity', 'currency', 'rateFx', 'cpCountry', 'cpAddress');
    }

    private function attributes(Company $company, array $d, array $p): array
    {
        $taxable = array_sum(array_column($p['lines'], 'taxable'));
        $tax = array_sum(array_column($p['lines'], 'tax'));

        return [
            'company_id' => $company->id, 'direction' => $p['direction'], 'doc_type' => $d['doc_type'], 'number' => $p['number'], 'doc_date' => $d['doc_date'],
            'total_amount' => Decimal::fromMinor($taxable + $tax), 'taxable_total' => Decimal::fromMinor($taxable), 'tax_total' => Decimal::fromMinor($tax),
            'supplier_name' => $p['supplier']['name'], 'supplier_vat' => $p['supplier']['vat'], 'supplier_cf' => $p['supplier']['cf'],
            'customer_name' => $p['customer']['name'], 'customer_vat' => $p['customer']['vat'], 'customer_cf' => $p['customer']['cf'],
            'identity_key' => $p['identity'], 'content_hash' => hash('sha256', json_encode([$d['doc_type'], $p['number'], $d['doc_date'], $p['lines'], $p['supplier'], $p['customer']])),
            'business_hash' => InvoiceIdentity::businessHash(['doc_type' => $d['doc_type'], 'number' => $p['number'], 'date' => $d['doc_date'], 'currency' => 'EUR', 'total_amount' => Decimal::fromMinor($taxable + $tax),
                'supplier' => $p['supplier'], 'customer' => $p['customer'], 'lines' => [],
                'summaries' => array_map(fn ($l) => ['rate' => $l['rate'], 'natura' => $l['natura'], 'taxable' => Decimal::fromMinor($l['taxable']), 'tax' => Decimal::fromMinor($l['tax'])], $p['lines'])]),
            'description' => ($d['description'] ?? '') ?: null,
            'receipt_date' => $p['direction'] === InvoiceDirection::Acquisto ? (($d['receipt_date'] ?? '') ?: null) : null,
            'currency' => $p['currency'], 'exchange_rate' => $p['rateFx'], 'original_total' => $p['rateFx'] ? Decimal::fromMinor(array_sum(array_map(fn ($l) => $l['original'] + $l['tax'], $p['lines']))) : null,
            $p['direction'] === InvoiceDirection::Vendita ? 'customer_country' : 'supplier_country' => $p['cpCountry'],
            $p['direction'] === InvoiceDirection::Vendita ? 'customer_address' : 'supplier_address' => $p['cpAddress'] ?: null,
            $p['direction'] === InvoiceDirection::Vendita ? 'supplier_country' : 'customer_country' => 'IT',
        ];
    }

    /** Righe come righe di fattura, riepilogo per aliquota/natura (come DatiRiepilogo di una FatturaPA). */
    private function writeSummaries(Invoice $invoice, array $lines): void
    {
        $groups = [];
        foreach ($lines as $l) {
            $k = $l['natura'] ? 'N:'.$l['natura'] : 'R:'.$l['rate'];
            $groups[$k] ??= ['rate' => $l['rate'], 'natura' => $l['natura'], 'taxable' => 0, 'tax' => 0];
            $groups[$k]['taxable'] += $l['taxable'];
            $groups[$k]['tax'] += $l['tax'];
        }
        foreach ($groups as $g) {
            $invoice->vatSummaries()->create(['rate' => $g['rate'], 'natura' => $g['natura'], 'taxable' => Decimal::fromMinor($g['taxable']), 'tax' => Decimal::fromMinor($g['tax'])]);
        }
        $invoice->lines()->delete();
        foreach (array_values($lines) as $i => $l) {
            $invoice->lines()->create(['line_no' => $i + 1, 'description' => mb_substr($l['description'], 0, 1000), 'quantity' => null, 'unit' => null,
                'unit_price' => Decimal::fromMinor($l['taxable']), 'total' => Decimal::fromMinor($l['taxable']), 'rate' => $l['rate'], 'natura' => $l['natura'],
                'original_total' => $l['original'] !== null ? Decimal::fromMinor($l['original']) : null]);
        }
    }

    /** Controvalore in euro in centesimi: importo originale / cambio (1 EUR = cambio valuta), mezzo centesimo in valore assoluto. */
    public static function toEur(int $originalCents, string $rate): int
    {
        // Intero con 6 decimali del cambio.
        [$i, $f] = array_pad(explode('.', $rate), 2, '');
        $r = (int) $i * 1000000 + (int) str_pad(substr($f, 0, 6), 6, '0');

        return $r > 0 ? intdiv(abs($originalCents) * 1000000 * 2 + $r, 2 * $r) * ($originalCents < 0 ? -1 : 1) : 0;
    }

    /** Allegato a posteriori, finché la fattura non è ancora registrata. */
    public function attach(Company $company, Invoice $invoice, User $actor, UploadedFile $file): void
    {
        if ($invoice->source !== 'manuale' || ! $invoice->status->isOpen() || $invoice->document_id) {
            RuleViolation::fail(['file' => 'Si può allegare un documento solo a una fattura manuale da registrare e ancora senza allegato.']);
        }
        DB::transaction(function () use ($company, $invoice, $actor, $file) {
            $document = $this->storeAttachment($company, $actor, $file);
            $invoice->update(['document_id' => $document->id]);
            Audit::record('invoice.attachment_added', $actor, $company, $invoice, data: ['attachment' => $document->original_name]);
        });
    }

    public function storeAttachment(Company $company, User $actor, UploadedFile $file): Document
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $sha = hash('sha256', $bytes);
        if ($existing = $company->documents()->where('sha256', $sha)->first()) {
            return $existing; // salvare lo stesso file una sola volta
        }
        $ext = strtolower($file->getClientOriginalExtension());
        $kind = $ext === 'jpeg' ? 'jpg' : $ext;
        $path = "attachments/{$company->id}/".substr($sha, 0, 2)."/{$sha}.{$kind}";
        Storage::disk('local')->put($path, $bytes);

        return Document::create([
            'company_id' => $company->id, 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'sha256' => $sha,
            'size' => strlen($bytes), 'kind' => $kind, 'storage_path' => $path, 'uploaded_by' => $actor->id,
        ]);
    }

    private static function vat(?string $v, ?string $country = null): ?string
    {
        $v = strtoupper(preg_replace('/\s+/', '', (string) $v));
        $country = strtoupper((string) $country);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^[A-Z]{2}/', $v)) {
            return $v;
        }

        return $country && $country !== 'IT' ? $country.$v : (preg_match('/^\d{11}$/', $v) ? 'IT'.$v : $v);
    }

    /** Dopo la modifica: la rata singola non modificata e non pagata segue il nuovo importo; altrimenti avviso «Importo da verificare». */
    private function resyncSchedule(Invoice $invoice): void
    {
        $invoice = $invoice->fresh();
        $expected = ScheduleService::expectedPayable($invoice);
        $rates = $invoice->installments()->get();
        $paid = $rates->sum(fn ($r) => Ledger::settledCents($r));
        if ($rates->count() === 1 && ! $rates[0]->edited && $paid === 0) {
            $rates[0]->update(['amount' => Decimal::fromMinor($expected)]);
            $invoice->update(['amount_check' => false]);
        } else {
            $invoice->update(['amount_check' => $rates->sum(fn ($r) => Decimal::toMinor((string) $r->amount)) !== $expected]);
        }
        app(FiscalService::class)->sync($invoice);
    }
}
