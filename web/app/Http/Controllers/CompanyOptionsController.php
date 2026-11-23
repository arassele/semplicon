<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\CompanyOptions;
use App\Http\Middleware\ActiveAccountingYear;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Impostazioni › Funzioni e opzioni. */
class CompanyOptionsController extends Controller
{
    public function show(Request $request, Company $company): Response
    {
        $year = ActiveAccountingYear::current($request, $company);
        $profile = $company->activeVatProfile($year);

        return Inertia::render('Companies/Options', [
            'options' => CompanyOptions::for($company), 'modules' => CompanyOptions::MODULES, 'year' => $year,
            'forced' => CompanyOptions::paymentDateSource($company, $year) === 'registrazione' && CompanyOptions::for($company)['payment_date_source'] !== 'registrazione',
            'regime' => $profile?->regimeLabel(),
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $d = $request->validate(['modules' => ['required', 'array'], 'modules.*' => ['boolean'], 'payment_date_source' => ['required', 'in:scadenziario,registrazione'], 'import_check_vat' => ['boolean']]);
        $new = CompanyOptions::normalize($d);
        $old = CompanyOptions::for($company);
        $company->update(['options' => $new]);
        Audit::record('company.options_updated', $request->user(), $company, data: ['old' => $old, 'new' => $new]);

        return back()->with('success', 'Funzioni e opzioni salvate.');
    }
}
