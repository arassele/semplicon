<?php

namespace App\Domain\Issuing;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Models\XmlExport;
use Illuminate\Support\Collection;
use ZipArchive;

/**
 * Panoramica delle fatture XML emesse, esportazione collettiva e stato di trasmissione. Semplicon non ha un'interfaccia verso il SdI: i file
 * vengono trasmessi con un programma esterno (o il portale); qui l'utente annota quando è avvenuto.
 */
final class TransmissionService
{
    public const MAX_BATCH = 200;

    /** @param  list<int>  $ids */
    public function invoices(Company $company, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids || count($ids) > self::MAX_BATCH) {
            RuleViolation::fail(['ids' => 'Scegli da 1 a '.self::MAX_BATCH.' fatture.']);
        }
        $rows = Invoice::where('company_id', $company->id)->where('source', 'emessa')->whereIn('id', $ids)->orderBy('doc_date')->orderBy('id')->get();
        if ($rows->count() !== count($ids)) {
            RuleViolation::fail(['ids' => 'Una o più fatture non sono fatture emesse con Semplicon.']);
        }

        return $rows;
    }

    /** @param  list<int>  $ids  @return array{path: string, filename: string, files: int} */
    public function zip(Company $company, User $actor, array $ids, bool $withPdf): array
    {
        $rows = $this->invoices($company, $ids);
        $view = app(InvoiceXmlView::class);
        $path = tempnam(sys_get_temp_dir(), 'semplicon-fe');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $n = 0;
        $sums = '';
        foreach ($rows as $i) {
            $x = XmlExport::where('company_id', $company->id)->where('invoice_id', $i->id)->orderByDesc('version')->first();
            if (! $x) {
                continue;
            }
            $zip->addFromString('XML/'.basename($x->filename), $x->content);
            $sums .= hash('sha256', $x->content).'  XML/'.basename($x->filename)."\n";
            $n++;
            if ($withPdf) {
                $doc = $view->fromXml($x->content);
                $pdf = $view->pdf($view->html($doc, 0, false, $view->logoFor($company, $doc)));
                $name = 'PDF/fattura-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $i->number).'.pdf';
                $zip->addFromString($name, $pdf);
                $sums .= hash('sha256', $pdf)."  {$name}\n";
            }
            $x->update(['downloads' => ($x->downloads ?? 0) + 1, 'downloaded_at' => now()]);
        }
        $zip->addFromString('SHA256SUMS', $sums);
        $zip->close();
        Audit::record('xml.exported_bulk', $actor, $company, data: ['count' => $n, 'pdf' => $withPdf, 'invoices' => $rows->pluck('number')->all()]);

        return ['path' => $path, 'filename' => 'fatture-elettroniche-'.now()->format('Y-m-d').'.zip', 'files' => $n];
    }

    /** @param  list<int>  $ids */
    public function mark(Company $company, User $actor, array $ids, string $date, ?string $note): int
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || $date > now()->toDateString()) {
            RuleViolation::fail(['date' => 'Indica la data di trasmissione (non nel futuro).']);
        }
        $rows = $this->invoices($company, $ids);
        foreach ($rows as $i) {
            if ($date < $i->doc_date->toDateString()) {
                RuleViolation::fail(['date' => "La data di trasmissione non può precedere la data della fattura {$i->number}."]);
            }
        }
        foreach ($rows as $i) {
            $i->update(['transmitted_at' => $date.' 12:00:00', 'transmitted_by' => $actor->id, 'transmission_note' => $note ? mb_substr(trim($note), 0, 255) : null]);
            Audit::record('invoice.transmitted', $actor, $company, $i, data: ['number' => $i->number, 'date' => $date, 'note' => $note]);
        }

        return $rows->count();
    }

    /** @param  list<int>  $ids */
    public function unmark(Company $company, User $actor, array $ids): int
    {
        $rows = $this->invoices($company, $ids);
        foreach ($rows as $i) {
            $i->update(['transmitted_at' => null, 'transmitted_by' => null, 'transmission_note' => null]);
            Audit::record('invoice.transmission_reset', $actor, $company, $i, data: ['number' => $i->number]);
        }

        return $rows->count();
    }
}
