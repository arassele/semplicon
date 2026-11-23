<?php

namespace App\Domain\Archive;

use App\Domain\AuditChain;
use App\Domain\VatRegisterService;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\VatSettlement;
use App\Models\XmlExport;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Archivio annuale per commercialista, verifica o archiviazione propria (elenco requisiti 1.3): uno ZIP con registri, liquidazioni,
 * file XML emessi, documenti originali e registro delle attività dell'anno. Ogni file compare con SHA-256 in SHA256SUMS
 * (formato di `sha256sum -c`); l'hash di SHA256SUMS identifica l'archivio e viene registrato nel registro delle attività.
 */
final class YearArchive
{
    /** @return array{path: string, filename: string, sums_sha256: string, files: int, chain: array<string, mixed>} */
    public function build(Company $company, int $year): array
    {
        $files = [];          // percorso nello ZIP => contenuto
        $disk = [];           // percorso nello ZIP => file su disco (originali, non caricati in memoria)
        $reg = app(VatRegisterService::class);
        foreach (['vendite', 'acquisti'] as $r) {
            $files["registri/registro-{$r}-{$year}.csv"] = $reg->csv($company, $r, $year, null);
        }
        $files["liquidazioni/liquidazioni-{$year}.csv"] = $this->settlementsCsv($company, $year);

        $count = ['xml' => 0, 'documenti' => 0];
        $from = "{$year}-01-01";
        $to = "{$year}-12-31";
        $invoices = Invoice::where('company_id', $company->id)->whereBetween('doc_date', [$from, $to])->get(['id', 'document_id']);
        foreach (XmlExport::where('company_id', $company->id)->whereIn('invoice_id', $invoices->pluck('id'))->orderBy('id')->get() as $x) {
            $files['fatture-emesse/'.basename($x->filename)] ??= $x->content;
            $count['xml']++;
        }
        $docIds = $invoices->pluck('document_id')->filter()->unique();
        foreach (Document::where('company_id', $company->id)->whereIn('id', $docIds)->orderBy('id')->get() as $doc) {
            if (! Storage::disk('local')->exists($doc->storage_path)) {
                continue;
            }
            $disk['documenti/'.$doc->id.'_'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $doc->original_name)] = Storage::disk('local')->path($doc->storage_path);
            $count['documenti']++;
        }

        $chain = AuditChain::verify($company);
        $files["registro-attivita/registro-attivita-{$year}.csv"] = $this->auditCsv($company, $year);
        $files['registro-attivita/verifica-catena.txt'] = sprintf("Verifica della catena di hash del registro attività al %s\r\nEsito: %s\r\nEventi verificati: %d\r\nEventi precedenti alla catena (non verificabili): %d\r\nUltimo hash: %s\r\n%s",
            now()->format('d/m/Y H:i'), $chain['ok'] ? 'OK' : 'ERRORE', $chain['checked'], $chain['legacy'], $chain['head'] ?? '—', $chain['ok'] ? '' : "Evento {$chain['broken_id']}: {$chain['reason']}\r\n");
        $files['LEGGIMI.txt'] = $this->readme($company, $year);

        // Checksum: prima tutti i file, poi il manifest, infine SHA256SUMS stesso
        $sums = [];
        foreach ($files as $p => $c) {
            $sums[$p] = hash('sha256', $c);
        }
        foreach ($disk as $p => $f) {
            $sums[$p] = hash_file('sha256', $f);
        }
        $manifest = json_encode([
            'azienda' => ['id' => $company->id, 'denominazione' => $company->name, 'partita_iva' => $company->partita_iva], 'anno' => $year, 'creato' => now()->toIso8601String(),
            'contenuto' => ['registri' => 2, 'liquidazioni' => VatSettlement::where('company_id', $company->id)->where('year', $year)->where('status', 'confermata')->count(), 'fatture_xml' => $count['xml'], 'documenti' => $count['documenti']],
            'catena_attivita' => ['esito' => $chain['ok'] ? 'ok' : 'errore', 'eventi' => $chain['checked'], 'ultimo_hash' => $chain['head']], 'sha256' => $sums,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $files['manifest.json'] = $manifest;
        $sums['manifest.json'] = hash('sha256', $manifest);
        ksort($sums);
        $sumsFile = '';
        foreach ($sums as $p => $h) {
            $sumsFile .= "{$h}  {$p}\n";
        }
        $files['SHA256SUMS'] = $sumsFile;

        $path = tempnam(sys_get_temp_dir(), 'semplicon-archive');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Archivio non creabile.');
        }
        foreach ($files as $p => $c) {
            $zip->addFromString($p, $c);
        }
        foreach ($disk as $p => $f) {
            $zip->addFile($f, $p);
        }
        $zip->close();

        return ['path' => $path, 'filename' => "archivio-{$year}-{$this->slug($company)}.zip", 'sums_sha256' => hash('sha256', $sumsFile), 'files' => count($sums) + 1, 'chain' => $chain];
    }

    private function slug(Company $c): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($c->name)), '-') ?: 'azienda';
    }

    private function settlementsCsv(Company $company, int $year): string
    {
        $rows = [self::row(['Anno', 'Periodo', 'Versione', 'Stato', 'Esito', 'Importo da versare', 'Credito', 'Rinviato', 'Scadenza', 'Confermata il'])];
        foreach (VatSettlement::where('company_id', $company->id)->where('year', $year)->orderBy('period')->orderBy('version')->get() as $s) {
            $rows[] = self::row([$s->year, $s->period, $s->version, $s->status, $s->result, self::n((string) $s->amount_due), self::n((string) $s->credit), self::n((string) $s->deferred),
                $s->due_date?->format('d/m/Y'), $s->confirmed_at?->format('d/m/Y H:i')]);
        }

        return "\xEF\xBB\xBF".implode("\r\n", $rows)."\r\n";
    }

    private function auditCsv(Company $company, int $year): string
    {
        $rows = [self::row(['ID', 'Data e ora', 'Utente', 'Azione', 'Oggetto', 'ID oggetto', 'Motivo', 'Dati', 'Hash precedente', 'Hash'])];
        $q = AuditEvent::where('company_id', $company->id)->whereBetween('created_at', ["{$year}-01-01 00:00:00", "{$year}-12-31 23:59:59"])->orderBy('id');
        foreach ($q->cursor() as $e) {
            $rows[] = self::row([$e->id, $e->created_at?->format('d/m/Y H:i:s'), $e->user_id, $e->action, $e->subject_type, $e->subject_id, $e->reason,
                $e->data ? AuditChain::canonical($e->data) : '', $e->prev_hash, $e->hash]);
        }

        return "\xEF\xBB\xBF".implode("\r\n", $rows)."\r\n";
    }

    private function readme(Company $c, int $year): string
    {
        return "Archivio annuale {$year} · {$c->name} (P. IVA {$c->partita_iva})\r\nCreato il ".now()->format('d/m/Y H:i')." con Semplicon.\r\n\r\n"
            ."Contenuto\r\n- registri/: registro vendite e registro acquisti dell'anno (CSV)\r\n- liquidazioni/: liquidazioni IVA dell'anno (CSV)\r\n- fatture-emesse/: file FatturaPA emessi con Semplicon\r\n"
            ."- documenti/: file originali delle fatture dell'anno\r\n- registro-attivita/: eventi dell'anno con catena di hash e esito della verifica\r\n\r\n"
            ."Verifica dell'integrità\r\nOgni file è elencato in SHA256SUMS con la sua impronta SHA-256. Dopo aver estratto l'archivio:\r\n    sha256sum -c SHA256SUMS\r\n"
            ."L'impronta del file SHA256SUMS è registrata in Semplicon (registro attività, evento archive.exported): conservala per provare che il contenuto non è cambiato.\r\n";
    }

    private static function row(array $cells): string
    {
        return implode(';', array_map(function ($c) {
            $c = (string) $c;
            if ($c !== '' && in_array($c[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! preg_match('/^-\d+(,\d+)?$/', $c)) {
                $c = "'".$c;
            }

            return preg_match('/[;"\r\n]/', $c) ? '"'.str_replace('"', '""', $c).'"' : $c;
        }, $cells));
    }

    private static function n(string $v): string
    {
        return str_replace('.', ',', $v);
    }
}
