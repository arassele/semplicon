<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\ManualInvoiceService;
use App\Domain\NonVatEntryService;
use App\Models\Account;
use App\Models\Company;
use App\Models\NonVatEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/** Movimenti fuori campo IVA. */
class NonVatEntryController extends Controller
{
    public function index(Request $request, Company $company, NonVatEntryService $service): Response
    {
        $years = $company->nonVatEntries()->distinct()->pluck('year')->when($company->currentYearSelectable(), fn ($c) => $c->push((int) now()->year))->unique()->sortDesc()->values();
        $year = in_array((int) $request->query('anno'), $years->all(), true) ? (int) $request->query('anno') : (int) now()->year;
        $kind = in_array($request->query('tipo'), ['costo', 'ricavo'], true) ? $request->query('tipo') : null;

        return Inertia::render('NonVat/Index', [
            'year' => $year, 'years' => $years, 'kind' => $kind,
            'data' => $service->build($company, $year, $kind),
            'accounts' => $company->accounts()->where('active', true)->get()->map(fn (Account $a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'iva_rilevante' => $a->iva_rilevante,
                'categoria' => $a->categoria, 'deducibilita_nota' => $a->deducibilita_nota,
            ]),
            'today' => now()->toDateString(),
            'accept' => '.'.implode(',.', ManualInvoiceService::ATTACHMENT_TYPES),
        ]);
    }

    public function store(Request $request, Company $company, NonVatEntryService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate([
            'kind' => ['required', 'in:costo,ricavo'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'account_id' => ['required', 'integer'],
            'description' => ['required', 'string', 'max:255'],
            'counterparty' => ['nullable', 'string', 'max:200'],
            'reference' => ['nullable', 'string', 'max:60'],
            'amount' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'],
            'file' => ['nullable', 'file', 'max:'.ManualInvoiceService::MAX_KB, 'mimes:'.implode(',', ManualInvoiceService::ATTACHMENT_TYPES)],
        ], ['amount.regex' => 'Importo non valido (es. 16,00).', 'file.mimes' => 'Allegato ammesso: PDF, JPG, PNG o XML.'],
            ['entry_date' => 'data', 'account_id' => 'conto', 'description' => 'descrizione', 'amount' => 'importo', 'file' => 'allegato']);
        $entry = $service->create($company, $request->user(), $data, $request->file('file'));

        return redirect("/aziende/{$company->id}/fuori-campo?anno={$entry->year}")->with('success', "Movimento {$entry->protocol}/{$entry->year} registrato.");
    }

    public function reverse(Request $request, Company $company, NonVatEntry $nonVatEntry, NonVatEntryService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'date' => ['required', 'date_format:Y-m-d']], [], ['reason' => 'motivo', 'date' => 'data']);
        $s = $service->reverse($company, $nonVatEntry, $request->user(), $data['reason'], $data['date']);

        return back()->with('success', "Storno registrato con protocollo {$s->protocol}/{$s->year}.");
    }

    /** @return Collection<int, NonVatEntry> */
    private function pick(Request $request, Company $company)
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);
        $rows = NonVatEntry::where('company_id', $company->id)->whereIn('id', array_unique($d['ids']))->with('account', 'document')->orderBy('entry_date')->orderBy('id')->get();
        abort_if($rows->isEmpty(), 404);

        return $rows;
    }

    /** Documenti delle registrazioni scelte come ZIP. */
    public function documents(Request $request, Company $company)
    {
        $rows = $this->pick($request, $company);
        $path = tempnam(sys_get_temp_dir(), 'semplicon-nv');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $n = 0;
        foreach ($rows as $e) {
            $d = $e->document;
            if ($d && Storage::disk('local')->exists($d->storage_path)) {
                $zip->addFromString("fuori-campo/{$e->protocol}-{$e->year}_".preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $d->original_name), Storage::disk('local')->get($d->storage_path));
                $n++;
            }
        }
        $zip->close();
        abort_if($n === 0, 404, 'Nessun allegato per la selezione.');
        Audit::record('documents.exported_bulk', $request->user(), $company, data: ['count' => $n, 'kind' => 'fuori_campo']);

        return response()->download($path, 'allegati-fuori-campo-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
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
        $out = [implode(';', array_map($cell, ['Protocollo', 'Data', 'Tipo', 'Descrizione', 'Conto', 'Importo', 'Stato', 'Allegato']))];
        foreach ($rows as $e) {
            $out[] = implode(';', array_map($cell, ["{$e->protocol}/{$e->year}", $e->entry_date->format('d/m/Y'), $e->kind === 'costo' ? 'Costo' : 'Ricavo', $e->description, $e->account ? "{$e->account->code} {$e->account->name}" : '',
                str_replace('.', ',', (string) ($e->amount * ($e->sign ?: 1))), $e->status, $e->document?->original_name]));
        }

        return response("\xEF\xBB\xBF".implode("\r\n", $out)."\r\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="fuori-campo-'.now()->format('Y-m-d').'.csv"']);
    }
}
