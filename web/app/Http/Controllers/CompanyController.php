<?php

namespace App\Http\Controllers;

use App\Domain\CompanyService;
use App\Domain\DashboardService;
use App\Enums\CompanyType;
use App\Http\Middleware\ActiveAccountingYear;
use App\Models\Company;
use App\Support\Present;
use App\Support\Validation\CodiceFiscale;
use App\Support\Validation\PartitaIva;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function home(Request $request): RedirectResponse
    {
        $user = $request->user();
        $last = $request->session()->get('current_company_id');
        $company = $last ? $user->accessibleCompanies()->whereKey($last)->first() : null;
        $company ??= (! $user->is_admin && $user->companies()->count() === 1) ? $user->companies()->first() : null;

        return $company
            ? redirect()->route('companies.dashboard', $company)
            : redirect()->route('companies.index');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $companies = $user->accessibleCompanies()->with('owners')->get();

        return Inertia::render('Companies/Index', [
            'items' => $companies->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'partita_iva' => $c->partita_iva,
                'owners' => $c->owners->pluck('name')->join(', '),
                'profile' => Present::profileSummary($c->activeVatProfile((int) now()->year)),
            ]),
            'isAdmin' => $user->is_admin,
        ]);
    }

    public function store(Request $request, CompanyService $service): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'tipologia' => ['required', Rule::enum(CompanyType::class)],
            'partita_iva' => ['required', 'digits:11', 'unique:companies,partita_iva',
                fn ($a, $v, $fail) => PartitaIva::isValid($v) ? null : $fail('La partita IVA non è valida (cifra di controllo errata).')],
            'codice_fiscale' => ['nullable', 'string', 'regex:/^([A-Z0-9]{16}|[0-9]{11})$/i',
                fn ($a, $v, $fail) => CodiceFiscale::isValid((string) $v) ? null : $fail('Il codice fiscale non è valido (carattere di controllo errato).')],
        ], [], ['name' => 'denominazione', 'partita_iva' => 'partita IVA', 'codice_fiscale' => 'codice fiscale']);
        $data['codice_fiscale'] = isset($data['codice_fiscale']) ? strtoupper($data['codice_fiscale']) : null;

        $company = $service->create($request->user(), $data);

        return redirect()->route('company-profile.show', $company)
            ->with('success', 'Azienda creata. Completa i dati e poi imposta il regime IVA.');
    }

    /** Cambiare l'anno contabile centrale e tornare alla stessa pagina senza ?anno proprio. */
    public function setYear(Request $request, Company $company): RedirectResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer']])['year'];
        abort_unless(in_array($year, InvoiceController::years($company), true), 422);
        $request->session()->put(ActiveAccountingYear::key($company), $year);
        $prev = parse_url((string) url()->previous());
        $path = ($prev['path'] ?? "/aziende/{$company->id}");
        parse_str($prev['query'] ?? '', $q);
        unset($q['anno'], $q['page']);

        return redirect($path.($q ? '?'.http_build_query($q) : ''));
    }

    public function dashboard(Company $company, DashboardService $dashboard): Response
    {
        return Inertia::render('Companies/Dashboard', [
            'overview' => $dashboard->build($company, ActiveAccountingYear::current(request(), $company)),
            'currentYear' => ActiveAccountingYear::current(request(), $company),
            'years' => Present::years($company),
            'invoices' => [
                'acquisto' => $company->invoices()->where('direction', 'acquisto')->count(),
                'vendita' => $company->invoices()->where('direction', 'vendita')->count(),
                'da_verificare' => $company->invoices()->where('status', 'da_verificare')->count(),
            ],
        ]);
    }
}
