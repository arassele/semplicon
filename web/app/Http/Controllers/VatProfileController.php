<?php

namespace App\Http\Controllers;

use App\Domain\VatProfileService;
use App\Domain\YearService;
use App\Enums\Periodicity;
use App\Enums\ProfileStatus;
use App\Enums\SemplificataMethod;
use App\Enums\VatRegime;
use App\Models\Company;
use App\Models\VatProfile;
use App\Support\Present;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VatProfileController extends Controller
{
    public function __construct(private VatProfileService $service) {}

    public function show(Request $request, Company $company): Response
    {
        $years = Present::years($company);
        $current = (int) now()->year;
        $year = (int) $request->query('anno', 0)
            ?: (collect($years)->contains('year', $current) ? $current : ($years[0]['year'] ?? $current));

        $versions = $company->vatProfiles()->with('confirmer')->where('year', $year)->orderByDesc('version')->get();
        $draft = $versions->first(fn ($p) => $p->status === ProfileStatus::Bozza);
        $active = $versions->first(fn ($p) => $p->status === ProfileStatus::Confermato);

        return Inertia::render('Companies/VatProfile', [
            'years' => $years,
            'year' => $year,
            'currentYear' => $current,
            'active' => $active ? Present::profile($active) : null,
            'draft' => $draft ? Present::profile($draft) : null,
            'blockers' => $draft ? $this->service->blockers($company, $draft) : [],
            'history' => $versions->filter(fn ($p) => $p->status !== ProfileStatus::Bozza)->map(fn ($p) => Present::profile($p))->values(),
            'audit' => $company->auditEvents()->with('user')->latest('id')->limit(30)->get()->map(fn ($e) => Present::audit($e)),
            'options' => [
                'regimes' => Present::options(VatRegime::cases()),
                'methods' => Present::options(SemplificataMethod::cases()),
                'periodicity' => Present::options(Periodicity::cases()),
                'quarterly_types' => [['value' => 'opzione', 'label' => 'Trimestrale per opzione (interessi 1%)'], ['value' => 'speciale', 'label' => 'Trimestrale speciale (art. 74 c. 4, senza interessi)']],
            ],
            'supported' => config('semplicon.supported_operations'),
            'unsupported' => config('semplicon.unsupported'),
            'ruleVersion' => config('semplicon.rule_version'),
            'yearDelete' => ['summary' => app(YearService::class)->summary($company, $year), 'blocker' => app(YearService::class)->blocker($company, $year)],
        ]);
    }

    /** Eliminare l'anno contabile con tutti i dati; solo con la password dell'utente connesso. */
    public function destroyYear(Request $request, Company $company, int $year): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $d = $request->validate(['password' => ['required', 'string']]);
        $sum = app(YearService::class)->delete($company, $request->user(), $year, $d['password'], $request);

        return redirect("/aziende/{$company->id}")->with('success', "Anno {$year} eliminato: {$sum['invoices']} fatture, {$sum['registrations']} registrazioni e {$sum['settlements']} liquidazioni rimosse.");
    }

    public function storeDraft(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:'.VatProfileService::MIN_YEAR.','.VatProfileService::MAX_YEAR],
        ], [], ['year' => 'anno']);

        $draft = $this->service->createDraft($company, $request->user(), (int) $data['year']);

        return redirect()->route('vat-profile.show', [$company, 'anno' => $draft->year])->with('success', "Bozza {$draft->year} creata.");
    }

    public function updateDraft(Request $request, Company $company, VatProfile $vatProfile): RedirectResponse
    {
        Gate::authorize('manage', $company);

        $data = $request->validate([
            'regime' => ['nullable', Rule::enum(VatRegime::class)],
            'method' => ['nullable', Rule::enum(SemplificataMethod::class)],
            'forfettario_iva' => ['boolean'],
            'periodicity' => ['nullable', Rule::enum(Periodicity::class)],
            'quarterly_type' => ['nullable', 'in:opzione,speciale'],
            'pro_rata' => ['nullable', 'string', 'regex:/^\d{1,3}([.,]\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        if (isset($data['pro_rata'])) {
            $data['pro_rata'] = str_replace(',', '.', $data['pro_rata']);
            if (! VatProfileService::isValidProRata($data['pro_rata'])) {
                return back()->withErrors(['pro_rata' => 'L’aliquota pro rata deve essere tra 0 e 100%.'])->withInput();
            }
        } else {
            unset($data['pro_rata']);
        }

        $this->service->updateDraft($company, $vatProfile, $request->user(), $data);

        return back()->with('success', 'Bozza salvata.');
    }

    public function destroyDraft(Request $request, Company $company, VatProfile $vatProfile): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->service->deleteDraft($company, $vatProfile, $request->user());

        return back()->with('success', 'Bozza eliminata.');
    }

    public function confirm(Request $request, Company $company, VatProfile $vatProfile): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->service->confirm($company, $vatProfile, $request->user());

        return back()->with('success', "Regime {$vatProfile->year} confermato.");
    }
}
