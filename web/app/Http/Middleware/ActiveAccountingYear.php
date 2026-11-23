<?php

namespace App\Http\Middleware;

use App\Http\Controllers\InvoiceController;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

/**
 * Anno contabile centrale (indicazione del committente, 20.11.2026): l'anno scelto è nella sessione (per azienda). Le pagine senza indicazione propria
 * lo ricevono come `anno`, così tutti gli elenchi mostrano lo stesso anno invece di tutti gli anni. Anche un esplicito ?anno=AAAA sceglie l'anno
 * in modo centrale; ?anno=tutti resta una vista singola senza effetto sulla selezione.
 */
class ActiveAccountingYear
{
    public static function key(Company $company): string
    {
        return "active_year.{$company->id}";
    }

    public static function current(Request $request, Company $company): int
    {
        $y = (int) $request->session()->get(self::key($company), 0);

        // Solo anni selezionabili (un anno eliminato resta non selezionabile finché non viene ricreato)
        return $y >= 2000 && $y <= 2100 && in_array($y, InvoiceController::years($company), true) ? $y : InvoiceController::activeYear($company);
    }

    public function handle(Request $request, Closure $next)
    {
        $company = $request->route('company');
        if ($company instanceof Company && $request->isMethod('GET') && ! $request->expectsJson()) {
            $q = $request->query('anno');
            if ($q === null) {
                $request->query->set('anno', (string) self::current($request, $company));
            } elseif (preg_match('/^\d{4}$/', (string) $q)) {
                if ((int) $q >= 2000 && (int) $q <= 2100 && in_array((int) $q, InvoiceController::years($company), true)) {
                    $request->session()->put(self::key($company), (int) $q);
                } else {
                    // anno non selezionabile (es. eliminato): vale l'anno attivo
                    $request->query->set('anno', (string) self::current($request, $company));
                }
            }
        }

        return $next($request);
    }
}
