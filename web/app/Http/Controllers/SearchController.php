<?php

namespace App\Http\Controllers;

use App\Domain\QuickSearch;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ricerca rapida dell'azienda (JSON per la palette Ctrl+K). */
class SearchController extends Controller
{
    public function __invoke(Request $request, Company $company, QuickSearch $search): JsonResponse
    {
        return response()->json(['results' => $search->search($company, (string) $request->query('q', ''))]);
    }
}
