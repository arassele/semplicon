<?php

namespace App\Domain\Import;

use App\Domain\CompanyOptions;
use App\Domain\Esterometro\IntegrationService;
use App\Domain\InvoiceService;
use App\Domain\Scadenziario\ScheduleService;
use App\Enums\ImportItemStatus;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Invoice;
use App\Models\InvoiceReprocessing;
use App\Models\User;
use App\Support\Decimal;
use App\Support\InvoiceIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Importa un file (XML o P7M) per una sola azienda: archiviare l'originale, verificare, creare le fatture.
 * Tutte le query e i percorsi di archiviazione dipendono da $item->company_id.
 */
final class InvoiceImporter
{
    public function __construct(private FatturaParser $parser, private Reprocessor $reprocessor) {}

    public function import(ImportItem $item, string $bytes, int $uploadedBy, ?string $mode = null): void
    {
        $company = Company::findOrFail($item->company_id);
        $mode ??= ImportBatch::whereKey($item->import_batch_id)->value('mode') ?? 'salta';
        $sha = hash('sha256', $bytes);
        $messages = [];

        // 1. Lo stesso file è già presente?
        $existing = $company->documents()->where('sha256', $sha)->first();
        if ($existing && $mode === 'sostituisci') {
            // Sostituire il file: eliminare la versione esistente (non registrata) e rileggerla; le fatture registrate non vengono sostituite
            try {
                app(InvoiceService::class)->deleteDocument($company, $existing, User::findOrFail($uploadedBy));
                $existing = null;
            } catch (ValidationException $e) {
                $why = (string) collect($e->errors())->flatten()->first();
                $results = $company->invoices()->where('document_id', $existing->id)->orderBy('body_index')->get()->map(fn ($i) => self::result('gia_presente', $i->body_index, $i->number, $i->doc_date->toDateString(), $i->doc_type, existing: $i->id, message: "Non sostituita: {$why}"))->all();
                $this->finish($item, ImportItemStatus::Duplicato, ["Non sostituita: {$why}"], $existing, results: $results ?: [self::result('gia_presente', message: "Non sostituita: {$why}")]);

                return;
            }
        }
        if ($existing && $mode === 'rielabora') {
            // Sezione 2: rileggere e confrontare con la logica di importazione attuale anche i file identici byte per byte.
            $this->reprocessDocument($company, $item, $existing, $bytes, $uploadedBy);

            return;
        }
        if ($existing) {
            // Ticket 08: ripetizione identica byte per byte, risultato per ogni corpo fattura esistente.
            $results = $company->invoices()->where('document_id', $existing->id)->orderBy('body_index')->get()
                ->map(fn ($i) => $i->status === InvoiceStatus::Archiviata
                    ? self::result('archiviata', $i->body_index, $i->number, $i->doc_date->toDateString(), $i->doc_type, existing: $i->id, message: 'Documento archiviato: resta in archivio, per riportarlo in lista usa “Ripristina”.')
                    : self::result('gia_presente', $i->body_index, $i->number, $i->doc_date->toDateString(), $i->doc_type, existing: $i->id, message: 'Documento già presente.'))->all();
            $this->finish($item, ImportItemStatus::Duplicato, ['File già importato il '.$existing->created_at->format('d/m/Y').'.'], $existing,
                results: $results ?: [self::result('gia_presente', message: 'File già importato il '.$existing->created_at->format('d/m/Y').'.')]);

            return;
        }

        // 2. Archiviare l'originale invariato.
        $isP7m = str_ends_with(strtolower($item->filename), '.p7m') || (! $this->looksLikeXml($bytes) && P7m::looksLikeP7m($bytes));
        $kind = $isP7m ? 'p7m' : 'xml';
        $path = "documents/{$company->id}/".substr($sha, 0, 2)."/{$sha}.{$kind}";
        Storage::disk('local')->put($path, $bytes);
        try {
            $document = Document::create([
                'company_id' => $company->id, 'original_name' => mb_substr($item->filename, 0, 255), 'sha256' => $sha,
                'size' => strlen($bytes), 'kind' => $kind, 'storage_path' => $path, 'uploaded_by' => $uploadedBy,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Importazione parallela dello stesso file.
            $this->finish($item, ImportItemStatus::Duplicato, ['File già importato.'], $company->documents()->where('sha256', $sha)->first());

            return;
        }

        try {
            $xml = $isP7m ? P7m::extract($bytes) : $bytes;
            $dom = SafeXml::parse($xml);
            FatturaPaSchema::validate($dom);
            $parsed = $this->parser->parse($dom);
        } catch (ImportError $e) {
            $this->finish($item, ImportItemStatus::Errore, [$e->getMessage(), ...$e->details], $document,
                results: [self::result('non_leggibile', message: trim($e->getMessage().' '.implode(' ', array_slice($e->details, 0, 3))))]);

            return;
        }

        // 3. Il file riguarda questa azienda?
        $ownVat = 'IT'.$company->partita_iva;
        $ownCf = array_filter([$company->codice_fiscale, $company->partita_iva]);
        $matches = fn (array $p) => $p['vat'] === $ownVat || ($p['cf'] && in_array($p['cf'], $ownCf, true));
        $isSupplier = $matches($parsed['supplier']);
        $isCustomer = $matches($parsed['customer']);
        $checkVat = CompanyOptions::for($company)['import_check_vat'];
        if ($checkVat && ! $isSupplier && ! $isCustomer) {
            $this->finish($item, ImportItemStatus::Errore, [
                'Il documento non riguarda questa azienda: né il cedente né il cessionario hanno la partita IVA o il codice fiscale dell’azienda.',
            ], $document, results: array_map(fn ($b) => self::result('azienda_non_corrispondente', $b['index'], $b['number'], $b['date'], $b['doc_type'],
                message: 'Il documento non riguarda questa azienda.', parties: ['cedente' => $parsed['supplier'], 'cessionario' => $parsed['customer']]), $parsed['bodies']));

            return;
        }
        $direction = $isSupplier && ! $isCustomer ? InvoiceDirection::Vendita : ($isCustomer && ! $isSupplier ? InvoiceDirection::Acquisto : null);

        // 4. Una fattura per ogni corpo fattura.
        $created = 0;
        $duplicates = 0;
        $review = false;
        $results = [];
        foreach ($parsed['bodies'] as $body) {
            if ($mode === 'rielabora' && ($proposal = $this->reprocessor->propose($company, $item, $document, $parsed, $body, $direction, $uploadedBy))) {
                $results[] = $proposal;
                $duplicates++;

                continue;
            }
            // Sezione 5: associare i TD16–TD19 importati a un'integrazione già registrata manualmente (nessuna seconda registrazione).
            $extra = [];
            if (in_array($body['doc_type'], ['TD16', 'TD17', 'TD18', 'TD19'], true)) {
                $m = IntegrationService::matches($company, $parsed, $body);
                if ($m->count() === 1) {
                    $m[0]->update(['document_id' => $document->id]);
                    $duplicates++;
                    $results[] = self::result('integrazione_abbinata', $body['index'], $body['number'], $body['date'], $body['doc_type'], existing: $m[0]->invoice_id,
                        message: "Abbinato all’integrazione n. {$m[0]->number}/{$m[0]->year} già registrata: nessuna nuova registrazione.");

                    continue;
                }
                if ($m->count() > 1) {
                    $extra[] = 'Corrispondenza da verificare: più integrazioni manuali ('.$m->map(fn ($i) => "{$i->number}/{$i->year}")->join(', ').') hanno lo stesso fornitore, tipo e imponibile. Non registrare due volte.';
                }
            }
            [$status, $issues, $invoiceId, $existingId] = $this->storeBody($company, $document, $item, $parsed, $body, $direction, $extra, $mode, $uploadedBy);
            if ($status === 'archived') {
                $duplicates++;
                $results[] = self::result('archiviata', $body['index'], $body['number'], $body['date'], $body['doc_type'], existing: $existingId, message: 'Documento archiviato: resta in archivio, per riportarlo in lista usa “Ripristina”.');

                continue;
            }
            if ($status === 'duplicate') {
                $duplicates++;
                $messages[] = "Documento {$body['number']} del ".$this->date($body['date']).' già presente.';
                $results[] = self::result('gia_presente', $body['index'], $body['number'], $body['date'], $body['doc_type'], existing: $existingId, message: 'Documento già presente con lo stesso contenuto.');

                continue;
            }
            $results[] = self::result($status === 'conflict' ? 'conflitto' : 'importata', $body['index'], $body['number'], $body['date'], $body['doc_type'], $invoiceId, $existingId,
                $status === 'conflict' ? 'Stesso emittente, tipo, anno e numero di un documento esistente ma contenuto diverso: confronta i documenti.' : ($issues ? 'Importata, da verificare.' : 'Importata.'), issues: $issues);
            $created++;
            if ($issues) {
                $review = true;
                foreach ($issues as $issue) {
                    $messages[] = "Documento {$body['number']}: {$issue}";
                }
            }
        }

        $status = $created === 0 ? ImportItemStatus::Duplicato : ($review ? ImportItemStatus::DaVerificare : ImportItemStatus::Importato);
        if ($created > 0 && ! $review) {
            $messages[] = $created === 1 ? '1 documento importato.' : "{$created} documenti importati.";
        }
        $this->finish($item, $status, $messages, $document, $created, $results);
    }

    /** «Rielabora XML» di un file già salvato: ogni FatturaElettronicaBody singolarmente. */
    private function reprocessDocument(Company $company, ImportItem $item, Document $document, string $bytes, int $uploadedBy): void
    {
        try {
            $isP7m = $document->kind === 'p7m';
            $dom = SafeXml::parse($isP7m ? P7m::extract($bytes) : $bytes);
            FatturaPaSchema::validate($dom);
            $parsed = $this->parser->parse($dom);
        } catch (ImportError $e) {
            $this->finish($item, ImportItemStatus::Errore, [$e->getMessage(), ...$e->details], $document, results: [self::result('non_leggibile', message: $e->getMessage())]);

            return;
        }
        $direction = $this->direction($company, $parsed);
        $results = [];
        $created = 0;
        foreach ($parsed['bodies'] as $body) {
            if ($p = $this->reprocessor->propose($company, $item, $document, $parsed, $body, $direction, $uploadedBy)) {
                $results[] = $p;

                continue;
            }
            // Sezione 5: associare i TD16–TD19 importati a un'integrazione già registrata manualmente (nessuna seconda registrazione).
            $extra = [];
            if (in_array($body['doc_type'], ['TD16', 'TD17', 'TD18', 'TD19'], true)) {
                $m = IntegrationService::matches($company, $parsed, $body);
                if ($m->count() === 1) {
                    $m[0]->update(['document_id' => $document->id]);
                    $duplicates++;
                    $results[] = self::result('integrazione_abbinata', $body['index'], $body['number'], $body['date'], $body['doc_type'], existing: $m[0]->invoice_id,
                        message: "Abbinato all’integrazione n. {$m[0]->number}/{$m[0]->year} già registrata: nessuna nuova registrazione.");

                    continue;
                }
                if ($m->count() > 1) {
                    $extra[] = 'Corrispondenza da verificare: più integrazioni manuali ('.$m->map(fn ($i) => "{$i->number}/{$i->year}")->join(', ').') hanno lo stesso fornitore, tipo e imponibile. Non registrare due volte.';
                }
            }
            [$status, $issues, $invoiceId, $existingId] = $this->storeBody($company, $document, $item, $parsed, $body, $direction, $extra);
            $results[] = self::result($status === 'conflict' ? 'conflitto' : ($status === 'created' ? 'importata' : 'gia_presente'), $body['index'], $body['number'], $body['date'], $body['doc_type'], $invoiceId, $existingId,
                $status === 'created' ? 'Importata (posizione non presente prima).' : 'Documento già presente.', issues: $issues);
            $created += $status === 'created' || $status === 'conflict' ? 1 : 0;
        }
        $changed = collect($results)->whereIn('status', ['modificato', 'da_abbinare'])->count();
        $this->finish($item, $changed || $created ? ImportItemStatus::DaVerificare : ImportItemStatus::Duplicato,
            [$changed ? "{$changed} documenti con differenze da confermare." : 'Nuova elaborazione completata.'], $document, $created, $results);
    }

    /** Direzione dal punto di vista dell'azienda (null: entrambi i lati, es. autofattura). */
    private function direction(Company $company, array $parsed): ?InvoiceDirection
    {
        $ownVat = 'IT'.$company->partita_iva;
        $ownCf = array_filter([$company->codice_fiscale, $company->partita_iva]);
        $m = fn (array $p) => $p['vat'] === $ownVat || ($p['cf'] && in_array($p['cf'], $ownCf, true));
        $s = $m($parsed['supplier']);
        $c = $m($parsed['customer']);

        return $s && ! $c ? InvoiceDirection::Vendita : ($c && ! $s ? InvoiceDirection::Acquisto : null);
    }

    /** «Importa come nuovo documento» in «Corrispondenza da verificare». */
    public function createFromPayload(Company $company, InvoiceReprocessing $r): ?int
    {
        $p = $r->payload;
        $direction = $p['direction'] ? InvoiceDirection::from($p['direction']) : null;
        [, , $invoiceId] = $this->storeBody($company, $r->document, $r->importItem, ['format' => $p['format'], 'supplier' => $p['supplier'], 'customer' => $p['customer']], $p['body'], $direction);

        return $invoiceId;
    }

    /** Risultato per corpo fattura (ticket 08). */
    public static function result(string $status, ?int $body = null, ?string $number = null, ?string $date = null, ?string $docType = null, ?int $invoice = null, ?int $existing = null, string $message = '', ?array $parties = null, array $issues = []): array
    {
        return array_filter(['status' => $status, 'body' => $body, 'number' => $number, 'date' => $date, 'doc_type' => $docType, 'invoice_id' => $invoice,
            'existing_id' => $existing, 'message' => $message, 'parties' => $parties, 'issues' => $issues ?: null], fn ($v) => $v !== null);
    }

    /** @return array{0: 'created'|'duplicate'|'conflict'|'archived', 1: list<string>, 2: ?int, 3: ?int} */
    private function storeBody(Company $company, Document $document, ImportItem $item, array $parsed, array $body, ?InvoiceDirection $direction, array $extra = [], string $mode = 'salta', int $uploadedBy = 0): array
    {
        // Ticket 08: candidati = stesso emittente, tipo, anno e numero; «già presente» solo a parità di contenuto sostanziale.
        $identity = InvoiceIdentity::key($parsed['supplier'], $body['doc_type'], $body['date'], $body['number']);
        $business = InvoiceIdentity::businessHash([...$body, 'supplier' => $parsed['supplier'], 'customer' => $parsed['customer']]);
        $same = $company->invoices()->where('identity_key', $identity)->orderBy('id')->get(['id', 'content_hash', 'business_hash']);
        // I documenti archiviati restano archiviati, anche se il contenuto cambia (si torna indietro solo con «Ripristina»).
        if ($arch = $company->invoices()->where('identity_key', $identity)->where('status', InvoiceStatus::Archiviata)->orderBy('id')->first()) {
            return ['archived', [], null, $arch->id];
        }
        if ($mode === 'sostituisci' && $same->isNotEmpty()) {
            // Sostituzione: eliminare le versioni non registrate dello stesso documento (quelle registrate restano e attivano il normale percorso di conflitto/duplicato)
            $svc = app(InvoiceService::class);
            $actor = User::find($uploadedBy);
            foreach ($same as $old) {
                try {
                    $svc->deleteUnregistered($company, Invoice::findOrFail($old->id), $actor);
                } catch (ValidationException) {
                    // registrato: non sostituibile
                }
            }
            $same = $company->invoices()->where('identity_key', $identity)->orderBy('id')->get(['id', 'content_hash', 'business_hash']);
        }
        if ($dup = $same->first(fn ($i) => $i->business_hash === $business || $i->content_hash === $body['content_hash'])) {
            return ['duplicate', [], null, $dup->id];
        }

        $issues = [...$this->issues($company, $body, $direction), ...$extra];
        if ($same->isNotEmpty()) {
            $issues[] = 'Esiste già un documento dello stesso emittente con lo stesso tipo, anno e numero ma con contenuto diverso: confronta i documenti e verifica quale è corretto.';
        }

        try {
            $invoiceId = DB::transaction(function () use ($company, $document, $item, $parsed, $body, $direction, $identity, $business, $issues, $same) {
                $invoice = Invoice::create([
                    'company_id' => $company->id, 'document_id' => $document->id, 'import_item_id' => $item->id,
                    'body_index' => $body['index'], 'format' => $parsed['format'], 'direction' => $direction,
                    'doc_type' => $body['doc_type'], 'number' => $body['number'], 'doc_date' => $body['date'],
                    'currency' => $body['currency'], 'total_amount' => $body['total_amount'],
                    'taxable_total' => $body['taxable_total'], 'tax_total' => $body['tax_total'],
                    'supplier_name' => $parsed['supplier']['name'], 'supplier_vat' => $parsed['supplier']['vat'], 'supplier_cf' => $parsed['supplier']['cf'],
                    'customer_name' => $parsed['customer']['name'], 'customer_vat' => $parsed['customer']['vat'], 'customer_cf' => $parsed['customer']['cf'],
                    'supplier_address' => $parsed['supplier']['address'] ?? null, 'customer_address' => $parsed['customer']['address'] ?? null,
                    'supplier_country' => $parsed['supplier']['country'] ?? ($parsed['supplier']['address']['nazione'] ?? null),
                    'customer_country' => $parsed['customer']['country'] ?? ($parsed['customer']['address']['nazione'] ?? null), 'xb_sdi' => true,
                    'identity_key' => $identity, 'content_hash' => $body['content_hash'], 'business_hash' => $business,
                    'status' => $issues ? InvoiceStatus::DaVerificare : InvoiceStatus::Importata,
                    'issues' => $issues ?: null, 'conflicts_with' => $same->first()?->id,
                ]);
                $invoice->vatSummaries()->createMany($body['summaries']);
                $invoice->lines()->createMany($body['lines']);
                // Scadenziario: rate da DatiPagamento (accordo, non prova di pagamento), ritenuta per l'importo da pagare.
                $invoice->update(['ritenuta' => $body['ritenuta'] ?? '0.00']);
                app(ScheduleService::class)->createFor($invoice, $body['payments'] ?? []);

                return $invoice->id;
            });
        } catch (UniqueConstraintViolationException) {
            // Importazione parallela dello stesso contenuto.
            return ['duplicate', [], null, $company->invoices()->where('identity_key', $identity)->value('id')];
        }

        return [$same->isNotEmpty() ? 'conflict' : 'created', $issues, $invoiceId, $same->first()?->id];
    }

    /**
     * Segnalazioni che richiedono una verifica. Non bloccano l'importazione, la fattura resta «da verificare».
     *
     * @return list<string>
     */
    private function issues(Company $company, array $body, ?InvoiceDirection $direction): array
    {
        $issues = [];
        if ($direction === null) {
            $issues[] = 'Cedente e cessionario coincidono con l’azienda (es. autofattura): la direzione va indicata in registrazione.';
        }
        if ($body['currency'] !== 'EUR') {
            $issues[] = "Valuta {$body['currency']}: gli importi non sono in euro.";
        }
        if ($body['total_amount'] !== null) {
            $sum = Decimal::toMinor($body['taxable_total']) + Decimal::toMinor($body['tax_total']) + ($body['rounding'] !== null ? Decimal::toMinor($body['rounding']) : 0);
            if ($sum !== Decimal::toMinor($body['total_amount'])) {
                $issues[] = 'L’importo totale del documento ('.$body['total_amount'].') non coincide con imponibile più imposta ('.Decimal::fromMinor($sum).').';
            }
        }
        if (FatturaPaSchema::label('TipoDocumentoType', $body['doc_type']) === null) {
            $issues[] = "Tipo documento {$body['doc_type']} sconosciuto.";
        }
        $year = (int) substr($body['date'], 0, 4);
        if (! $company->activeVatProfile($year)) {
            $issues[] = "Nessun regime IVA confermato per il {$year}.";
        }

        return $issues;
    }

    private function finish(ImportItem $item, ImportItemStatus $status, array $messages, ?Document $document, int $invoices = 0, array $results = []): void
    {
        $item->update(['status' => $status, 'messages' => array_values($messages), 'document_id' => $document?->id, 'invoices' => $invoices, 'results' => $results ?: null]);
    }

    private function looksLikeXml(string $bytes): bool
    {
        return str_starts_with(ltrim(str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes), '<');
    }

    private function date(string $iso): string
    {
        return implode('/', array_reverse(explode('-', $iso)));
    }
}
