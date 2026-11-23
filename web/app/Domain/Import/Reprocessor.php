<?php

namespace App\Domain\Import;

use App\Domain\Audit;
use App\Domain\Liquidazioni\SettlementCalculator;
use App\Domain\ManualInvoiceService;
use App\Domain\RegistrationService;
use App\Domain\RuleViolation;
use App\Domain\Scadenziario\FiscalService;
use App\Domain\Scadenziario\Ledger;
use App\Domain\Scadenziario\ScheduleService;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Document;
use App\Models\ImportItem;
use App\Models\Installment;
use App\Models\Invoice;
use App\Models\InvoiceReprocessing;
use App\Models\InvoiceVersion;
use App\Models\User;
use App\Models\VatRegistration;
use App\Support\Decimal;
use App\Support\InvoiceIdentity;
use App\Support\VatPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * «Rielabora XML» (incarico di sviluppo 19.11.2026, sezione 2): rileggere un XML con la logica di importazione attuale, associarlo al
 * documento esistente, mostrare le differenze per campo e riga e recepirlo solo dopo conferma con lo stesso
 * ID fattura. Documenti registrati tramite il percorso di rettifica, periodi bloccati impediti.
 */
final class Reprocessor
{
    private const FIELD_LABELS = [
        'doc_type' => 'Tipo documento', 'number' => 'Numero', 'doc_date' => 'Data', 'currency' => 'Valuta', 'total_amount' => 'Totale documento',
        'taxable_total' => 'Imponibile', 'tax_total' => 'Imposta', 'ritenuta' => 'Ritenuta', 'supplier_name' => 'Cedente', 'supplier_vat' => 'P. IVA cedente',
        'supplier_cf' => 'C.F. cedente', 'customer_name' => 'Cessionario', 'customer_vat' => 'P. IVA cessionario', 'customer_cf' => 'C.F. cessionario',
        'supplier_address' => 'Sede cedente', 'customer_address' => 'Sede cessionario',
    ];

    /** Candidati nella stessa azienda, tipo di documento e direzione: prima l'identificativo di importazione (file + corpo), poi l'identità sostanziale. */
    public function candidates(Company $company, array $parsed, array $body, ?InvoiceDirection $direction, ?Document $document): array
    {
        if ($document) {
            $byDoc = $company->invoices()->where('document_id', $document->id)->where('body_index', $body['index'])->get();
            if ($byDoc->count() === 1) {
                return ['match', $byDoc->first(), collect()];
            }
        }
        $identity = InvoiceIdentity::key($parsed['supplier'], $body['doc_type'], $body['date'], $body['number']);
        $all = $company->invoices()->where('identity_key', $identity)->where('doc_type', $body['doc_type'])
            ->where(fn ($q) => $direction ? $q->where('direction', $direction)->orWhereNull('direction') : $q)->orderBy('id')->get();
        if ($all->isEmpty()) {
            return ['none', null, collect()];
        }
        $consistent = $all->filter(fn (Invoice $i) => $i->number === $body['number'] && $i->doc_date->toDateString() === $body['date']
            && ($i->customer_vat ?? $i->customer_cf) === ($parsed['customer']['vat'] ?? $parsed['customer']['cf']));
        if ($all->count() === 1 && $consistent->count() === 1) {
            return ['match', $all->first(), collect()];
        }

        return ['ambiguous', null, $all];
    }

    /** Risultato per corpo fattura in modalità «Rielabora XML»; null = nessun documento esistente (importare come nuovo). */
    public function propose(Company $company, ?ImportItem $item, ?Document $document, array $parsed, array $body, ?InvoiceDirection $direction, int $actorId): ?array
    {
        [$kind, $invoice, $cands] = $this->candidates($company, $parsed, $body, $direction, $document);
        $res = fn (string $status, string $msg, ?int $existing = null, ?int $rep = null) => InvoiceImporter::result($status, $body['index'], $body['number'], $body['date'], $body['doc_type'],
            existing: $existing, message: $msg) + ($rep ? ['reprocessing_id' => $rep] : []);
        $payload = ['format' => $parsed['format'], 'supplier' => $parsed['supplier'], 'customer' => $parsed['customer'], 'body' => $body, 'direction' => $direction?->value];
        if ($kind === 'none') {
            return null;
        }
        if ($kind === 'ambiguous') {
            $r = InvoiceReprocessing::create(['company_id' => $company->id, 'import_item_id' => $item?->id, 'document_id' => $document?->id, 'body_index' => $body['index'],
                'payload' => $payload, 'candidates' => $cands->map(fn ($i) => ['id' => $i->id, 'number' => $i->number, 'doc_date' => $i->doc_date->toDateString(), 'status' => $i->status->value,
                    'counterparty' => $i->direction ? $i->counterpartyName() : $i->supplier_name, 'total' => (string) $i->total_amount])->values()->all(),
                'status' => 'da_abbinare', 'message' => 'Più documenti o dati discordanti: scegli il documento da aggiornare prima di sostituire i dati.', 'created_by' => $actorId]);

            return $res('da_abbinare', 'Corrispondenza da verificare: scegli il documento da aggiornare.', null, $r->id);
        }
        if ($invoice->status === InvoiceStatus::Archiviata) {
            return $res('archiviata', 'Documento archiviato: resta in archivio. Per riportarlo in lista usa “Ripristina”.', $invoice->id);
        }
        $diff = $this->diff($invoice, $payload);
        if (! $diff['fields'] && ! $diff['summaries'] && ! $diff['lines']) {
            return $res('invariato', 'Documento invariato.', $invoice->id);
        }
        $r = $this->pending($company, $invoice, $item, $document, $payload, $diff, $actorId);

        return $res('modificato', $invoice->activeRegistration() ? 'Documento registrato con differenze: verifica gli effetti e conferma la rettifica.' : 'Documento modificato: verifica le differenze e conferma.', $invoice->id, $r->id);
    }

    private function pending(Company $company, Invoice $invoice, ?ImportItem $item, ?Document $document, array $payload, array $diff, int $actorId): InvoiceReprocessing
    {
        $hash = hash('sha256', json_encode($payload['body']['content_hash'] ?? $payload));
        foreach (InvoiceReprocessing::where('invoice_id', $invoice->id)->where('status', 'in_attesa')->get() as $old) {
            if (hash('sha256', json_encode($old->payload['body']['content_hash'] ?? $old->payload)) === $hash) {
                return $old; // ripetizione: nessun secondo modello
            }
            $old->update(['status' => 'scartata', 'message' => 'Sostituita da una nuova elaborazione.']);
        }
        [$relevant, $impacts] = $this->impacts($company, $invoice, $diff, $payload);

        return InvoiceReprocessing::create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'import_item_id' => $item?->id, 'document_id' => $document?->id,
            'body_index' => $payload['body']['index'], 'payload' => $payload, 'diff' => $diff, 'impacts' => $impacts, 'booking_relevant' => $relevant,
            'status' => 'in_attesa', 'created_by' => $actorId]);
    }

    /** Differenze per campo, gruppo IVA (DatiRiepilogo) e riga (DettaglioLinee). */
    public function diff(Invoice $invoice, array $payload): array
    {
        $invoice->loadMissing('vatSummaries', 'lines');
        $b = $payload['body'];
        $new = ['doc_type' => $b['doc_type'], 'number' => $b['number'], 'doc_date' => $b['date'], 'currency' => $b['currency'], 'total_amount' => $b['total_amount'],
            'taxable_total' => $b['taxable_total'], 'tax_total' => $b['tax_total'], 'ritenuta' => $b['ritenuta'] ?? '0.00',
            'supplier_name' => $payload['supplier']['name'], 'supplier_vat' => $payload['supplier']['vat'], 'supplier_cf' => $payload['supplier']['cf'],
            'customer_name' => $payload['customer']['name'], 'customer_vat' => $payload['customer']['vat'], 'customer_cf' => $payload['customer']['cf'],
            'supplier_address' => $payload['supplier']['address'] ?? null, 'customer_address' => $payload['customer']['address'] ?? null];
        $norm = fn ($v) => is_array($v) ? json_encode($v) : ($v === null ? null : (is_numeric($v) && str_contains((string) $v, '.') ? Decimal::normalize((string) $v) : (string) $v));
        $fields = [];
        foreach ($new as $k => $v) {
            $old = $invoice->{$k};
            $old = $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old;
            // Gli indirizzi mancavano nei documenti vecchi: l'integrazione conta come differenza, ma non rilevante ai fini contabili.
            if ($norm($old) !== $norm($v) && ! ($old === null && $v === null)) {
                $fields[] = ['field' => $k, 'label' => self::FIELD_LABELS[$k], 'old' => is_array($old) ? self::addr($old) : $old, 'new' => is_array($v) ? self::addr($v) : $v];
            }
        }
        $sumKey = fn ($s) => [(string) Decimal::normalize((string) $s['rate']), $s['natura'] ?? null, Decimal::normalize((string) $s['taxable']), Decimal::normalize((string) $s['tax']), $s['esigibilita'] ?? null];
        $summaries = [];
        $oldS = $invoice->vatSummaries->values();
        foreach (array_values($b['summaries']) as $k => $s) {
            $o = $oldS[$k] ?? null;
            if (! $o) {
                $summaries[] = ['index' => $k, 'change' => 'aggiunta', 'old' => null, 'new' => self::sumText($s)];
            } elseif ($sumKey($o->toArray()) !== $sumKey($s)) {
                $summaries[] = ['index' => $k, 'change' => 'modificata', 'old' => self::sumText($o->toArray()), 'new' => self::sumText($s)];
            }
        }
        for ($k = count($b['summaries']); $k < $oldS->count(); $k++) {
            $summaries[] = ['index' => $k, 'change' => 'rimossa', 'old' => self::sumText($oldS[$k]->toArray()), 'new' => null];
        }
        $lineKey = fn ($l) => [(string) $l['description'], $l['quantity'] === null ? null : Decimal::normalize((string) $l['quantity']), Decimal::normalize((string) $l['unit_price']),
            Decimal::normalize((string) $l['total']), Decimal::normalize((string) $l['rate']), $l['natura'] ?? null];
        $oldL = $invoice->lines->keyBy('line_no');
        $lines = [];
        foreach ($b['lines'] as $l) {
            $o = $oldL[$l['line_no']] ?? null;
            if (! $o) {
                $lines[] = ['line_no' => $l['line_no'], 'change' => 'aggiunta', 'old' => null, 'new' => self::lineText($l)];
            } elseif ($lineKey($o->toArray()) !== $lineKey($l)) {
                $lines[] = ['line_no' => $l['line_no'], 'change' => 'modificata', 'old' => self::lineText($o->toArray()), 'new' => self::lineText($l)];
            }
        }
        $newNos = array_column($b['lines'], 'line_no');
        foreach ($oldL as $no => $o) {
            if (! in_array($no, $newNos, true)) {
                $lines[] = ['line_no' => $no, 'change' => 'rimossa', 'old' => self::lineText($o->toArray()), 'new' => null];
            }
        }
        usort($lines, fn ($x, $y) => $x['line_no'] <=> $y['line_no']);

        return ['fields' => $fields, 'summaries' => $summaries, 'lines' => $lines];
    }

    /**
     * Effetti sui documenti registrati e se la modifica è rilevante ai fini contabili.
     *
     * @return array{0: bool, 1: list<array{type: string, text: string}>}
     */
    public function impacts(Company $company, Invoice $invoice, array $diff, array $payload): array
    {
        $relevantFields = ['doc_type', 'doc_date', 'currency', 'taxable_total', 'tax_total', 'total_amount', 'supplier_vat', 'customer_vat'];
        $relevant = (bool) $diff['summaries'] || collect($diff['fields'])->contains(fn ($f) => in_array($f['field'], $relevantFields, true));
        $out = [];
        $reg = $invoice->activeRegistration();
        if ($reg) {
            $label = VatPeriod::label($reg->year, $reg->period, $reg->periodicity);
            if ($relevant) {
                $out[] = ['type' => 'contabilizzazione', 'text' => 'Registrazione prot. '.$reg->protocol.'/'.$reg->year.': '.($diff['summaries'] ? count($diff['summaries']).' gruppi IVA cambiano; conti e % detraibile restano assegnati per gruppo.' : 'importi o dati del documento cambiano.')];
                $out[] = ['type' => 'registri', 'text' => "Registro IVA {$reg->direction->value}: periodo {$label}."];
                if (collect($payload['body']['summaries'])->contains(fn ($s) => str_starts_with((string) ($s['natura'] ?? ''), 'N6')) || $reg->integration_id) {
                    $out[] = ['type' => 'reverse_charge', 'text' => 'Operazione in reverse charge: verifica l’integrazione IVA dopo la rettifica.'];
                }
                $s = SettlementCalculator::latest($company, $reg->year, $reg->period);
                $out[] = ['type' => 'liquidazione', 'text' => $s ? "Liquidazione {$label} confermata: periodo bloccato, la modifica diretta non è consentita." : "Liquidazione {$label} non ancora confermata: verrà ricalcolata."];
            } else {
                $out[] = ['type' => 'contabilizzazione', 'text' => 'Nessun effetto sulla registrazione: cambiano solo descrizioni, righe o dati anagrafici.'];
            }
        }
        $newPayable = $this->payableOf($payload, $invoice);
        $settled = $invoice->installments()->get()->sum(fn ($i) => Ledger::settledCents($i));
        if (Decimal::toMinor((string) $invoice->installments()->sum('amount')) !== $newPayable) {
            $out[] = ['type' => 'scadenze', 'text' => 'Scadenze: importo da '.($invoice->direction === InvoiceDirection::Vendita ? 'incassare' : 'pagare').' '.self::eur($newPayable).', già saldati '.self::eur($settled).', residuo '.self::eur(max(0, $newPayable - $settled)).'.'];
        }

        return [$relevant, $out];
    }

    /** Periodo di una registrazione valida bloccato (liquidazione confermata)? */
    public static function lockedPeriod(Company $company, ?VatRegistration $reg): ?string
    {
        return $reg && SettlementCalculator::latest($company, $reg->year, $reg->period) ? VatPeriod::label($reg->year, $reg->period, $reg->periodicity) : null;
    }

    /** «Rielabora XML» direttamente dalla fattura: rileggere l'originale salvato con la logica di importazione attuale. */
    public function fromInvoice(Company $company, Invoice $invoice, User $actor): array
    {
        $doc = $invoice->document;
        if (! $doc || ! in_array($doc->kind, ['xml', 'p7m'], true) || $invoice->source === 'manuale') {
            RuleViolation::fail(['reprocessing' => 'Per questa fattura non c’è un file XML da rielaborare.']);
        }
        $bytes = Storage::disk('local')->get($doc->storage_path);
        $dom = SafeXml::parse($doc->kind === 'p7m' ? P7m::extract($bytes) : $bytes);
        FatturaPaSchema::validate($dom);
        $parsed = app(FatturaParser::class)->parse($dom);
        $body = collect($parsed['bodies'])->firstWhere('index', $invoice->body_index) ?? RuleViolation::fail(['reprocessing' => 'Corpo del documento non trovato nel file.']);

        return $this->propose($company, null, $doc, $parsed, $body, $invoice->direction, $actor->id) ?? ['status' => 'invariato'];
    }

    /** Associazione in «Corrispondenza da verificare»: documento scelto (poi confronto) o importazione come nuovo documento. */
    public function assign(Company $company, InvoiceReprocessing $r, User $actor, ?int $invoiceId): ?InvoiceReprocessing
    {
        if ($r->status !== 'da_abbinare') {
            RuleViolation::fail(['assign' => 'Elaborazione già gestita.']);
        }
        if ($invoiceId === null) {
            $id = app(InvoiceImporter::class)->createFromPayload($company, $r);
            $r->update(['status' => 'applicata', 'invoice_id' => $id, 'applied_by' => $actor->id, 'applied_at' => now(), 'message' => 'Importato come nuovo documento.']);

            return null;
        }
        if (! in_array($invoiceId, array_column($r->candidates ?? [], 'id'), true)) {
            RuleViolation::fail(['assign' => 'Scegli uno dei documenti proposti.']);
        }
        $invoice = Invoice::where('company_id', $company->id)->findOrFail($invoiceId);
        if ($invoice->status === InvoiceStatus::Archiviata) {
            RuleViolation::fail(['assign' => 'Il documento scelto è archiviato: ripristinalo prima.']);
        }
        $diff = $this->diff($invoice, $r->payload);
        $r->update(['status' => 'scartata', 'message' => 'Abbinato alla fattura '.$invoice->number.'.']);

        return $this->pending($company, $invoice, $r->importItem, $r->document, $r->payload, $diff, $actor->id);
    }

    /**
     * Recepimento confermato in una transazione. $withStorno: percorso di rettifica con periodo bloccato (storno oggi, poi
     * il documento con i nuovi dati torna «da registrare»).
     */
    public function apply(Company $company, InvoiceReprocessing $r, User $actor, bool $withStorno = false): Invoice
    {
        // Verificare e documentare prima il blocco (fuori dalla transazione, affinché lo stato «bloccata» resti).
        if (! $withStorno && in_array($r->status, ['in_attesa', 'bloccata'], true) && ($inv = Invoice::find($r->invoice_id)) && ($reg = $inv->activeRegistration())) {
            [$relevant] = $this->impacts($company, $inv, $this->diff($inv, $r->payload), $r->payload);
            if ($relevant && ($period = self::lockedPeriod($company, $reg))) {
                $r->update(['status' => 'bloccata', 'message' => "Periodo {$period} bloccato (liquidazione confermata): aggiornamento diretto non consentito. Usa la rettifica con storno nel periodo corrente."]);
                RuleViolation::fail(['reprocessing' => "La registrazione è nel periodo {$period}, già liquidato: aggiornamento diretto bloccato. Usa “Storna e aggiorna”: la registrazione viene stornata oggi e la fattura aggiornata torna da registrare."]);
            }
        }

        return DB::transaction(function () use ($company, $r, $actor, $withStorno) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $r = InvoiceReprocessing::whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($r->status === 'applicata') {
                return $r->invoice; // secondo clic
            }
            if (! in_array($r->status, ['in_attesa', 'bloccata'], true)) {
                RuleViolation::fail(['reprocessing' => 'Questa elaborazione non è più valida.']);
            }
            $invoice = Invoice::whereKey($r->invoice_id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === InvoiceStatus::Archiviata) {
                RuleViolation::fail(['reprocessing' => 'La fattura è archiviata: ripristinala prima di aggiornarla.']);
            }
            $diff = $this->diff($invoice, $r->payload);
            [$relevant] = $this->impacts($company, $invoice, $diff, $r->payload);
            $reg = $invoice->activeRegistration();
            if ($reg && $relevant) {
                if (($period = self::lockedPeriod($company, $reg)) && ! $withStorno) {
                    $r->update(['status' => 'bloccata', 'message' => "Periodo {$period} bloccato (liquidazione confermata): aggiornamento diretto non consentito. Usa la rettifica con storno nel periodo corrente."]);
                    RuleViolation::fail(['reprocessing' => "La registrazione è nel periodo {$period}, già liquidato: aggiornamento diretto bloccato. Usa “Storna e aggiorna”: la registrazione viene stornata oggi e la fattura aggiornata torna da registrare."]);
                }
                if ($withStorno) {
                    app(RegistrationService::class)->reverse($company, $reg, $actor, 'Rettifica da nuova elaborazione XML', now()->toDateString());
                    $reg = null;
                    $invoice->refresh();
                } elseif (count($r->payload['body']['summaries']) !== $invoice->vatSummaries()->count()) {
                    RuleViolation::fail(['reprocessing' => 'I gruppi IVA cambiano di numero: la registrazione non si aggiorna da sola. Usa “Storna e aggiorna” e registra di nuovo con i conti corretti.']);
                }
            }
            $regData = $reg && $relevant ? ManualInvoiceService::registrationData($reg) : null;

            // Conservare la versione precedente.
            $invoice->load('vatSummaries', 'lines');
            InvoiceVersion::create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'version' => (int) InvoiceVersion::where('invoice_id', $invoice->id)->max('version') + 1,
                'snapshot' => ['invoice' => collect($invoice->getAttributes())->except(['id'])->all(), 'summaries' => $invoice->vatSummaries->map->only(['rate', 'natura', 'taxable', 'tax', 'esigibilita', 'normative_reference'])->all(),
                    'lines' => $invoice->lines->map->only(['line_no', 'description', 'quantity', 'unit', 'unit_price', 'total', 'rate', 'natura'])->all()],
                'document_id' => $invoice->document_id, 'reason' => 'Nuova elaborazione XML', 'created_by' => $actor->id]);

            $p = $r->payload;
            $b = $p['body'];
            $identity = InvoiceIdentity::key($p['supplier'], $b['doc_type'], $b['date'], $b['number']);
            $invoice->update([
                'doc_type' => $b['doc_type'], 'number' => $b['number'], 'doc_date' => $b['date'], 'currency' => $b['currency'], 'total_amount' => $b['total_amount'],
                'taxable_total' => $b['taxable_total'], 'tax_total' => $b['tax_total'], 'ritenuta' => $b['ritenuta'] ?? '0.00', 'format' => $p['format'],
                'supplier_name' => $p['supplier']['name'], 'supplier_vat' => $p['supplier']['vat'], 'supplier_cf' => $p['supplier']['cf'],
                'customer_name' => $p['customer']['name'], 'customer_vat' => $p['customer']['vat'], 'customer_cf' => $p['customer']['cf'],
                'supplier_address' => $p['supplier']['address'] ?? null, 'customer_address' => $p['customer']['address'] ?? null,
                'supplier_country' => $p['supplier']['country'] ?? null, 'customer_country' => $p['customer']['country'] ?? null,
                'identity_key' => $identity, 'content_hash' => $b['content_hash'], 'business_hash' => InvoiceIdentity::businessHash([...$b, 'supplier' => $p['supplier'], 'customer' => $p['customer']]),
                'document_id' => $r->document_id ?? $invoice->document_id,
            ]);
            $invoice->vatSummaries()->delete();
            $invoice->vatSummaries()->createMany($b['summaries']);
            $invoice->lines()->delete();
            $invoice->lines()->createMany($b['lines']);
            $this->resyncInstallments($invoice->fresh(), $b['payments'] ?? []);
            if ($regData) {
                try {
                    app(RegistrationService::class)->update($company, $reg, $actor, $regData);
                } catch (ValidationException $e) {
                    RuleViolation::fail(['reprocessing' => 'La registrazione non è compatibile con i nuovi dati ('.collect($e->errors())->flatten()->first().'). Usa “Storna e aggiorna”.']);
                }
            }
            app(FiscalService::class)->sync($invoice->fresh());
            $r->update(['status' => 'applicata', 'applied_by' => $actor->id, 'applied_at' => now(), 'message' => $withStorno ? 'Applicata con storno della registrazione.' : 'Applicata.']);
            Audit::record('invoice.reprocessed', $actor, $company, $invoice, data: ['fields' => array_column($diff['fields'], 'field'), 'summaries' => count($diff['summaries']), 'lines' => count($diff['lines']),
                'with_storno' => $withStorno, 'document' => $r->document_id]);

            return $invoice->fresh();
        });
    }

    public function discard(Company $company, InvoiceReprocessing $r, User $actor): void
    {
        if (! in_array($r->status, ['in_attesa', 'bloccata', 'da_abbinare'], true)) {
            RuleViolation::fail(['reprocessing' => 'Elaborazione già gestita.']);
        }
        $r->update(['status' => 'scartata', 'message' => 'Scartata dall’utente.', 'applied_by' => $actor->id, 'applied_at' => now()]);
        Audit::record('invoice.reprocessing_discarded', $actor, $company, $r->invoice);
    }

    /** Saldo aperto dopo gli importi modificati in base ai pagamenti esistenti. */
    private function resyncInstallments(Invoice $invoice, array $payments): void
    {
        $expected = ScheduleService::expectedPayable($invoice);
        $rates = $invoice->installments()->get();
        $paid = $rates->sum(fn ($i) => Ledger::settledCents($i));
        $hasAlloc = $rates->contains(fn ($i) => Ledger::settledCents($i) > 0);
        if ($payments && ! $hasAlloc && ! $rates->contains('edited', true)) {
            Installment::where('invoice_id', $invoice->id)->delete();
            app(ScheduleService::class)->createFor($invoice->fresh(), $payments);

            return;
        }
        if ($rates->count() === 1 && $expected >= $paid) {
            $rates[0]->update(['amount' => Decimal::fromMinor($expected)]);
            $invoice->update(['amount_check' => false]);

            return;
        }
        $invoice->update(['amount_check' => $rates->sum(fn ($i) => Decimal::toMinor((string) $i->amount)) !== $expected]);
    }

    private function payableOf(array $payload, Invoice $invoice): int
    {
        $b = $payload['body'];
        $total = $b['total_amount'] !== null ? Decimal::toMinor($b['total_amount']) : Decimal::toMinor($b['taxable_total']) + Decimal::toMinor($b['tax_total']);
        $split = collect($b['summaries'])->where('esigibilita', 'S')->sum(fn ($s) => Decimal::toMinor((string) $s['tax']));

        return abs($total - Decimal::toMinor($b['ritenuta'] ?? '0.00') - $split);
    }

    private static function sumText(array $s): string
    {
        return ($s['natura'] ?? null ? $s['natura'] : str_replace('.', ',', Decimal::normalize((string) $s['rate'])).'%').' · imponibile '.self::eur(Decimal::toMinor((string) $s['taxable'])).' · imposta '.self::eur(Decimal::toMinor((string) $s['tax']));
    }

    private static function lineText(array $l): string
    {
        return mb_substr((string) $l['description'], 0, 80).' · '.self::eur(Decimal::toMinor(Decimal::normalize((string) $l['total']))).' · '.($l['natura'] ?? null ? $l['natura'] : str_replace('.', ',', Decimal::normalize((string) $l['rate'])).'%');
    }

    private static function addr(?array $a): ?string
    {
        return $a ? trim(implode(', ', array_filter([trim(($a['indirizzo'] ?? '').' '.($a['civico'] ?? '')), trim(($a['cap'] ?? '').' '.($a['comune'] ?? '')), $a['provincia'] ?? null, $a['nazione'] ?? null]))) : null;
    }

    private static function eur(int $c): string
    {
        [$i, $d] = explode('.', Decimal::fromMinor(abs($c)));

        return ($c < 0 ? '−' : '').number_format((int) $i, 0, ',', '.').','.$d.' €';
    }
}
