<?php

namespace App\Http\Controllers;

use App\Domain\Issuing\InvoiceIssuer;
use App\Domain\PartnerService;
use App\Models\Company;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Anagrafiche: dati anagrafici di clienti e fornitori. */
class PartnerController extends Controller
{
    public function __construct(private PartnerService $svc) {}

    public function index(Request $request, Company $company, string $kind): Response
    {
        abort_unless(in_array($kind, ['clienti', 'fornitori'], true), 404);
        $flag = $kind === 'clienti' ? 'is_customer' : 'is_supplier';
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($q)).'%';
        $rows = $company->partners()->where($flag, true)->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(first_name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])->orWhereRaw('LOWER(vat) LIKE ?', [$like])->orWhereRaw('LOWER(cf) LIKE ?', [$like])->orWhereRaw('LOWER(city) LIKE ?', [$like])))
            ->orderByRaw('LOWER(COALESCE(name, last_name))')->get();

        return Inertia::render('Partners/Index', [
            'kind' => $kind, 'q' => $q, 'paymentModes' => InvoiceIssuer::PAYMENT_MODES,
            'partners' => $rows->map(fn (Partner $p) => [...$p->only(['id', 'is_customer', 'is_supplier', 'kind', 'name', 'first_name', 'last_name', 'vat', 'cf', 'country', 'address', 'zip', 'city', 'province', 'sdi_code', 'pec', 'is_pa', 'email', 'phone', 'iban',
                'payment_mode', 'payment_days', 'notes', 'active']), 'display' => $p->displayName()])->values(),
            'counts' => ['clienti' => $company->partners()->where('is_customer', true)->count(), 'fornitori' => $company->partners()->where('is_supplier', true)->count()],
        ]);
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $p = $this->svc->save($company, $request->user(), null, $this->data($request));

        return back()->with('success', 'Anagrafica «'.$p->displayName().'» salvata.');
    }

    public function update(Request $request, Company $company, Partner $partner): RedirectResponse
    {
        Gate::authorize('manage', $company);
        abort_unless($partner->company_id === $company->id, 404);
        $this->svc->save($company, $request->user(), $partner, $this->data($request));

        return back()->with('success', 'Anagrafica aggiornata.');
    }

    public function destroy(Request $request, Company $company, Partner $partner): RedirectResponse
    {
        Gate::authorize('manage', $company);
        abort_unless($partner->company_id === $company->id, 404);
        $this->svc->delete($company, $request->user(), $partner);

        return back()->with('success', 'Anagrafica eliminata. Le fatture già emesse o registrate non cambiano.');
    }

    public function import(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $r = $this->svc->importFromInvoices($company, $request->user());

        return back()->with('success', "Dalle fatture: {$r['created']} nuove anagrafiche, {$r['updated']} completate.");
    }

    private function data(Request $request): array
    {
        return $request->validate(['is_customer' => ['boolean'], 'is_supplier' => ['boolean'], 'kind' => ['nullable', 'in:azienda,persona'], 'name' => ['nullable', 'string', 'max:120'], 'first_name' => ['nullable', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'], 'vat' => ['nullable', 'string', 'max:30'], 'cf' => ['nullable', 'string', 'max:16'], 'country' => ['nullable', 'string', 'max:2'], 'address' => ['nullable', 'string', 'max:80'],
            'zip' => ['nullable', 'string', 'max:10'], 'city' => ['nullable', 'string', 'max:60'], 'province' => ['nullable', 'string', 'max:2'], 'sdi_code' => ['nullable', 'string', 'max:7'], 'pec' => ['nullable', 'string', 'max:255'],
            'is_pa' => ['boolean'], 'email' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'iban' => ['nullable', 'string', 'max:40'], 'payment_mode' => ['nullable', 'string', 'max:4'],
            'payment_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'notes' => ['nullable', 'string', 'max:2000'], 'active' => ['boolean']]);
    }
}
