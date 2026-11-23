<?php

namespace App\Http\Controllers;

use App\Domain\Archive\YearArchive;
use App\Domain\Audit;
use App\Domain\AuditChain;
use App\Models\AuditEvent;
use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Archivio annuale: ZIP con registri, liquidazioni, XML, originali e registro attività, con impronte SHA-256. */
class ArchiveController extends Controller
{
    public function index(Company $company): Response
    {
        $years = collect($company->vatRegistrations()->distinct()->pluck('year'))->merge($company->invoices()->pluck('doc_date')->map(fn ($d) => (int) substr((string) $d, 0, 4)))
            ->when($company->currentYearSelectable(), fn ($c) => $c->push((int) now()->year))->unique()->sortDesc()->values();
        $chain = AuditChain::verify($company);

        return Inertia::render('Archive/Index', [
            'years' => $years,
            'chain' => $chain,
            'previous' => AuditEvent::where('company_id', $company->id)->where('action', 'archive.exported')->latest('id')->limit(10)->get()
                ->map(fn ($e) => ['at' => $e->created_at?->toIso8601String(), 'year' => $e->data['year'] ?? null, 'sha256' => $e->data['sha256'] ?? null, 'files' => $e->data['files'] ?? null])->values(),
        ]);
    }

    public function download(Request $request, Company $company, int $year, YearArchive $archive)
    {
        abort_unless($year >= 2000 && $year <= (int) now()->year + 1, 404);
        $a = $archive->build($company, $year);
        Audit::record('archive.exported', $request->user(), $company, data: ['year' => $year, 'sha256' => $a['sums_sha256'], 'files' => $a['files'], 'chain' => $a['chain']['ok'] ? 'ok' : 'errore']);

        return response()->download($a['path'], $a['filename'], ['Content-Type' => 'application/zip', 'X-Archive-Sums-Sha256' => $a['sums_sha256']])->deleteFileAfterSend(true);
    }
}
