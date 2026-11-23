<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\VatRegisterService;
use App\Models\Company;
use App\Support\VatPeriod;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Registri IVA acquisti e vendite (I04). */
class VatRegisterController extends Controller
{
    public function __construct(private VatRegisterService $service) {}

    /** @return array{0: string, 1: ?int, 2: ?int, 3: array<int,string>, 4: ?string} */
    private function params(Request $request, Company $company, string $register): array
    {
        abort_unless(in_array($register, ['acquisti', 'vendite'], true), 404);
        $years = $company->vatProfiles()->where('status', 'confermato')->orderByDesc('year')->get(['year', 'periodicity'])
            ->mapWithKeys(fn ($p) => [$p->year => $p->periodicity->value])->all();
        foreach ($company->vatRegistrations()->distinct()->pluck('year') as $y) {
            $years[$y] ??= $company->vatRegistrations()->where('year', $y)->value('periodicity');
        }
        krsort($years);
        // Sezione 3: per impostazione l'anno contabile attivo, «Tutti» senza filtro per anno (periodo della registrazione a registro).
        $active = InvoiceController::activeYear($company);
        $year = $request->query('anno') === 'tutti' ? null : (int) $request->query('anno', isset($years[$active]) ? $active : (array_key_first($years) ?? now()->year));
        $periodicity = $year ? ($years[$year] ?? null) : null;
        $period = (int) $request->query('periodo', 0) ?: null;
        if ($period !== null && ($periodicity === null || $period < 1 || $period > VatPeriod::max($periodicity))) {
            $period = null;
        }

        return [$register, $year, $period, $years, $periodicity];
    }

    public function index(Request $request, Company $company, string $register): Response
    {
        [$register, $year, $period, $years, $periodicity] = $this->params($request, $company, $register);

        return Inertia::render('Registers/Index', [
            'register' => $register,
            'year' => $year,
            'period' => $period,
            'years' => $years,
            'periodicity' => $periodicity,
            'periods' => $periodicity && $year ? array_map(fn ($p) => ['value' => $p, 'label' => VatPeriod::label($year, $p, $periodicity)], range(1, VatPeriod::max($periodicity))) : [],
            'data' => $this->service->build($company, $register, $year, $period),
            // Ticket 07: voce evidenziata e ritorno all'elenco di controllo (solo percorsi di questa azienda).
            'highlight' => (int) $request->query('reg') ?: null,
            'returnUrl' => str_starts_with((string) $request->query('ritorno'), "/aziende/{$company->id}/") ? (string) $request->query('ritorno') : null,
        ]);
    }

    public function csv(Request $request, Company $company, string $register): HttpResponse
    {
        [$register, $year, $period] = $this->params($request, $company, $register);
        Audit::record('register.exported', $request->user(), $company, data: ['register' => $register, 'year' => $year, 'period' => $period, 'format' => 'csv']);
        $name = "registro-{$register}-".($year ?? 'tutti').($period ? "-p{$period}" : '').'.csv';

        return response($this->service->csv($company, $register, $year, $period), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    public function pdf(Request $request, Company $company, string $register): HttpResponse
    {
        [$register, $year, $period, , $periodicity] = $this->params($request, $company, $register);
        $data = $this->service->build($company, $register, $year, $period);
        $html = view('pdf.register', [
            'company' => $company, 'register' => $register, 'year' => $year,
            'periodLabel' => $year === null ? 'Tutti gli anni' : ($period ? VatPeriod::label($year, $period, $periodicity) : "Anno {$year}"),
            'data' => $data, 'generatedAt' => now(),
        ])->render();

        $options = new Options;
        $options->setIsRemoteEnabled(false);      // nessuna risorsa esterna
        $options->setDefaultFont('DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        Audit::record('register.exported', $request->user(), $company, data: ['register' => $register, 'year' => $year, 'period' => $period, 'format' => 'pdf']);
        $name = "registro-{$register}-".($year ?? 'tutti').($period ? "-p{$period}" : '').'.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
