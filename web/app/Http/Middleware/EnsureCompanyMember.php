<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Separazione dei mandanti: senza accesso (azienda propria o amministratore) ogni route
 * sotto /aziende/{company} risponde con 404, così le aziende altrui non rivelano nemmeno la propria esistenza.
 */
class EnsureCompanyMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->route('company');

        if (! $company instanceof Company || ! $request->user()?->canAccess($company)) {
            abort(404);
        }

        $request->session()->put('current_company_id', $company->id);

        return $next($request);
    }
}
