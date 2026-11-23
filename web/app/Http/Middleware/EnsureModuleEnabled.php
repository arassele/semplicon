<?php

namespace App\Http\Middleware;

use App\Domain\CompanyOptions;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

/** Blocca le pagine di un modulo disattivato (Funzioni e opzioni): reindirizzamento alla panoramica con avviso. */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module)
    {
        $company = $request->route('company');
        if ($company instanceof Company && ! CompanyOptions::enabled($company, $module)) {
            if ($request->expectsJson()) {
                abort(403, 'Funzione disattivata.');
            }

            return redirect("/aziende/{$company->id}")->with('success', 'Questa funzione è disattivata: puoi riattivarla in Impostazioni › Funzioni e opzioni.');
        }

        return $next($request);
    }
}
