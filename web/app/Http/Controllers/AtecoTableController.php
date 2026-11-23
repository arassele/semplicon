<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Models\AtecoCode;
use App\Models\Company;
use App\Support\Ateco;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Tabelle › Codici ATECO. Lettura per tutti, modifica solo per l'amministratore (vale per tutte le aziende). */
class AtecoTableController extends Controller
{
    public function index(Request $request, Company $company): Response
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $admin = $request->user()->is_admin;
        $query = AtecoCode::query()->when(! $admin, fn ($w) => $w->where('active', true));
        if ($q !== '') {
            $codes = array_column(Ateco::search($q, 500, ! $admin), 'code');
            $query->whereIn('code', $codes);
        }

        return Inertia::render('Tables/Ateco', [
            'q' => $q,
            'codes' => $query->orderBy('code')->paginate(50)->withQueryString()->through(fn ($r) => $r->only(['code', 'title_it', 'active', 'source'])),
            'canEdit' => $admin,
            'source' => Ateco::SOURCE,
            'total' => AtecoCode::where('active', true)->count(),
        ]);
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate([
            'code' => ['required', 'regex:/^\d{2}\.\d{2}\.\d{2}$/', 'unique:ateco_codes,code'],
            'title_it' => ['required', 'string', 'max:255'],
        ], ['code.regex' => 'Formato NN.NN.NN (es. 69.20.01).', 'code.unique' => 'Codice già presente.'], ['title_it' => 'titolo italiano']);
        $code = AtecoCode::create([...$data, 'active' => true, 'source' => 'manuale']);
        Audit::record('ateco.created', $request->user(), null, null, data: ['code' => $code->code]);

        return back()->with('success', "Codice {$code->code} aggiunto.");
    }

    public function update(Request $request, Company $company, string $code): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $row = AtecoCode::findOrFail($code);
        $data = $request->validate([
            'title_it' => ['required', 'string', 'max:255'],
            'active' => ['boolean'],
        ], [], ['title_it' => 'titolo italiano']);
        $row->update($data);
        Audit::record('ateco.updated', $request->user(), null, null, data: ['code' => $row->code, 'active' => $row->active]);

        return back()->with('success', "Codice {$row->code} salvato.");
    }
}
