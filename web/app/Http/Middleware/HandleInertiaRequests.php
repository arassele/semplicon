<?php

namespace App\Http\Middleware;

use App\Domain\CompanyOptions;
use App\Domain\DashboardService;
use App\Http\Controllers\InvoiceController;
use App\Models\Company;
use App\Support\Present;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $company = $request->route('company');
        $current = ($user && $company instanceof Company && $user->canAccess($company)) ? $company : null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'initials' => $user->initials(),
                    'is_admin' => $user->is_admin,
                ] : null,
            ],
            'companies' => fn () => $user
                ? $user->accessibleCompanies()->get(['companies.id', 'companies.name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()
                : [],
            'currentCompany' => $current ? [
                'id' => $current->id,
                'name' => $current->name,
                'partita_iva' => $current->partita_iva,
                // Amministratore in un'azienda altrui: rendere visibile che non ne è il titolare.
                'admin_view' => $user->is_admin && ! $current->owners()->whereKey($user->id)->exists(),
                // Ticket 01: avviso sul regime per l'anno visualizzato (?anno=…), altrimenti l'anno corrente.
                'regime' => Present::regimeBadge($current, self::displayYear($request, $current)),
                'modules' => CompanyOptions::for($current)['modules'],
                // Anno contabile centrale: anno attivo e anni selezionabili
                'fiscal_year' => ['active' => ActiveAccountingYear::current($request, $current), 'years' => InvoiceController::years($current)],
            ] : null,
            // Menu: lavoro aperto per voce (solo con azienda scelta)
            'navCounts' => fn () => $current ? app(DashboardService::class)->navCounts($current) : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'registered' => fn () => $request->session()->get('registered'), // ticket 07
            ],
        ];
    }

    /** Anno di visualizzazione della pagina: parametro «anno», se plausibile, altrimenti l'anno corrente. */
    public static function displayYear(Request $request, ?Company $company = null): int
    {
        $y = (int) $request->query('anno', 0);
        if (($y < 2000 || $y > 2100) && $company) {
            return ActiveAccountingYear::current($request, $company);
        }

        return $y >= 2000 && $y <= 2100 ? $y : (int) now()->year;
    }
}
