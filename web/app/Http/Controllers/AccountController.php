<?php

namespace App\Http\Controllers;

use App\Domain\AccountService;
use App\Domain\Audit;
use App\Http\Middleware\ActiveAccountingYear;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalParameter;
use App\Models\NonVatEntry;
use App\Models\VatRegistrationLine;
use App\Support\Cee;
use App\Support\Sezione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Tabelle › Piano dei conti (con impostazioni fiscali) e Parametri fiscali. */
class AccountController extends Controller
{
    public function index(Company $company, AccountService $service): Response
    {
        $service->ensureDefaults($company);
        $ids = $company->accounts()->pluck('id');
        $used = VatRegistrationLine::query()->whereIn('account_id', $ids)->distinct()->pluck('account_id')
            ->merge(NonVatEntry::query()->whereIn('account_id', $ids)->distinct()->pluck('account_id'))->unique()->values()->all();

        return Inertia::render('Tables/Accounts', [
            'accounts' => $company->accounts()->get()->map(fn (Account $a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'active' => $a->active, 'is_default' => $a->is_default,
                'used' => in_array($a->id, $used, true), 'source' => $a->source, ...$a->only(Account::FISCAL),
            ]),
            'cee' => Cee::LABELS,
            'sezioni' => Sezione::LABELS,
            'sezioniGroups' => Sezione::GROUPS,
            'currentModel' => AccountService::modelFor($company),
            'models' => collect(AccountService::MODELS)->except(AccountService::PATRIMONIALE)->map(fn ($m, $k) => ['key' => $k, 'label' => $m['label'], 'available' => $m['available'], 'size' => count(AccountService::model($k))])->values(),
        ]);
    }

    public function store(Request $request, Company $company, AccountService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $account = $service->create($company, $request->user(), $this->validated($request));

        return back()->with('success', "Conto {$account->code} aggiunto.");
    }

    public function update(Request $request, Company $company, Account $account, AccountService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $service->update($company, $account, $request->user(), $this->validated($request));

        return back()->with('success', "Conto {$account->code} salvato.");
    }

    public function destroy(Request $request, Company $company, Account $account, AccountService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $service->delete($company, $account, $request->user());

        return back()->with('success', 'Conto eliminato.');
    }

    public function makeDefault(Request $request, Company $company, Account $account, AccountService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $service->makeDefault($company, $account, $request->user());

        return back()->with('success', "Conto {$account->code} impostato come predefinito.");
    }

    public function applyModel(Request $request, Company $company, AccountService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $model = (string) $request->input('model', AccountService::DEFAULT_MODEL);
        abort_unless(isset(AccountService::MODELS[$model]), 422);
        $r = $service->applyModel($company, $request->user(), $request->boolean('remove_others'), $model);

        return back()->with('success', AccountService::MODELS[$model]['label']." applicato: {$r['added']} conti aggiunti".($r['removed'] ? ", {$r['removed']} eliminati" : '').'.');
    }

    /** Parametri fiscali dell'anno contabile scelto: righe di riferimento (senza azienda) + righe proprie dell'azienda. */
    public function parameters(Request $request, Company $company): Response
    {
        $year = $this->paramYear($request, $company);
        $years = FiscalParameter::where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->distinct()->orderByDesc('year')->pluck('year')->all();

        return Inertia::render('Tables/FiscalParameters', [
            'year' => $year, 'sourceYears' => array_values(array_filter($years, fn ($y) => (int) $y !== $year)),
            'parameters' => FiscalParameter::where('year', $year)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->orderByRaw('company_id IS NOT NULL')->orderBy('id')
                ->get(['id', 'company_id', 'year', 'ambito', 'deducibilita', 'iva', 'limiti', 'riferimento', 'fonte_url'])->map(fn ($p) => [...$p->only(['id', 'year', 'ambito', 'deducibilita', 'iva', 'limiti', 'riferimento', 'fonte_url']), 'own' => $p->company_id !== null])->values(),
        ]);
    }

    private function paramYear(Request $request, Company $company): int
    {
        $y = (int) $request->query('anno', 0);

        return $y >= 2000 && $y <= 2100 ? $y : ActiveAccountingYear::current($request, $company);
    }

    private function paramData(Request $request): array
    {
        return $request->validate(['ambito' => ['required', 'string', 'max:160'], 'deducibilita' => ['nullable', 'string', 'max:160'], 'iva' => ['nullable', 'string', 'max:160'], 'limiti' => ['nullable', 'string', 'max:255'],
            'riferimento' => ['nullable', 'string', 'max:255'], 'fonte_url' => ['nullable', 'url', 'max:500']]);
    }

    public function storeParameter(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $p = $company->fiscalParameters()->create([...$this->paramData($request), 'year' => $this->paramYear($request, $company)]);
        Audit::record('fiscal_parameter.created', $request->user(), $company, $p, data: ['year' => $p->year, 'ambito' => $p->ambito]);

        return back()->with('success', 'Parametro aggiunto.');
    }

    public function updateParameter(Request $request, Company $company, FiscalParameter $fiscalParameter): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $fiscalParameter->update($this->paramData($request));
        Audit::record('fiscal_parameter.updated', $request->user(), $company, $fiscalParameter, data: ['year' => $fiscalParameter->year, 'ambito' => $fiscalParameter->ambito]);

        return back()->with('success', 'Parametro aggiornato.');
    }

    public function destroyParameter(Request $request, Company $company, FiscalParameter $fiscalParameter): RedirectResponse
    {
        Gate::authorize('manage', $company);
        Audit::record('fiscal_parameter.deleted', $request->user(), $company, $fiscalParameter, data: ['year' => $fiscalParameter->year, 'ambito' => $fiscalParameter->ambito]);
        $fiscalParameter->delete();

        return back()->with('success', 'Parametro eliminato.');
    }

    /** Copiare i parametri di un altro anno (di riferimento e propri) come righe proprie modificabili nell'anno scelto. */
    public function copyParameters(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $from = (int) $request->validate(['from' => ['required', 'integer', 'min:2000', 'max:2100']])['from'];
        $to = $this->paramYear($request, $company);
        if ($from === $to) {
            return back()->withErrors(['from' => 'Scegli un altro anno di partenza.']);
        }
        $have = $company->fiscalParameters()->where('year', $to)->pluck('ambito')->map(fn ($a) => mb_strtolower($a))->all();
        $n = 0;
        foreach (FiscalParameter::where('year', $from)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->orderBy('id')->get() as $p) {
            if (in_array(mb_strtolower($p->ambito), $have, true)) {
                continue;
            }
            $company->fiscalParameters()->create([...$p->only(['ambito', 'deducibilita', 'iva', 'limiti', 'riferimento', 'fonte_url']), 'year' => $to]);
            $n++;
        }
        Audit::record('fiscal_parameter.copied', $request->user(), $company, data: ['from' => $from, 'to' => $to, 'count' => $n]);

        return back()->with('success', "{$n} parametri copiati dal {$from} al {$to}: ora puoi adattarli.");
    }

    private function validated(Request $request): array
    {
        $pct = ['nullable', 'regex:/^\d{1,3}([.,]\d{1,2})?$/', function ($attr, $value, $fail) {
            if ($value !== null && (float) str_replace(',', '.', (string) $value) > 100) {
                $fail('Percentuale tra 0 e 100.');
            }
        }];

        return $request->validate([
            'code' => ['required', 'string', 'max:12', 'regex:/^[0-9A-Za-z.\-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:costo,ricavo,patrimoniale'],
            'active' => ['boolean'],
            'sezione' => ['nullable', Rule::in(array_keys(Sezione::LABELS))],
            'voce_cee' => ['nullable', Rule::in(array_keys(Cee::LABELS))],
            'categoria' => ['nullable', 'string', 'max:60'],
            'iva_rilevante' => ['boolean'],
            'iva_detraibile_pct' => $pct,
            'iva_nota' => ['nullable', 'string', 'max:160'],
            'deducibilita_pct' => $pct,
            'deducibilita_nota' => ['nullable', 'string', 'max:160'],
            'irap_nota' => ['nullable', 'string', 'max:160'],
            'limite' => ['nullable', 'string', 'max:255'],
            'normativa' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'code.regex' => 'Usa solo lettere, cifre, punto e trattino.',
            'iva_detraibile_pct.regex' => 'Percentuale tra 0 e 100, al massimo due decimali.',
            'deducibilita_pct.regex' => 'Percentuale tra 0 e 100, al massimo due decimali.',
        ], ['code' => 'codice', 'name' => 'descrizione', 'voce_cee' => 'voce CEE']);
    }
}
