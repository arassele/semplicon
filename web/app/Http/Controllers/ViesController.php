<?php

namespace App\Http\Controllers;

use App\Domain\ViesService;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Verifica VIES di una partita IVA UE (JSON, dal modulo di emissione). */
class ViesController extends Controller
{
    public function check(Request $request, Company $company, ViesService $vies): JsonResponse
    {
        $d = $request->validate(['country' => ['required', 'string', 'max:2'], 'vat' => ['required', 'string', 'max:30']]);

        return response()->json($vies->check($company, $request->user(), $d['country'], $d['vat']));
    }

    /** Ultimo risultato (max. 30 giorni) senza nuova interrogazione. */
    public function last(Request $request, Company $company, ViesService $vies): JsonResponse
    {
        $d = $request->validate(['country' => ['required', 'string', 'max:2'], 'vat' => ['required', 'string', 'max:30']]);
        $r = $vies->lastResult($company, $d['country'], $d['vat']);

        return response()->json($r ? ['status' => $r->status, 'name' => $r->name, 'address' => $r->address, 'request_identifier' => $r->request_identifier, 'checked_at' => $r->checked_at->toIso8601String()] : null);
    }
}
