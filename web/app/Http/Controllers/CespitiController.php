<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\Cespiti\AssetImportService;
use App\Domain\Cespiti\AssetLedger;
use App\Domain\Cespiti\AssetMovementService;
use App\Domain\Cespiti\AssetReportService;
use App\Domain\Cespiti\AssetService;
use App\Domain\Cespiti\DepreciationService;
use App\Domain\Cespiti\FiscalYears;
use App\Http\Middleware\ActiveAccountingYear;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetDepreciationRun;
use App\Models\AssetImportJob;
use App\Models\AssetMovement;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\DepreciationRate;
use App\Models\DepreciationTable;
use App\Models\FiscalYearClosure;
use App\Models\JournalEntry;
use App\Support\Decimal;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Modulo Cespiti: Elenco, Anagrafica, Categorie, Calcolo, Movimenti, Importazione, Stampe, Prima nota, Impostazioni. */
class CespitiController extends Controller
{
    public function __construct(private AssetService $assets, private DepreciationService $dep, private AssetMovementService $moves) {}

    private function year(Request $r, Company $c): int
    {
        return ActiveAccountingYear::current($r, $c);
    }

    private static function e(int $c): string
    {
        return Decimal::fromMinor($c);
    }

    private function accounts(Company $c): array
    {
        return $c->accounts()->where('active', true)->whereIn('type', ['patrimoniale', 'costo', 'ricavo'])->orderBy('code')->get(['id', 'code', 'name', 'type', 'sezione'])->toArray();
    }

    private function categories(Company $c): array
    {
        return $c->assetCategories()->with('rate')->orderBy('code')->get()->map(fn (AssetCategory $k) => [...$k->only(['id', 'code', 'name', 'kind', 'civil_rate', 'fiscal_rate', 'first_year_half', 'active',
            'account_asset_id', 'account_fund_id', 'account_expense_id', 'account_gain_id', 'account_loss_id', 'account_writedown_id', 'depreciation_rate_id', 'notes']),
            'dm' => $k->rate ? ['gruppo' => $k->rate->gruppo, 'specie' => $k->rate->specie, 'coefficient' => (string) $k->rate->coefficient, 'description' => $k->rate->description] : null,
            'assets' => $k->assets()->count(), 'changes' => $k->rateChanges()->get(['from_year', 'civil_rate', 'fiscal_rate', 'reason'])])->all();
    }

    /** Riga dell'elenco con situazione a fine dell'anno scelto. */
    private function row(Asset $a, int $year): array
    {
        $s = AssetLedger::snapshot($a, $year);

        return [...$a->only(['id', 'number', 'code', 'description', 'status', 'kind', 'location', 'cost_center', 'excluded']), 'category' => $a->category->name, 'category_id' => $a->asset_category_id,
            'purchase_date' => $a->purchase_date->format('Y-m-d'), 'cost' => self::e(AssetLedger::costCivil($a)), 'fund' => self::e($s['exit'] ? 0 : $s['fund_end']), 'net' => self::e($s['exit'] ? 0 : $s['net']),
            'rate' => (string) ($a->category->civil_rate ?? $a->category->fiscal_rate), 'quota' => self::e($s['quota_civil'])];
    }

    public function index(Request $request, Company $company): Response
    {
        $year = $this->year($request, $company);
        $f = $request->only(['q', 'category', 'status', 'location', 'cost_center', 'purchase_year']);
        $list = $company->assets()->with(['category.rate', 'rates', 'opening', 'company'])->orderBy('number')->get();
        $rows = [];
        $k = ['count' => 0, 'cost' => 0, 'fund' => 0, 'net' => 0, 'quota' => 0, 'acq' => 0, 'alien' => 0, 'plus' => 0, 'minus' => 0];
        foreach ($list as $a) {
            if ($a->purchase_date->format('Y') > $year) {
                continue;
            }
            $s = AssetLedger::snapshot($a, $year);
            $gone = $s['exit'] === null && AssetMovement::where('asset_id', $a->id)->where('status', 'registrato')->where('year', '<', $year)->whereIn('type', ['alienazione_totale', 'eliminazione'])->exists();
            if (! $gone) {
                $k['count']++;
                $k['cost'] += $s['exit'] ? 0 : $s['cost_end'];
                $k['fund'] += $s['exit'] ? 0 : $s['fund_end'];
                $k['net'] += $s['exit'] ? 0 : $s['net'];
                $k['quota'] += $s['quota_civil'];
                $k['acq'] += $s['mov']['acquisizioni'];
                $k['alien'] += $s['mov']['alienazioni'] + $s['mov']['eliminazioni'];
            }
            $r = $this->row($a, $year);
            $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
            if (($q === '' || str_contains(mb_strtolower($a->code.' '.$a->description.' '.$a->serial_number.' '.$a->matricola), $q))
                && (empty($f['category']) || (int) $f['category'] === $a->asset_category_id) && (empty($f['status']) || $f['status'] === $a->status)
                && (empty($f['location']) || str_contains(mb_strtolower((string) $a->location), mb_strtolower($f['location'])))
                && (empty($f['cost_center']) || str_contains(mb_strtolower((string) $a->cost_center), mb_strtolower($f['cost_center'])))
                && (empty($f['purchase_year']) || (int) $f['purchase_year'] === (int) $a->purchase_date->format('Y'))) {
                $rows[] = $r;
            }
        }
        foreach (AssetMovement::where('company_id', $company->id)->where('year', $year)->where('status', 'registrato')->whereIn('type', ['alienazione_totale', 'alienazione_parziale', 'eliminazione'])->get() as $m) {
            $g = AssetLedger::m($m->gain_civil);
            $g >= 0 ? $k['plus'] += $g : $k['minus'] -= $g;
        }

        return Inertia::render('Cespiti/Index', ['year' => $year, 'filters' => $f, 'rows' => $rows, 'kpi' => ['count' => $k['count'], ...array_map(fn ($v) => self::e($v), array_diff_key($k, ['count' => 1]))],
            'categories' => $company->assetCategories()->orderBy('code')->get(['id', 'code', 'name']), 'statuses' => AssetService::STATUS]);
    }

    private function formProps(Company $c, ?Asset $a = null): array
    {
        return ['asset' => $a ? [...$a->toArray(), 'purchase_date' => $a->purchase_date?->format('Y-m-d'), 'invoice_date' => $a->invoice_date?->format('Y-m-d'), 'in_service_date' => $a->in_service_date?->format('Y-m-d'),
            'depreciation_start' => $a->depreciation_start?->format('Y-m-d'), 'locked' => $a->depreciations()->where('status', '!=', 'stornato')->exists() || $a->movements()->whereNotIn('type', ['acquisizione'])->where('status', 'registrato')->exists()] : null,
            'categories' => $this->categories($c), 'accounts' => $this->accounts($c), 'methods' => AssetService::METHODS, 'roles' => AssetService::ROLES,
            'invoices' => $c->invoices()->where('direction', 'acquisto')->orderByDesc('doc_date')->limit(300)->get(['id', 'number', 'doc_date', 'supplier_name', 'taxable_total'])->map(fn ($i) => ['id' => $i->id, 'label' => $i->number.' · '.$i->doc_date->format('d/m/Y').' · '.$i->supplier_name.' · '.$i->taxable_total]),
            'settings' => AssetService::settings($c), 'opening' => $a?->opening ? $a->opening->only(['mode', 'last_year', 'fund_civil', 'fund_fiscal']) : null,
            'default_last_year' => (int) now()->year - 1];
    }

    public function create(Company $company): Response
    {
        return Inertia::render('Cespiti/Form', $this->formProps($company));
    }

    public function edit(Company $company, Asset $asset): Response
    {
        return Inertia::render('Cespiti/Form', $this->formProps($company, $asset));
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $a = $this->assets->save($company, $request->user(), $request->all());

        return redirect("/aziende/{$company->id}/cespiti/{$a->id}")->with('success', "Cespite {$a->code} creato.");
    }

    public function update(Request $request, Company $company, Asset $asset): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->assets->save($company, $request->user(), $request->all(), $asset);

        return redirect("/aziende/{$company->id}/cespiti/{$asset->id}")->with('success', 'Cespite aggiornato.');
    }

    public function duplicate(Request $request, Company $company, Asset $asset): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $a = $this->assets->duplicate($company, $request->user(), $asset);

        return redirect("/aziende/{$company->id}/cespiti/{$a->id}/modifica")->with('success', "Copia {$a->code} creata: controlla i dati.");
    }

    public function show(Request $request, Company $company, Asset $asset): Response
    {
        $year = $this->year($request, $company);
        $asset->load(['category.rate', 'rates', 'opening', 'company', 'invoice', 'documents.document']);
        $plan = array_map(fn ($r) => [...$r, ...array_map(fn ($v) => is_int($v) ? self::e($v) : $v, array_intersect_key($r, array_flip(['base_civil', 'base_fiscal', 'fund_civil_start', 'civil_amount', 'fund_civil_end',
            'fund_fiscal_start', 'fiscal_limit', 'fiscal_amount', 'fund_fiscal_end', 'nondeductible', 'extra_deduction']))), 'civil_rate' => self::e($r['civil_rate']), 'fiscal_rate' => self::e($r['fiscal_rate'])], AssetLedger::plan($asset));
        $journal = JournalEntry::where('company_id', $company->id)->whereIn('id', $asset->movements()->whereNotNull('journal_entry_id')->pluck('journal_entry_id'))->with('lines.account')->orderBy('entry_date')->get();

        return Inertia::render('Cespiti/Show', [
            'year' => $year, 'asset' => [...$asset->toArray(), 'purchase_date' => $asset->purchase_date->format('Y-m-d'), 'in_service_date' => $asset->in_service_date?->format('Y-m-d'),
                'invoice_date' => $asset->invoice_date?->format('Y-m-d'), 'depreciation_start' => $asset->depreciation_start?->format('Y-m-d'),
                'cost_civil' => self::e(AssetLedger::costCivil($asset)), 'cost_fiscal' => self::e(AssetLedger::costFiscal($asset)), 'start_year' => AssetLedger::startYear($asset)],
            'snapshot' => array_map(fn ($v) => is_int($v) ? self::e($v) : $v, array_diff_key(AssetLedger::snapshot($asset, $year), ['row' => 1, 'mov' => 1, 'exit' => 1])),
            'plan' => $plan, 'movements' => $asset->movements()->with('creator')->get()->map(fn (AssetMovement $m) => [...$m->toArray(), 'movement_date' => $m->movement_date->format('Y-m-d'), 'type_label' => AssetMovementService::TYPES[$m->type] ?? $m->type, 'user' => $m->creator?->name]),
            'journal' => $journal->map(fn ($e) => ['id' => $e->id, 'number' => "{$e->number}/{$e->year}", 'date' => $e->entry_date->format('Y-m-d'), 'description' => $e->description, 'status' => $e->status,
                'lines' => $e->lines->map(fn ($l) => ['account' => $l->account->code.' '.$l->account->name, 'dare' => (string) $l->dare, 'avere' => (string) $l->avere])]),
            'documents' => $asset->documents->map(fn ($d) => ['id' => $d->document_id, 'name' => $d->document?->original_name, 'label' => $d->label]),
            'audit' => AuditEvent::where('company_id', $company->id)->where('subject_type', 'Asset')->where('subject_id', $asset->id)->with('user')->latest('id')->limit(80)->get()
                ->map(fn ($e) => ['at' => $e->created_at->toIso8601String(), 'action' => $e->action, 'user' => $e->user?->name, 'reason' => $e->reason, 'data' => $e->data]),
            'types' => array_diff_key(AssetMovementService::TYPES, ['acquisizione' => 1, 'ammortamento' => 1]), 'categories' => $this->categories($company), 'accounts' => $this->accounts($company),
            'statuses' => AssetService::STATUS, 'methods' => AssetService::METHODS, 'roles' => AssetService::ROLES,
        ]);
    }

    public function exclude(Request $request, Company $company, Asset $asset): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->assets->exclude($company, $request->user(), $asset, (bool) $request->boolean('excluded'), $request->input('reason'));

        return back()->with('success', $request->boolean('excluded') ? 'Cespite escluso dal calcolo.' : 'Cespite di nuovo nel calcolo.');
    }

    public function rates(Request $request, Company $company, Asset $asset): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->assets->changeAssetRates($company, $request->user(), $asset, (int) $request->input('from_year'), $request->input('civil_rate'), $request->input('fiscal_rate'), (string) $request->input('reason'),
            $request->input('manual_civil'), $request->input('manual_fiscal'));

        return back()->with('success', 'Aliquote aggiornate per gli esercizi futuri.');
    }

    public function attach(Request $request, Company $company, Asset $asset): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,xml,jpg,jpeg,png']]);
        $this->assets->attach($company, $request->user(), $asset, $request->file('file'), $request->input('label'));

        return back()->with('success', 'Documento allegato.');
    }

    public function previewMovement(Request $request, Company $company, Asset $asset): JsonResponse
    {
        Gate::authorize('manage', $company);
        $b = $this->moves->preview($company, $asset, $request->all());
        $acc = $company->accounts()->get(['id', 'code', 'name'])->keyBy('id');

        return response()->json(['values' => array_map(fn ($v) => self::e($v), $b['values']), 'warnings' => $b['warnings'],
            'lines' => array_map(fn ($l) => ['account' => $l['account_id'] && isset($acc[$l['account_id']]) ? $acc[$l['account_id']]->code.' '.$acc[$l['account_id']]->name : 'Conto mancante',
                'dare' => self::e($l['dare'] ?? 0), 'avere' => self::e($l['avere'] ?? 0), 'description' => $l['description'] ?? ''], $b['lines'])]);
    }

    public function storeMovement(Request $request, Company $company, Asset $asset): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $m = $this->moves->register($company, $request->user(), $asset, $request->all());

        return back()->with('success', AssetMovementService::TYPES[$m->type].' registrata.');
    }

    public function reverseMovement(Request $request, Company $company, AssetMovement $assetMovement): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->moves->reverse($company, $request->user(), $assetMovement, (string) $request->input('reason'));

        return back()->with('success', 'Movimento stornato.');
    }

    public function movements(Request $request, Company $company): Response
    {
        $year = $this->year($request, $company);
        $type = $request->query('tipo');

        return Inertia::render('Cespiti/Movements', ['year' => $year, 'type' => $type, 'types' => AssetMovementService::TYPES,
            'manual_types' => array_diff_key(AssetMovementService::TYPES, ['acquisizione' => 1, 'ammortamento' => 1]), 'categories' => $company->assetCategories()->orderBy('code')->get(['id', 'code', 'name', 'kind']),
            'assets' => $company->assets()->whereNotIn('status', ['venduto', 'eliminato'])->orderBy('number')->get(['id', 'code', 'description', 'kind', 'asset_category_id']),
            'selected' => $request->integer('cespite') ?: null, 'new_type' => $request->query('nuovo'),
            'rows' => AssetMovement::where('company_id', $company->id)->where('year', $year)->when($type, fn ($q) => $q->where('type', $type))->with(['asset', 'creator', 'journal'])->orderBy('movement_date')->orderBy('id')->get()
                ->map(fn ($m) => ['id' => $m->id, 'date' => $m->movement_date->format('Y-m-d'), 'type' => $m->type, 'type_label' => AssetMovementService::TYPES[$m->type] ?? $m->type, 'asset_id' => $m->asset_id,
                    'asset' => $m->asset->code.' · '.$m->asset->description, 'amount' => $m->amount !== null ? (string) $m->amount : null, 'gain' => $m->gain_civil !== null ? (string) $m->gain_civil : null,
                    'causale' => $m->causale, 'status' => $m->status, 'journal' => $m->journal ? "{$m->journal->number}/{$m->journal->year}" : null, 'user' => $m->creator?->name])]);
    }

    public function categoriesPage(Request $request, Company $company): Response
    {
        $t = DepreciationTable::where('code', 'DM-1988')->first();
        $g = (int) $request->query('gruppo', 0);
        $sp = $request->query('specie');

        return Inertia::render('Cespiti/Categories', ['categories' => $this->categories($company), 'accounts' => $this->accounts($company), 'roles' => AssetService::ROLES, 'kinds' => AssetService::KINDS,
            'table' => $t ? ['title' => $t->title, 'source' => $t->source, 'version' => $t->version, 'notes' => $t->notes] : null,
            'groups' => $t ? DepreciationRate::where('depreciation_table_id', $t->id)->selectRaw('gruppo, gruppo_desc')->groupBy('gruppo', 'gruppo_desc')->orderBy('gruppo')->get() : [],
            'species' => $t && $g ? DepreciationRate::where('depreciation_table_id', $t->id)->where('gruppo', $g)->selectRaw('specie, specie_desc')->groupBy('specie', 'specie_desc')->get()->unique('specie')->values() : [],
            'rates' => $t && $g ? DepreciationRate::where('depreciation_table_id', $t->id)->where('gruppo', $g)->when($sp !== null && $sp !== '', fn ($q) => $q->where('specie', $sp), fn ($q) => $q->whereNull('specie'))
                ->orderBy('position')->get(['id', 'description', 'coefficient', 'verify_note', 'specie']) : [],
            'gruppo' => $g ?: null, 'specie' => $sp, 'year' => $this->year($request, $company)]);
    }

    public function storeCategory(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $c = $this->assets->saveCategory($company, $request->user(), $request->all());

        return back()->with('success', "Categoria {$c->code} salvata.");
    }

    public function updateCategory(Request $request, Company $company, AssetCategory $assetCategory): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->assets->saveCategory($company, $request->user(), $request->all(), $assetCategory);

        return back()->with('success', 'Categoria aggiornata.');
    }

    public function categoriesFromTable(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $n = $this->assets->categoriesFromTable($company, $request->user(), array_map('intval', (array) $request->input('rates', [])));

        return back()->with('success', "{$n} categorie create dalla tabella ministeriale.");
    }

    public function categoryRates(Request $request, Company $company, AssetCategory $assetCategory): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->assets->changeCategoryRates($company, $request->user(), $assetCategory, (int) $request->input('from_year'), (string) $request->input('civil_rate'), (string) $request->input('fiscal_rate'), (string) $request->input('reason'));

        return back()->with('success', 'Nuove aliquote dal '.(int) $request->input('from_year').'.');
    }

    /** Esercizio del calcolo: liberamente selezionabile tra il primo anno di ammortamento e l'anno centrale (recuperare gli anni precedenti). */
    private function depYear(Request $request, Company $company): int
    {
        $y = (int) $request->input('esercizio');

        return $y >= 2000 && $y <= (int) now()->year + 1 ? $y : $this->year($request, $company);
    }

    private function depYears(Company $company): array
    {
        $first = $company->assets()->get()->map(fn ($a) => AssetLedger::startYear($a))->min();

        return $first ? range(max(2000, $first), (int) now()->year) : [(int) now()->year];
    }

    /** Opzioni dell'esecuzione dalla richiesta (quota ridotta); per un'esecuzione definitiva quelle salvate. */
    private function depOptions(Request $request): array
    {
        return DepreciationService::options(['reduced_pct' => $request->input('ridotta'), 'categories' => (array) $request->input('ridotta_cat', [])]);
    }

    public function depreciation(Request $request, Company $company): Response
    {
        $year = $this->depYear($request, $company);
        $opts = $this->depOptions($request);
        $sim = $this->dep->simulate($company, $year, $opts);
        $stored = $sim['run']?->options;
        $cats = $company->assetCategories()->where('kind', 'materiale')->whereIn('id', collect($sim['rows'])->map(fn ($x) => $x['asset']->asset_category_id)->unique())->orderBy('code')->get(['id', 'code', 'name']);

        return Inertia::render('Cespiti/Depreciation', ['year' => $year, 'years' => $this->depYears($company), 'blocking' => $sim['blocking'], 'closed' => $sim['closed'],
            'run' => $sim['run'] ? [...$sim['run']->only(['id', 'status', 'total_civil', 'total_fiscal', 'journal_entry_id']), 'calculated_at' => $sim['run']->calculated_at?->toIso8601String(), 'posted_at' => $sim['run']->posted_at?->toIso8601String()] : null,
            'history' => $company->assetDepreciationRuns()->where('year', $year)->orderByDesc('id')->get(['id', 'status', 'total_civil', 'total_fiscal', 'reason', 'calculated_at', 'posted_at', 'reversed_at']),
            'rows' => array_map(fn ($x) => ['id' => $x['asset']->id, 'code' => $x['asset']->code, 'description' => $x['asset']->description, 'category' => $x['asset']->category->name,
                'method' => $x['row']['method'], 'fixed' => $x['row']['fixed'], 'base' => self::e($x['row']['base_civil']), 'rate' => self::e($x['row']['civil_rate']), 'civil' => self::e($x['row']['civil_amount']),
                'limit' => self::e($x['row']['fiscal_limit']), 'fiscal' => self::e($x['row']['fiscal_amount']), 'nondeductible' => self::e($x['row']['nondeductible']), 'fund_end' => self::e($x['row']['fund_civil_end']),
                'days' => $x['row']['days'], 'warnings' => $x['row']['warnings'], 'kind' => $x['asset']->kind, 'extra' => self::e($x['row']['extra_deduction'] ?? 0),
                'extra_pct' => $x['asset']->extra_deduction_pct !== null ? (string) $x['asset']->extra_deduction_pct : null, 'reduced_pct' => isset($x['row']['reduced_pct']) ? self::e($x['row']['reduced_pct']) : null], $sim['rows']),
            'categories' => $cats, 'options' => $stored ?? ['reduced_pct' => $opts['reduced_pct'] !== null ? self::e($opts['reduced_pct']) : null, 'categories' => array_map(fn ($v) => self::e($v), $opts['categories'])]]);
    }

    public function calculate(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $run = $this->dep->calculate($company, $request->user(), $this->depYear($request, $company), $this->depOptions($request));

        return back()->with('success', "Ammortamenti {$run->year} calcolati: quote ferme, da contabilizzare.");
    }

    public function post(Request $request, Company $company, AssetDepreciationRun $assetDepreciationRun): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Conferma la contabilizzazione.']);
        $this->dep->post($company, $request->user(), $assetDepreciationRun);

        return back()->with('success', 'Ammortamenti contabilizzati nella prima nota cespiti.');
    }

    public function reverseRun(Request $request, Company $company, AssetDepreciationRun $assetDepreciationRun): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->dep->reverse($company, $request->user(), $assetDepreciationRun, (string) $request->input('reason'));

        return back()->with('success', 'Calcolo stornato.');
    }

    public function importPage(Request $request, Company $company): Response
    {
        $job = $request->query('job') ? $company->assetImportJobs()->find($request->query('job')) : null;

        return Inertia::render('Cespiti/Import', ['fields' => array_keys(AssetImportService::FIELDS), 'categories' => $company->assetCategories()->orderBy('code')->get(['code', 'name']),
            'job' => $job ? ['id' => $job->id, 'filename' => $job->filename, 'mode' => $job->mode, 'status' => $job->status, 'mapping' => $job->mapping, 'imported' => $job->imported,
                'head' => $job->report['head'] ?? [], 'missing' => $job->report['missing'] ?? [], 'rows' => array_map(fn ($r) => array_intersect_key($r, array_flip(['line', 'codice', 'descrizione', 'categoria', 'errors', 'warnings', 'anno'])), $job->report['rows'] ?? []),
                'error_count' => $job->report['error_count'] ?? 0, 'warning_count' => $job->report['warning_count'] ?? 0, 'assets' => $job->report['assets'] ?? 0, 'skipped' => $job->report['skipped'] ?? []] : null,
            'jobs' => $company->assetImportJobs()->latest('id')->limit(10)->get(['id', 'filename', 'mode', 'status', 'rows', 'imported', 'created_at'])]);
    }

    public function upload(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $request->validate(['file' => ['required', 'file', 'max:5120'], 'mode' => ['required', 'in:saldi,storico']]);
        $f = $request->file('file');
        if (! in_array(strtolower($f->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            return back()->withErrors(['file' => 'Carica un file CSV (separatore ; o ,). Da Excel: “Salva con nome” › CSV.']);
        }
        $job = app(AssetImportService::class)->preview($company, $request->user(), $f->getClientOriginalName(), (string) file_get_contents($f->getRealPath()), $request->input('mode'));

        return redirect("/aziende/{$company->id}/cespiti/importazione?job={$job->id}");
    }

    public function remap(Request $request, Company $company, AssetImportJob $assetImportJob): RedirectResponse
    {
        Gate::authorize('manage', $company);
        abort_unless($assetImportJob->status === 'anteprima', 422);
        $svc = app(AssetImportService::class);
        $rep = $assetImportJob->report;
        $csv = implode("\n", array_map(fn ($r) => implode(';', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $r)), [$rep['head'], ...$rep['raw']]));
        $svc->preview($company, $request->user(), $assetImportJob->filename, $csv, $assetImportJob->mode, (array) $request->input('mapping', []), $assetImportJob);

        return back();
    }

    public function runImport(Request $request, Company $company, AssetImportJob $assetImportJob): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $j = app(AssetImportService::class)->import($company, $request->user(), $assetImportJob, $request->boolean('only_valid'));

        return back()->with('success', "{$j->imported} cespiti importati (nessuna scrittura contabile generata).");
    }

    public function template(Request $request, Company $company): HttpResponse
    {
        $saldi = $request->query('modo') !== 'storico';
        $head = $saldi ? 'codice;descrizione;categoria;data_acquisto;data_entrata_funzione;costo_storico;incrementi;decrementi;rivalutazioni;aliquota_civilistica;aliquota_fiscale;fondo_civilistico;fondo_fiscale;ultimo_esercizio;residuo;stato'
            : 'codice;descrizione;categoria;data_acquisto;data_entrata_funzione;costo_storico;anno;aliquota_civilistica;aliquota_fiscale;quota_civilistica;quota_fiscale';
        $ex = $saldi ? 'M-001;Tornio CNC;CAT1;15/03/2022;01/04/2022;50000,00;;;;20;20;30000,00;30000,00;2025;20000,00;attivo'
            : "M-002;Furgone;CAT2;10/06/2023;10/06/2023;30000,00;2023;20;20;3000,00;3000,00\nM-002;Furgone;CAT2;10/06/2023;10/06/2023;30000,00;2024;20;20;6000,00;6000,00";

        return response("\xEF\xBB\xBF{$head}\r\n{$ex}\r\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="modello-cespiti-'.($saldi ? 'saldi' : 'storico').'.csv"']);
    }

    public function reports(Request $request, Company $company): Response
    {
        $year = $this->year($request, $company);
        $key = $request->query('stampa', 'registro');
        $f = $request->only(['category', 'status', 'location', 'cost_center', 'purchase_year', 'account', 'asset']);
        $r = $key === 'scheda' && empty($f['asset']) ? null : app(AssetReportService::class)->build($company, $key, $year, $f);

        return Inertia::render('Cespiti/Reports', ['year' => $year, 'key' => $key, 'reports' => AssetReportService::REPORTS, 'filters' => $f, 'report' => $r,
            'categories' => $company->assetCategories()->orderBy('code')->get(['id', 'code', 'name']), 'statuses' => AssetService::STATUS,
            'assets' => $company->assets()->orderBy('number')->get(['id', 'code', 'description']), 'accounts' => $company->accounts()->where('type', 'patrimoniale')->where('sezione', 'like', 'immobilizzazioni%')->orderBy('code')->get(['id', 'code', 'name'])]);
    }

    public function download(Request $request, Company $company, string $report, string $format): HttpResponse
    {
        $year = $this->year($request, $company);
        $svc = app(AssetReportService::class);
        $r = $svc->build($company, $report, $year, $request->only(['category', 'status', 'location', 'cost_center', 'purchase_year', 'account', 'asset']));
        Audit::record('asset_report.exported', $request->user(), $company, data: ['report' => $report, 'year' => $year, 'format' => $format]);
        $name = "cespiti-{$report}-{$year}";
        if ($format === 'csv') {
            return response($svc->csv($r), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}.csv\""]);
        }
        $o = new Options;
        $o->setIsRemoteEnabled(false);
        $o->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($o);
        $pdf->loadHtml(view('pdf.asset_report', ['r' => $r, 'company' => $company, 'generatedAt' => now()])->render(), 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}.pdf\""]);
    }

    public function journal(Request $request, Company $company): Response
    {
        $year = $this->year($request, $company);

        return Inertia::render('Cespiti/Journal', ['year' => $year, 'entries' => JournalEntry::where('company_id', $company->id)->where('year', $year)->with(['lines.account', 'creator'])->orderBy('number')->get()
            ->map(fn ($e) => ['id' => $e->id, 'number' => "{$e->number}/{$e->year}", 'date' => $e->entry_date->format('Y-m-d'), 'description' => $e->description, 'status' => $e->status, 'source' => $e->source_key,
                'user' => $e->creator?->name, 'lines' => $e->lines->map(fn ($l) => ['account' => $l->account->code.' '.$l->account->name, 'dare' => (string) $l->dare, 'avere' => (string) $l->avere, 'description' => $l->description])])]);
    }

    public function settings(Request $request, Company $company): Response
    {
        return Inertia::render('Cespiti/Settings', ['settings' => AssetService::settings($company), 'year' => $this->year($request, $company),
            'closures' => FiscalYearClosure::where('company_id', $company->id)->orderByDesc('year')->get(['year', 'closed_at', 'reopened_at', 'reopen_reason'])]);
    }

    public function saveSettings(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->assets->updateSettings($company, $request->user(), $request->all());

        return back()->with('success', 'Impostazioni salvate.');
    }

    public function closeYear(Request $request, Company $company, int $year): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $request->boolean('reopen') ? FiscalYears::reopen($company, $request->user(), $year, (string) $request->input('reason')) : FiscalYears::close($company, $request->user(), $year);

        return back()->with('success', $request->boolean('reopen') ? "Esercizio {$year} riaperto." : "Esercizio {$year} chiuso.");
    }
}
