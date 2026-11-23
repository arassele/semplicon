<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\CompanyOptions;
use App\Domain\Liquidazioni\SettlementCalculator;
use App\Enums\VatRegime;
use App\Http\Middleware\ActiveAccountingYear;
use App\Models\Allocation;
use App\Models\Company;
use App\Models\MoneyMovement;
use App\Support\Decimal;
use App\Support\VatPeriod;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** Stampa: Registro IVA, Liquidazione IVA e (solo professionisti) Registro incassi e pagamenti per l'anno contabile scelto. */
class PrintController extends Controller
{
    public function index(Request $request, Company $company): InertiaResponse
    {
        $year = ActiveAccountingYear::current($request, $company);
        $profile = $company->activeVatProfile($year);
        $per = $profile?->periodicity->value;
        $periods = $per ? array_map(fn ($p) => ['value' => $p, 'label' => VatPeriod::label($year, $p, $per)], range(1, VatPeriod::max($per))) : [];
        $confirmed = $per ? collect($periods)->pluck('value')->filter(fn ($p) => SettlementCalculator::latest($company, $year, $p) !== null)->values()->all() : [];

        return Inertia::render('Print/Index', [
            'year' => $year, 'periodicity' => $per, 'periods' => $periods, 'confirmed' => $confirmed, 'regime' => $profile?->regimeLabel(),
            'cashRegister' => $profile?->regime === VatRegime::Professionisti && CompanyOptions::enabled($company, 'scadenziario'),
        ]);
    }

    /** Registro incassi e pagamenti (contabilità professionisti): movimenti di pagamento dell'anno o del mese con documenti riconciliati. */
    public function cashRegister(Request $request, Company $company): Response
    {
        $year = ActiveAccountingYear::current($request, $company);
        abort_unless($company->activeVatProfile($year)?->regime === VatRegime::Professionisti, 404);
        $month = (int) $request->query('mese', 0);
        $month = $month >= 1 && $month <= 12 ? $month : null;
        $from = $month ? sprintf('%d-%02d-01', $year, $month) : "{$year}-01-01";
        $to = $month ? date('Y-m-t', strtotime($from)) : "{$year}-12-31";
        $mv = MoneyMovement::where('company_id', $company->id)->where('status', 'valida')->whereBetween('movement_date', [$from, $to])->orderBy('movement_date')->orderBy('id')->get();
        $alloc = Allocation::where('company_id', $company->id)->where('status', 'valida')->whereIn('movement_id', $mv->pluck('id'))->with('installment.invoice:id,number,doc_date,doc_type')->get()->groupBy('movement_id');
        $in = 0;
        $out = 0;
        $rows = $mv->map(function (MoneyMovement $m) use ($alloc, &$in, &$out) {
            $c = Decimal::toMinor((string) $m->amount);
            $m->kind === 'incasso' ? $in += $c : $out += $c;
            $docs = ($alloc[$m->id] ?? collect())->map(fn ($a) => $a->installment?->invoice)->filter()->unique('id')->map(fn ($i) => 'n. '.$i->number.' del '.$i->doc_date->format('d/m/Y'))->values()->all();

            return ['date' => $m->movement_date->toDateString(), 'fiscal_date' => ($m->kind === 'incasso' && $m->payer_date ? $m->payer_date : $m->movement_date)->toDateString(), 'kind' => $m->kind, 'party' => $m->party_name,
                'docs' => $docs, 'method' => $m->method, 'amount' => (string) $m->amount, 'sostituto' => (bool) $m->payer_date];
        })->all();
        $label = $month ? ucfirst(Carbon::create($year, $month, 1)->locale('it')->translatedFormat('F Y')) : "Anno {$year}";
        $html = view('pdf.cash_register', ['company' => $company, 'rows' => $rows, 'label' => $label, 'in' => Decimal::fromMinor($in), 'out' => Decimal::fromMinor($out), 'balance' => Decimal::fromMinor($in - $out), 'generatedAt' => now()])->render();
        Audit::record('register.exported', $request->user(), $company, data: ['register' => 'incassi_pagamenti', 'year' => $year, 'month' => $month, 'format' => 'pdf']);

        return $this->pdf($html, "registro-incassi-pagamenti-{$year}".($month ? sprintf('-%02d', $month) : '').'.pdf');
    }

    private function pdf(string $html, string $name): Response
    {
        $o = new Options;
        $o->setIsRemoteEnabled(false);
        $o->setDefaultFont('DejaVu Sans');
        $d = new Dompdf($o);
        $d->loadHtml($html, 'UTF-8');
        $d->setPaper('A4', 'landscape');
        $d->render();

        return response($d->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }
}
