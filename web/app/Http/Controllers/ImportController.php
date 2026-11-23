<?php

namespace App\Http\Controllers;

use App\Domain\Import\ImportService;
use App\Enums\ImportStatus;
use App\Models\Company;
use App\Models\ImportBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    public function index(Company $company): Response
    {
        return Inertia::render('Import/Index', [
            'batches' => $company->importBatches()->with('creator:id,name')->latest('id')->limit(20)->get()->map(fn (ImportBatch $b) => self::batch($b)),
            'limits' => [
                'files' => config('semplicon.import.max_files_per_upload'),
                'file_mb' => intdiv(config('semplicon.import.max_file_kb'), 1024),
                'zip_mb' => intdiv(config('semplicon.import.max_zip_kb'), 1024),
            ],
        ]);
    }

    public function store(Request $request, Company $company, ImportService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $cfg = config('semplicon.import');
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.$cfg['max_files_per_upload']],
            'files.*' => ['required', 'file', 'max:'.$cfg['max_zip_kb'], function ($attribute, $file, $fail) use ($cfg) {
                $ext = strtolower($file->getClientOriginalExtension());
                if (! in_array($ext, ['xml', 'p7m', 'zip'], true)) {
                    $fail('Sono accettati solo file .xml, .p7m e .zip.');
                } elseif ($ext !== 'zip' && $file->getSize() > $cfg['max_file_kb'] * 1024) {
                    $fail('Il file supera '.intdiv($cfg['max_file_kb'], 1024).' MB.');
                }
            }],
            'mode' => ['nullable', 'in:salta,rielabora,sostituisci'],
        ], ['files.required' => 'Scegli almeno un file.'], ['files' => 'file', 'files.*' => 'file']);

        $batch = $service->start($company, $request->user(), $request->file('files'), (string) $request->input('mode', 'salta'));

        return redirect()->route('imports.show', [$company, $batch]);
    }

    public function show(Company $company, ImportBatch $importBatch): Response
    {
        $importBatch->load('creator:id,name');

        $items = $importBatch->items()->with('document:id,original_name')->get();
        $results = $items->flatMap(fn ($i) => $i->results ?? []);

        return Inertia::render('Import/Show', [
            'batch' => self::batch($importBatch),
            'summary' => self::summary($results->countBy('status')->all()),
            'items' => $items->map(fn ($i) => [
                'results' => $i->results ?? [],
                'id' => $i->id,
                'parent_id' => $i->parent_id,
                'filename' => $i->filename,
                'status' => $i->status->value,
                'status_label' => $i->status->label(),
                'messages' => $i->messages ?? [],
                'invoices' => $i->invoices,
                'document_id' => $i->document_id,
            ]),
        ]);
    }

    /**
     * Riepilogo per documento (ticket 08), es. «6 documenti già presenti. Nessuna nuova fattura da registrare.»
     *
     * @param  array<string, int>  $c
     */
    public static function summary(array $c): string
    {
        $n = fn (string $k) => (int) ($c[$k] ?? 0);
        $part = fn (int $x, string $one, string $many) => $x === 1 ? $one : "{$x} {$many}";
        $parts = array_filter([
            $n('importata') ? $part($n('importata'), '1 documento importato', 'documenti importati') : null,
            $n('gia_presente') ? $part($n('gia_presente'), '1 documento già presente', 'documenti già presenti') : null,
            $n('invariato') ? $part($n('invariato'), '1 documento invariato', 'documenti invariati') : null,
            $n('modificato') ? $part($n('modificato'), '1 documento modificato da confermare', 'documenti modificati da confermare') : null,
            $n('da_abbinare') ? $part($n('da_abbinare'), '1 corrispondenza da verificare', 'corrispondenze da verificare') : null,
            $n('archiviata') ? $part($n('archiviata'), '1 documento archiviato', 'documenti archiviati') : null,
            $n('integrazione_abbinata') ? $part($n('integrazione_abbinata'), '1 integrazione abbinata', 'integrazioni abbinate') : null,
            $n('conflitto') ? $part($n('conflitto'), '1 possibile conflitto', 'possibili conflitti') : null,
            $n('azienda_non_corrispondente') ? $part($n('azienda_non_corrispondente'), '1 documento di un’altra azienda', 'documenti di un’altra azienda') : null,
            $n('non_leggibile') ? $part($n('non_leggibile'), '1 file non leggibile', 'file non leggibili') : null,
        ]);
        if (! $parts) {
            return '';
        }
        $text = ucfirst(implode(', ', $parts)).'.';

        return $n('importata') + $n('conflitto') === 0 ? $text.' Nessuna nuova fattura da registrare.' : $text;
    }

    public static function batch(ImportBatch $b): array
    {
        return [
            'id' => $b->id,
            'status' => $b->status->value,
            'status_label' => $b->status->label(),
            'running' => $b->status !== ImportStatus::Completato,
            'files' => $b->files, 'invoices' => $b->invoices, 'duplicates' => $b->duplicates, 'to_review' => $b->to_review, 'errors' => $b->errors,
            'created_by' => $b->creator?->name, 'mode' => $b->mode,
            'created_at' => $b->created_at->toIso8601String(),
        ];
    }
}
