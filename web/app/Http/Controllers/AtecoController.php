<?php

namespace App\Http\Controllers;

use App\Support\Ateco;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ricerca nei codici ATECO 2025 per il modulo «Dati azienda». */
class AtecoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');

        return response()->json([
            'items' => mb_strlen(trim($q)) >= 2 ? Ateco::search(mb_substr($q, 0, 80)) : [],
            'source' => Ateco::SOURCE,
        ]);
    }
}
