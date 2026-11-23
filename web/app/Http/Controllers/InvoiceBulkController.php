<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\InvoiceService;
use App\Models\Company;
use App\Models\Document;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/** Selezione multipla negli elenchi delle fatture: documenti come ZIP, elenco come CSV, eliminare le fatture non registrate. */
class InvoiceBulkController extends Controller
{
    private const MAX = 200;

    /** @return Collection<int, Invoice> */
    private function pick(Request $request, Company $company)
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:'.self::MAX], 'ids.*' => ['integer']]);
        $rows = Invoice::where('company_id', $company->id)->whereIn('id', array_unique($d['ids']))->with('document', 'registrations.document')->orderBy('doc_date')->orderBy('id')->get();
        abort_if($rows->isEmpty(), 404);

        return $rows;
    }

    public function documents(Request $request, Company $company)
    {
        $rows = $this->pick($request, $company);
        $path = tempnam(sys_get_temp_dir(), 'semplicon-docs');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $n = 0;
        $seen = [];
        $add = function (?Document $d, string $folder, string $prefix) use ($zip, &$n, &$seen) {
            if (! $d || isset($seen[$d->id]) || ! Storage::disk('local')->exists($d->storage_path)) {
                return;
            }
            $seen[$d->id] = true;
            $zip->addFromString($folder.'/'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $prefix).'_'.preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $d->original_name), Storage::disk('local')->get($d->storage_path));
            $n++;
        };
        foreach ($rows as $i) {
            $add($i->document, 'fatture', (string) $i->number);
            foreach ($i->registrations as $r) {
                $add($r->document, 'allegati', (string) $i->number);
            }
        }
        $zip->close();
        abort_if($n === 0, 404, 'Nessun documento disponibile per la selezione.');
        Audit::record('documents.exported_bulk', $request->user(), $company, data: ['count' => $n, 'invoices' => $rows->pluck('number')->all()]);

        return response()->download($path, 'documenti-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    public function csv(Request $request, Company $company)
    {
        $rows = $this->pick($request, $company);
        $cell = function ($c) {
            $c = (string) $c;
            if ($c !== '' && in_array($c[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! preg_match('/^-\d+(,\d+)?$/', $c)) {
                $c = "'".$c;
            }

            return preg_match('/[;"\r\n]/', $c) ? '"'.str_replace('"', '""', $c).'"' : $c;
        };
        $n = fn ($v) => str_replace('.', ',', (string) $v);
        $out = [implode(';', array_map($cell, ['Numero', 'Tipo', 'Data', 'Ricezione', 'Direzione', 'Controparte', 'Partita IVA / C.F.', 'Imponibile', 'IVA', 'Totale', 'Stato']))];
        foreach ($rows as $i) {
            $sale = $i->direction?->value === 'vendita';
            $out[] = implode(';', array_map($cell, [$i->number, $i->doc_type, $i->doc_date->format('d/m/Y'), $i->receipt_date?->format('d/m/Y'), $i->direction?->label() ?? 'Da definire', $sale ? $i->customer_name : $i->supplier_name,
                $sale ? ($i->customer_vat ?? $i->customer_cf) : ($i->supplier_vat ?? $i->supplier_cf), $n($i->taxable_total), $n($i->tax_total), $n($i->total_amount), $i->status->label()]));
        }
        Audit::record('invoices.exported_csv', $request->user(), $company, data: ['count' => $rows->count()]);

        return response("\xEF\xBB\xBF".implode("\r\n", $out)."\r\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="elenco-fatture-'.now()->format('Y-m-d').'.csv"']);
    }

    public function destroy(Request $request, Company $company, InvoiceService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:'.self::MAX], 'ids.*' => ['integer']]);
        $r = $service->deleteMany($company, array_unique($d['ids']), $request->user());
        $msg = $r['deleted'] ? ($r['deleted'] === 1 ? '1 fattura eliminata.' : "{$r['deleted']} fatture eliminate.") : 'Nessuna fattura eliminata.';
        if ($r['blocked']) {
            $msg .= ' Non eliminate: '.implode(' ', array_slice($r['blocked'], 0, 3)).(count($r['blocked']) > 3 ? ' …' : '');
        }

        return back()->with('success', $msg);
    }
}
