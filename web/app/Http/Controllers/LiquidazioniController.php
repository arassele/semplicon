<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\Liquidazioni\ClaimService;
use App\Domain\Liquidazioni\CreditService;
use App\Domain\Liquidazioni\F24Service;
use App\Domain\Liquidazioni\LipeService;
use App\Domain\Liquidazioni\Rules;
use App\Domain\Liquidazioni\SettlementCalculator;
use App\Domain\Liquidazioni\SettlementService;
use App\Models\Company;
use App\Models\F24Draft;
use App\Models\VatAdjustment;
use App\Models\VatClaim;
use App\Models\VatCredit;
use App\Models\VatCreditMovement;
use App\Models\VatSettlement;
use App\Support\Decimal;
use App\Support\Present;
use App\Support\VatPeriod;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Ticket 11: Liquidazioni IVA, Destinazione del credito, deleghe F24, Registro crediti, pratiche di rimborso. */
class LiquidazioniController extends Controller
{
    private const AMOUNT = ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'];

    public function __construct(private SettlementCalculator $calc, private SettlementService $settlements, private CreditService $credits, private F24Service $f24, private LipeService $lipe) {}

    private function base(Company $c): string
    {
        return "/aziende/{$c->id}/liquidazioni";
    }

    /** Importi in centesimi del prospetto come testo decimale. */
    private static function money(array $d): array
    {
        if (isset($d['figures'])) {
            $d['figures'] = array_map(fn ($v) => Decimal::fromMinor((int) $v), $d['figures']);
        }
        unset($d['hash']);

        return $d;
    }

    private function period(Company $company, int $year, int $period): void
    {
        $p = $company->activeVatProfile($year);
        abort_if($period < 1 || $period > ($p?->periodicity ? VatPeriod::max($p->periodicity->value) : 12), 404);
    }

    public function index(Request $request, Company $company): Response
    {
        // Sezione 3: per impostazione l'anno contabile attivo; «Tutti» mostra tutti gli anni con regime, separati per anno.
        $all = $request->query('anno') === 'tutti';
        $year = $all ? (int) now()->year : max(2000, min(2100, (int) ($request->query('anno') ?: InvoiceController::activeYear($company))));
        $p = $company->activeVatProfile($year);
        $issues = $all ? [] : $this->calc->setupIssues($company, $year);
        $rows = [];
        $years = $company->vatProfiles()->where('status', 'confermato')->distinct()->orderByDesc('year')->pluck('year')->all();
        foreach ($all ? $years : [$year] as $yy) {
            $p = $company->activeVatProfile($yy);
            if (! $p?->periodicity) {
                continue;
            }
            $year = $yy;
            for ($i = 1; $i <= VatPeriod::max($p->periodicity->value); $i++) {
                $s = SettlementCalculator::latest($company, $year, $i);
                $d = $s ? null : $this->calc->compute($company, $year, $i);
                $f = $s ? F24Service::active($s) : null;
                $fig = $s ? $s->data['figures'] : ($d['figures'] ?? null);
                $rows[] = ['period' => $i, 'label' => VatPeriod::label($year, $i, $p->periodicity->value), 'status' => $s ? ($s->review_required ? 'riesaminare' : 'confermata') : 'bozza',
                    'version' => $s?->version, 'result' => $s?->result ?? ($d['result'] ?? null), 'due' => $fig ? Decimal::fromMinor($fig['due']) : null,
                    'credit' => $fig ? Decimal::fromMinor($fig['credit']) : null, 'deferred' => $fig ? Decimal::fromMinor($fig['deferred_out']) : null,
                    'due_date' => $s?->due_date?->toDateString() ?? ($d && $d['ok'] && $d['figures']['due'] > 0 ? $d['due_date'] : null),
                    'checks' => count($d['checks'] ?? []), 'empty' => ! $s && $d && $d['ok'] && ! $d['registration_ids'] && ! $d['adjustments'] && ! $d['credits'] && ! $d['figures']['deferred_in'],
                    'f24' => $f ? ['id' => $f->id, 'status' => $f->status, 'label' => F24Service::STATUS[$f->status]] : null, 'year' => $year,
                    // Critique del 25.11.2026: stato del versamento nella lista e termine superato per i periodi non confermati
                    'pay_state' => $s && $fig && $fig['due'] > 0 && ! ($f && in_array($f->status, ['presentato', 'addebito_confermato'], true)) ? (($s->due_date?->toDateString() ?? '9999') < now()->toDateString() ? 'scaduto' : 'da_versare') : ($s && $fig && $fig['due'] <= 0 ? 'nessuno' : null),
                    'term_passed' => ! $s && VatPeriod::end($year, $i, $p->periodicity->value) < now()->toDateString() && Rules::dueDate($year, $i, $p->periodicity->value, $p->quarterly_type) < now()->toDateString() && ! ($d && $d['ok'] && ! $d['registration_ids'])];
            }
        }
        $year = $all ? 'tutti' : $year;
        $p = $all ? null : $company->activeVatProfile($year);

        return Inertia::render('Liquidazioni/Index', ['year' => $year, 'years' => $years, 'profile' => $p ? ['label' => $p->regimeLabel(), 'periodicity' => Present::periodicityLabel($p), 'pro_rata' => (string) $p->pro_rata] : null,
            'issues' => $issues, 'rows' => $rows, 'lipe' => $all || ! $p?->periodicity ? [] : $this->lipeRows($company, (int) $year), 'rules' => is_int($year) && Rules::for($year) ? Rules::for($year)['version'] : null]);
    }

    /** Stato della LIPE per trimestre per la panoramica. */
    private function lipeRows(Company $company, int $year): array
    {
        return array_map(function (int $q) use ($company, $year) {
            $b = $this->lipe->build($company, $year, $q);

            return ['quarter' => $q, 'label' => "{$q}° trimestre {$year}", 'ok' => $b['ok'], 'reasons' => $b['blocking'], 'modules' => count($b['modules']), 'deadline' => $b['deadline'], 'filename' => $b['filename']];
        }, [1, 2, 3, 4]);
    }

    public function show(Request $request, Company $company, int $year, int $period): Response
    {
        $this->period($company, $year, $period);
        $this->calc->forget();
        $draft = $this->calc->compute($company, $year, $period);
        $latest = SettlementCalculator::latest($company, $year, $period);
        $versions = VatSettlement::where('company_id', $company->id)->where('year', $year)->where('period', $period)->with('confirmer')->orderByDesc('version')->get();
        $f24 = $latest ? F24Draft::where('settlement_id', $latest->id)->orderByDesc('id')->get() : collect();
        $comp = [];
        if ($latest && Decimal::toMinor((string) $latest->amount_due) > 0 && ! F24Service::active($latest)) {
            foreach (VatCredit::where('company_id', $company->id)->where('status', 'attivo')->whereIn('kind', ['annuale', 'trimestrale'])->orderBy('year')->get() as $c) {
                $reserved = $c->movements()->where('destination', 'compensazione')->where('status', 'prenotata')->whereNull('f24_id')->get()->sum(fn ($m) => Decimal::toMinor((string) $m->amount));
                $avail = $c->residualCents() + $reserved;
                if ($avail <= 0) {
                    continue;
                }
                $av = $this->credits->availability($c, 'compensazione', min($avail, Decimal::toMinor((string) $latest->amount_due)), $latest->due_date->toDateString());
                $comp[] = ['credit_id' => $c->id, 'description' => $c->description, 'kind' => CreditService::KIND[$c->kind], 'available' => Decimal::fromMinor($avail), 'reserved' => Decimal::fromMinor($reserved),
                    'reasons' => array_values(array_filter($av['reasons'], fn ($x) => ! str_contains($x, 'residuo'))), 'first_date' => $av['first_date'],
                    'code' => $c->kind === 'trimestrale' ? (Rules::for($c->year)['codes']['tr_credit'][$c->period] ?? null) : '6099', 'year' => $c->year];
            }
        }
        $p = $company->activeVatProfile($year);

        return Inertia::render('Liquidazioni/Show', [
            'year' => $year, 'period' => $period, 'label' => $p?->periodicity ? VatPeriod::label($year, $period, $p->periodicity->value) : "{$period}/{$year}",
            'draft' => self::money($draft), 'confirmed' => $latest ? [...$this->settlementInfo($latest), 'data' => self::money($latest->data)] : null,
            'versions' => $versions->map(fn ($v) => $this->settlementInfo($v))->values(),
            'compare' => $latest?->review_required ? $this->settlements->compare($company, $latest) : null,
            'f24' => $f24->map(fn ($f) => $this->f24Info($f))->values(), 'compensable' => $comp,
            'creditSummary' => $latest && $latest->result === 'credito' ? $this->creditSummary($company, $latest) : null,
            'prev' => $period > 1 ? [$year, $period - 1] : [$year - 1, $p?->periodicity ? VatPeriod::max($p->periodicity->value) : 12],
            'next' => $p?->periodicity ? SettlementService::next($year, $period, $p->periodicity->value) : [$year, $period + 1],
            'lipe' => $p?->periodicity ? ['quarter' => intdiv(($p->periodicity->value === 'trimestrale' ? $period * 3 : $period) - 1, 3) + 1, 'year' => $year] : null,
            'today' => now()->toDateString(), 'canAcconto' => $p?->periodicity && SettlementService::isYearEnd($p->periodicity->value, $period),
        ]);
    }

    private function settlementInfo(VatSettlement $s): array
    {
        return ['id' => $s->id, 'version' => $s->version, 'status' => $s->review_required && $s->status === 'confermata' ? 'riesaminare' : $s->status, 'result' => $s->result,
            'amount_due' => (string) $s->amount_due, 'credit' => (string) $s->credit, 'deferred' => (string) $s->deferred, 'due_date' => $s->due_date?->toDateString(),
            'review_reason' => $s->review_reason, 'confirmed_by' => $s->confirmer?->name, 'confirmed_at' => $s->confirmed_at->toIso8601String(), 'rule_version' => $s->rule_version];
    }

    private function f24Info(F24Draft $f): array
    {
        return ['id' => $f->id, 'status' => $f->status, 'status_label' => F24Service::STATUS[$f->status], 'due_date' => $f->due_date->toDateString(), 'lines' => $f->lines,
            'total_debit' => (string) $f->total_debit, 'total_credit' => (string) $f->total_credit, 'balance' => (string) $f->balance, 'presented_at' => $f->presented_at?->toDateString(),
            'protocol' => $f->protocol, 'outcome' => $f->outcome, 'debit_confirmed_at' => $f->debit_confirmed_at?->toDateString(), 'settlement_id' => $f->settlement_id, 'rule_version' => $f->rule_version];
    }

    private function creditInfo(VatCredit $c, ?string $useDate = null): array
    {
        $r = $c->residualCents();
        $dest = [];
        foreach (['riporto', 'compensazione', 'rimborso'] as $d) {
            $av = $this->credits->availability($c, $d, min($r, max(1, $r)), $useDate);
            $dest[$d] = ['ok' => $av['ok'] || ($d === 'compensazione' && ! array_filter($av['reasons'], fn ($x) => str_contains($x, 'singolo periodo') || str_contains($x, 'sostituito'))),
                'reasons' => array_values(array_filter($av['reasons'], fn ($x) => ! str_contains($x, 'residuo'))), 'first_date' => $av['first_date']];
        }
        [$ty, $tp] = $this->credits->nextFor($c->company, $c);
        $tp2 = $c->company->activeVatProfile($ty);

        return ['id' => $c->id, 'kind' => $c->kind, 'kind_label' => CreditService::KIND[$c->kind], 'origin' => $c->origin, 'description' => $c->description, 'year' => $c->year, 'period' => $c->period,
            'amount' => (string) $c->amount, 'residual' => Decimal::fromMinor($r), 'status' => $c->status, 'filed_at' => $c->filed_at?->toDateString(), 'visto' => $c->visto,
            'settlement' => $c->settlement ? ['year' => $c->settlement->year, 'period' => $c->settlement->period, 'label' => $c->settlement->data['period_label']] : null,
            'next' => ['year' => $ty, 'period' => $tp, 'label' => VatPeriod::label($ty, $tp, $tp2?->periodicity?->value ?? 'mensile')], 'destinations' => $dest,
            'movements' => $c->movements()->with('f24', 'claim')->orderBy('id')->get()->map(fn (VatCreditMovement $m) => ['id' => $m->id, 'destination' => $m->destination,
                'destination_label' => CreditService::DESTINATION[$m->destination] ?? $m->destination, 'amount' => (string) $m->amount, 'status' => $m->status,
                'status_label' => CreditService::MOVEMENT_STATUS[$m->status], 'target' => $m->target_year ? ['year' => $m->target_year, 'period' => $m->target_period] : null,
                'f24' => $m->f24 ? ['id' => $m->f24->id, 'status' => F24Service::STATUS[$m->f24->status], 'settlement_id' => $m->f24->settlement_id] : null,
                'claim' => $m->claim ? ['id' => $m->claim->id, 'status' => ClaimService::STATUS[$m->claim->status]] : null, 'reason' => $m->reason, 'date' => $m->created_at->toDateString()])->values()];
    }

    private function creditSummary(Company $company, VatSettlement $s): array
    {
        [$own, $origins] = $this->credits->settlementCredits($company, $s);

        return ['own' => $own->map(fn ($c) => $this->creditInfo($c))->values(), 'origins' => $origins->map(fn ($c) => $this->creditInfo($c))->values()];
    }

    public function confirm(Request $request, Company $company, int $year, int $period): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->period($company, $year, $period);
        $data = $request->validate(['request_id' => ['nullable', 'string', 'max:64']]);
        $s = $this->settlements->confirm($company, $request->user(), $year, $period, $data['request_id'] ?? null);

        return redirect($this->base($company)."/{$year}/{$period}")->with('success', $s->replayed ? 'Liquidazione già confermata (versione '.$s->version.').' : 'Liquidazione '.$s->data['period_label'].' confermata (versione '.$s->version.').');
    }

    public function addAdjustment(Request $request, Company $company, int $year, int $period): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->period($company, $year, $period);
        $data = $request->validate(['kind' => ['required', 'in:debito,detrazione,acconto'], 'amount' => ['required', 'regex:/^-?\d{1,12}([.,]\d{1,2})?$/'],
            'causale' => ['required', 'string', 'max:255'], 'reference' => ['nullable', 'string', 'max:120']], ['amount.regex' => 'Importo non valido (es. -50,00).'], ['causale' => 'causale', 'amount' => 'importo']);
        $this->settlements->addAdjustment($company, $request->user(), $year, $period, $data);

        return back()->with('success', 'Rettifica registrata.');
    }

    public function deleteAdjustment(Request $request, Company $company, VatAdjustment $vatAdjustment): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $this->settlements->deleteAdjustment($company, $request->user(), $vatAdjustment);

        return back()->with('success', 'Rettifica eliminata.');
    }

    public function credit(Request $request, Company $company, int $year, int $period): Response
    {
        $this->period($company, $year, $period);
        $s = SettlementCalculator::latest($company, $year, $period);
        abort_unless($s && $s->result === 'credito', 404);
        $tr = null;
        $isQuarterEnd = $s->periodicity === 'trimestrale' ? $period <= 3 : in_array($period, [3, 6, 9], true);
        if ($isQuarterEnd) {
            $q = $s->periodicity === 'trimestrale' ? $period : intdiv($period, 3);
            $chk = $this->credits->trCheck($company, $year, $q);
            $tr = ['quarter' => $q, 'eligible' => $chk['eligible'], 'matured' => Decimal::fromMinor($chk['matured']), 'admissible' => Decimal::fromMinor($chk['admissible']),
                'threshold' => Decimal::fromMinor($chk['threshold']), 'reasons' => $chk['reasons'], 'deadline' => $chk['deadline'],
                'existing' => VatClaim::where('company_id', $company->id)->where('kind', 'tr')->where('year', $year)->where('quarter', $q)->whereIn('status', ['bozza', 'richiesto', 'erogato'])->exists()];
        }
        $rules = Rules::for($year) ?? [];

        return Inertia::render('Liquidazioni/Credit', ['year' => $year, 'period' => $period, 'settlement' => [...$this->settlementInfo($s), 'label' => $s->data['period_label'],
            'figures' => array_map(fn ($v) => Decimal::fromMinor((int) $v), $s->data['figures'])],
            'credits' => $this->creditSummary($company, $s), 'tr' => $tr, 'presupposti' => $rules['tr_presupposti'] ?? [], 'annualPresupposti' => $rules['annual_presupposti'] ?? []]);
    }

    public function allocate(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['rows' => ['required', 'array', 'min:1'], 'rows.*.credit_id' => ['required', 'integer'],
            'rows.*.riporto' => ['nullable', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'], 'rows.*.compensazione' => ['nullable', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'],
            'rows.*.rimborso' => ['nullable', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'], 'target_year' => ['nullable', 'integer'], 'target_period' => ['nullable', 'integer', 'between:1,12'],
            'presupposto' => ['nullable', 'string', 'max:40'], 'documentation' => ['nullable', 'string', 'max:2000']], [], ['rows.*.riporto' => 'riporto', 'rows.*.compensazione' => 'compensazione', 'rows.*.rimborso' => 'rimborso']);
        $made = $this->credits->allocate($company, $request->user(), $data['rows'], array_filter(['target_year' => $data['target_year'] ?? null, 'target_period' => $data['target_period'] ?? null,
            'presupposto' => $data['presupposto'] ?? null, 'documentation' => $data['documentation'] ?? null], fn ($v) => $v !== null));

        return back()->with('success', count($made) ? 'Destinazione del credito confermata.' : 'Nessuna quota destinata.');
    }

    public function requestTr(Request $request, Company $company, int $year, int $quarter): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['refund' => ['nullable', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'], 'compensation' => ['nullable', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'],
            'presupposto' => ['required', 'string', 'max:40'], 'documentation' => ['nullable', 'string', 'max:2000'], 'visto' => ['nullable', 'boolean']], [], ['presupposto' => 'presupposto']);
        $claim = $this->credits->requestTr($company, $request->user(), $year, $quarter, $data);

        return redirect("/aziende/{$company->id}/crediti-iva#pratica-{$claim->id}")->with('success', "Pratica IVA TR {$quarter}° trimestre {$year} preparata (bozza).");
    }

    public function generateF24(Request $request, Company $company, int $year, int $period): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $s = SettlementCalculator::latest($company, $year, $period);
        abort_unless($s, 404);
        $data = $request->validate(['compensations' => ['nullable', 'array'], 'compensations.*.credit_id' => ['required', 'integer'],
            'compensations.*.amount' => ['nullable', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'], 'request_id' => ['nullable', 'string', 'max:64']]);
        $rows = array_values(array_filter($data['compensations'] ?? [], fn ($r) => ($r['amount'] ?? '') !== '' && Decimal::toMinor(str_replace(',', '.', $r['amount'])) > 0));
        $f = $this->f24->generate($company, $request->user(), $s, $rows, $data['request_id'] ?? null);

        return back()->with('success', $f->replayed ? 'Delega F24 già generata (n. '.$f->id.').' : 'Bozza F24 n. '.$f->id.' generata'.(Decimal::toMinor((string) $f->balance) === 0 ? ' a saldo zero' : '').': da presentare entro il '.$f->due_date->format('d/m/Y').'.');
    }

    public function f24Status(Request $request, Company $company, F24Draft $f24Draft, string $action): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'protocol' => ['nullable', 'string', 'max:60'], 'reason' => ['nullable', 'string', 'max:500']]);
        match ($action) {
            'presentato' => $this->f24->present($company, $request->user(), $f24Draft, $data['date'] ?? now()->toDateString(), $data['protocol'] ?? null),
            'scartato' => $this->f24->reject($company, $request->user(), $f24Draft, (string) ($data['reason'] ?? '')),
            'addebito' => $this->f24->confirmDebit($company, $request->user(), $f24Draft, $data['date'] ?? now()->toDateString()),
        };

        return back()->with('success', 'Stato della delega aggiornato.');
    }

    public function credits(Request $request, Company $company): Response
    {
        // Sezione 3: anno = anno di formazione del credito; utilizzi e riporti restano all'origine.
        $creditYears = VatCredit::where('company_id', $company->id)->distinct()->orderByDesc('year')->pluck('year')->all();
        $anno = $request->query('anno') === 'tutti' ? 'tutti' : (preg_match('/^\d{4}$/', (string) $request->query('anno')) ? (int) $request->query('anno') : InvoiceController::activeYear($company));
        $credits = VatCredit::where('company_id', $company->id)->when($anno !== 'tutti', fn ($q) => $q->where('year', $anno))->with('settlement', 'company')->orderByDesc('year')->orderByDesc('id')->get();
        // Disponibile = maturato − già utilizzato; un riporto è utilizzato solo quando la liquidazione di destinazione è confermata (contato una volta).
        $available = $credits->where('status', 'attivo')->sum(fn ($c) => Decimal::toMinor((string) $c->amount) - $c->movements()->where('status', 'utilizzata')->get()->sum(fn ($m) => Decimal::toMinor((string) $m->amount)));
        $claims = VatClaim::where('company_id', $company->id)->with('credit')->orderByDesc('id')->get();
        $y = (int) now()->year;

        return Inertia::render('Liquidazioni/Credits', [
            'credits' => $credits->map(fn ($c) => $this->creditInfo($c))->values(),
            'anno' => $anno, 'years' => $creditYears, 'available' => Decimal::fromMinor($available),
            'claims' => $claims->map(fn (VatClaim $c) => ['id' => $c->id, 'kind' => $c->kind, 'year' => $c->year, 'quarter' => $c->quarter, 'status' => $c->status, 'status_label' => ClaimService::STATUS[$c->status],
                'refund' => (string) $c->refund_amount, 'compensation' => (string) $c->compensation_amount, 'presupposto' => $c->presupposto,
                'presupposto_label' => (Rules::for($c->year)[$c->kind === 'tr' ? 'tr_presupposti' : 'annual_presupposti'][$c->presupposto] ?? $c->presupposto),
                'documentation' => $c->documentation, 'deadline' => $c->deadline?->toDateString(), 'filed_at' => $c->filed_at?->toDateString(), 'protocol' => $c->protocol,
                'outcome_at' => $c->outcome_at?->toDateString(), 'outcome_notes' => $c->outcome_notes, 'credit' => $c->credit?->description,
                'guarantee' => Decimal::toMinor((string) $c->refund_amount) > Decimal::toMinor((Rules::for($c->year) ?? Rules::for(2026))['refund_guarantee_limit'])])->values(),
            'annualPresupposti' => (Rules::for($y) ?? Rules::for(2026))['annual_presupposti'], 'today' => now()->toDateString(), 'year' => $y,
        ]);
    }

    public function storeCredit(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'amount' => self::AMOUNT, 'description' => ['nullable', 'string', 'max:255'],
            'filed_at' => ['nullable', 'date_format:Y-m-d'], 'visto' => ['nullable', 'boolean'], 'visto_exempt_limit' => ['nullable', 'regex:/^\d{1,12}([.,]\d{1,2})?$/']], [], ['amount' => 'importo', 'year' => 'anno']);
        $this->credits->registerAnnual($company, $request->user(), $data);

        return back()->with('success', 'Credito annuale registrato.');
    }

    public function updateFiling(Request $request, Company $company, VatCredit $vatCredit): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['filed_at' => ['nullable', 'date_format:Y-m-d'], 'visto' => ['nullable', 'boolean']]);
        $this->credits->updateFiling($company, $request->user(), $vatCredit, $data);

        return back()->with('success', 'Dati della dichiarazione aggiornati.');
    }

    public function release(Request $request, Company $company, VatCreditMovement $vatCreditMovement): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);
        $this->credits->release($company, $request->user(), $vatCreditMovement, $data['reason']);

        return back()->with('success', 'Quota sbloccata.');
    }

    public function claim(Request $request, Company $company, VatClaim $vatClaim, string $action, ClaimService $claims): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'protocol' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:1000']], [], ['date' => 'data']);
        $action === 'presentata' ? $claims->file($company, $request->user(), $vatClaim, $data['date'], $data['protocol'] ?? null)
            : $claims->outcome($company, $request->user(), $vatClaim, $action, $data['date'], $data['notes'] ?? null);

        return back()->with('success', 'Pratica aggiornata.');
    }

    /** PDF del prospetto (versione confermata, altrimenti bozza con dicitura). */
    public function pdf(Request $request, Company $company, int $year, int $period): HttpResponse
    {
        $this->period($company, $year, $period);
        $s = SettlementCalculator::latest($company, $year, $period);
        $this->calc->forget();
        $d = $s ? $s->data : $this->calc->compute($company, $year, $period);
        abort_unless($d['ok'] ?? false, 404);
        Audit::record('settlement.exported', $request->user(), $company, $s, data: ['year' => $year, 'period' => $period]);

        return $this->renderPdf(view('pdf.liquidazione', ['company' => $company, 'd' => self::money($d), 's' => $s, 'generatedAt' => now()])->render(), "liquidazione-iva-{$year}-p{$period}.pdf");
    }

    public function f24Pdf(Request $request, Company $company, F24Draft $f24Draft): HttpResponse
    {
        Audit::record('f24.exported', $request->user(), $company, $f24Draft);

        return $this->renderPdf(view('pdf.f24', ['company' => $company, 'f' => $f24Draft, 'generatedAt' => now()])->render(), "f24-bozza-{$f24Draft->id}.pdf", 'portrait');
    }

    private function renderPdf(string $html, string $name, string $orientation = 'portrait'): HttpResponse
    {
        $o = new Options;
        $o->setIsRemoteEnabled(false);
        $o->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($o);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', $orientation);
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }
}
