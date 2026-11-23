<?php

namespace App\Domain\Cespiti;

use App\Domain\Audit;
use App\Domain\ManualInvoiceService;
use App\Domain\RuleViolation;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCategoryRate;
use App\Models\AssetDocument;
use App\Models\AssetMovement;
use App\Models\AssetOpeningBalance;
use App\Models\AssetRate;
use App\Models\Company;
use App\Models\DepreciationRate;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Anagrafica: categorie (con aliquote da anno), cespiti, documenti, impostazioni del modulo. */
final class AssetService
{
    public const KINDS = ['materiale' => 'Materiale', 'immateriale' => 'Immateriale', 'non_ammortizzabile' => 'Non ammortizzabile'];

    public const STATUS = ['attivo' => 'Attivo', 'ammortizzato' => 'Completamente ammortizzato', 'venduto' => 'Venduto', 'eliminato' => 'Eliminato', 'sospeso' => 'Sospeso'];

    /** La quota ridotta viene impostata nel calcolo (decisione n. 81), non nel cespite. */
    public const METHODS = ['ordinario' => 'Ordinario', 'pieno' => 'Pieno', 'manuale' => 'Manuale'];

    public const ROLES = ['asset' => 'Immobilizzazione', 'fund' => 'Fondo ammortamento', 'expense' => 'Ammortamento (conto economico)', 'gain' => 'Plusvalenza', 'loss' => 'Minusvalenza / perdita da eliminazione', 'writedown' => 'Svalutazione'];

    /** Impostazioni per azienda (companies.asset_settings). */
    public static function settings(Company $c): array
    {
        return array_replace(['civil_first_year_half' => false, 'sale_year_quota' => true, 'low_value_threshold' => '516.46', 'transit_account' => '150001', 'reserve_account' => '160001'], $c->asset_settings ?? []);
    }

    public function updateSettings(Company $c, User $actor, array $d): void
    {
        $e = [];
        $thr = str_replace(',', '.', (string) ($d['low_value_threshold'] ?? '516.46'));
        if (! preg_match('/^\d{1,9}(\.\d{1,2})?$/', $thr)) {
            $e['low_value_threshold'] = 'Importo non valido.';
        }
        $e && RuleViolation::fail($e);
        $c->forceFill(['asset_settings' => ['civil_first_year_half' => (bool) ($d['civil_first_year_half'] ?? false), 'sale_year_quota' => (bool) ($d['sale_year_quota'] ?? true),
            'low_value_threshold' => Decimal::fromMinor(Decimal::toMinor($thr)), 'transit_account' => (string) ($d['transit_account'] ?? '150001'), 'reserve_account' => (string) ($d['reserve_account'] ?? '160001')]])->save();
        Audit::record('assets.settings_updated', $actor, $c, data: $c->asset_settings);
    }

    /** Suggerimento di conto dalla descrizione (parole chiave), solo codici esistenti dell'azienda. */
    public static function suggestAccounts(Company $c, string $text, string $kind): array
    {
        $t = mb_strtolower($text);
        $prof = $c->accounts()->where('code', '631001')->exists();
        $map = $kind === 'immateriale'
            ? [['/software|licenz/', '110001', '130001', '605001'], ['/brevett|ingegno/', '110002', '130002', '605002'], ['/marchi|marchio|concession/', '110003', '130003', '605002'], ['/avviamento/', '110004', '130004', '605002'], ['/.*/', '110009', '130009', '605002']]
            : [['/fabbricat|edific|costruzion|capannon/', '120001', '140001', '605003'], ['/autovettur|motoveicol/', '120006', '140006', '605009'], ['/autoveicoli da trasporto|autocarr|automezz|carrelli/', '120007', '140007', '605010'],
                ['/elettronic|computer|elaborator|hardware/', '120005', '140005', '605007'], ['/mobili|arred/', '120004', '140004', '605006'], ['/attrezzatur/', '120003', '140003', '605005'], ['/macchin|impiant/', '120002', '140002', '605004'], ['/.*/', '120009', '140009', '605004']];
        foreach ($map as [$re, $a, $f, $x]) {
            if (preg_match($re, $t)) {
                if ($prof) {
                    $x = str_contains($a, '120006') ? '639007' : ($kind === 'immateriale' || $a === '120005' ? '631002' : '631001');
                }
                $id = fn (string $code) => $c->accounts()->where('code', $code)->value('id');

                return ['account_asset_id' => $id($a), 'account_fund_id' => $id($f), 'account_expense_id' => $id($x), 'account_gain_id' => $id($prof ? '712002' : '705005'),
                    'account_loss_id' => $id('609009'), 'account_writedown_id' => $id($x)];
            }
        }

        return [];
    }

    private function rate(?string $v, string $field, array &$e, bool $required = false): ?string
    {
        $v = trim(str_replace(',', '.', (string) $v));
        if ($v === '') {
            if ($required) {
                $e[$field] = 'Indica l’aliquota.';
            }

            return null;
        }
        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v) || (float) $v > 100) {
            $e[$field] = 'Aliquota non valida (0–100, al massimo 2 decimali).';

            return null;
        }

        return Decimal::fromMinor(Decimal::toMinor($v));
    }

    private function accountsFor(Company $c, array $d, array &$e): array
    {
        $out = [];
        foreach (array_keys(self::ROLES) as $r) {
            $id = $d["account_{$r}_id"] ?? null;
            if ($id) {
                $a = Account::where('company_id', $c->id)->find($id);
                if (! $a) {
                    $e["account_{$r}_id"] = 'Conto non valido.';
                } elseif (in_array($r, ['asset', 'fund'], true) && $a->type !== 'patrimoniale') {
                    $e["account_{$r}_id"] = 'Scegli un conto dello stato patrimoniale.';
                } elseif (in_array($r, ['expense', 'loss', 'writedown'], true) && $a->type !== 'costo') {
                    $e["account_{$r}_id"] = 'Scegli un conto di costo.';
                } elseif ($r === 'gain' && $a->type !== 'ricavo') {
                    $e["account_{$r}_id"] = 'Scegli un conto di ricavo.';
                }
            }
            $out["account_{$r}_id"] = $id ?: null;
        }

        return $out;
    }

    public function saveCategory(Company $c, User $actor, array $d, ?AssetCategory $cat = null): AssetCategory
    {
        $e = [];
        $code = trim((string) ($d['code'] ?? ''));
        if ($code === '' || mb_strlen($code) > 20) {
            $e['code'] = 'Indica un codice (max 20 caratteri).';
        } elseif ($c->assetCategories()->where('code', $code)->when($cat, fn ($q) => $q->where('id', '!=', $cat->id))->exists()) {
            $e['code'] = 'Codice già usato.';
        }
        if (trim((string) ($d['name'] ?? '')) === '') {
            $e['name'] = 'Indica la descrizione.';
        }
        $kind = $d['kind'] ?? 'materiale';
        if (! isset(self::KINDS[$kind])) {
            $e['kind'] = 'Tipologia non valida.';
        }
        $dm = ! empty($d['depreciation_rate_id']) ? DepreciationRate::find($d['depreciation_rate_id']) : null;
        if (! empty($d['depreciation_rate_id']) && ! $dm) {
            $e['depreciation_rate_id'] = 'Voce della tabella non valida.';
        }
        if ($dm && $kind !== 'materiale') {
            $e['depreciation_rate_id'] = 'Il D.M. 31/12/1988 riguarda solo i beni materiali.';
        }
        $fiscal = $this->rate($d['fiscal_rate'] ?? ($dm ? (string) $dm->coefficient : null), 'fiscal_rate', $e, $kind !== 'non_ammortizzabile');
        $civil = $this->rate($d['civil_rate'] ?? null, 'civil_rate', $e) ?? $fiscal;
        $acc = $this->accountsFor($c, $d, $e);
        $e && RuleViolation::fail($e);
        if ($cat && $cat->assets()->exists() && ((string) $cat->fiscal_rate !== (string) $fiscal || (string) $cat->civil_rate !== (string) $civil)) {
            RuleViolation::fail(['fiscal_rate' => 'La categoria ha già cespiti: per cambiare le aliquote usa “Modifica dal …” (vale per gli esercizi futuri, il passato resta invariato).']);
        }
        $v = ['code' => $code, 'name' => trim($d['name']), 'kind' => $kind, 'depreciation_rate_id' => $dm?->id, 'civil_rate' => $civil, 'fiscal_rate' => $fiscal,
            'first_year_half' => $kind === 'materiale' && (bool) ($d['first_year_half'] ?? true), 'active' => (bool) ($d['active'] ?? true), 'notes' => $d['notes'] ?? null, ...$acc];

        return DB::transaction(function () use ($c, $actor, $v, $cat) {
            if ($cat) {
                $cat->update($v);
            } else {
                $cat = AssetCategory::create([...$v, 'company_id' => $c->id]);
            }
            Audit::record('asset_category.saved', $actor, $c, $cat, data: ['code' => $cat->code, 'civil_rate' => $cat->civil_rate, 'fiscal_rate' => $cat->fiscal_rate]);

            return $cat;
        });
    }

    /** Creare categorie dalle righe D.M. (codice G<gruppo>-<specie>-<nr>), conti per parole chiave. */
    public function categoriesFromTable(Company $c, User $actor, array $rateIds): int
    {
        $n = 0;
        foreach (DepreciationRate::whereIn('id', $rateIds)->orderBy('position')->get() as $r) {
            if ($c->assetCategories()->where('depreciation_rate_id', $r->id)->exists()) {
                continue;
            }
            $code = 'G'.$r->gruppo.($r->specie ? '-'.preg_replace('/[^A-Za-z0-9]/', '', $r->specie) : '').'-'.$r->position;
            $this->saveCategory($c, $actor, ['code' => mb_substr($code, 0, 20), 'name' => mb_substr($r->description, 0, 200), 'kind' => 'materiale', 'depreciation_rate_id' => $r->id,
                'fiscal_rate' => (string) $r->coefficient, 'civil_rate' => (string) $r->coefficient, 'first_year_half' => true, ...self::suggestAccounts($c, $r->description, 'materiale')]);
            $n++;
        }

        return $n;
    }

    /** Nuove aliquote da un anno futuro (categoria): il passato resta invariato. */
    public function changeCategoryRates(Company $c, User $actor, AssetCategory $cat, int $fromYear, string $civil, string $fiscal, string $reason): AssetCategoryRate
    {
        $e = [];
        $cv = $this->rate($civil, 'civil_rate', $e, true);
        $fv = $this->rate($fiscal, 'fiscal_rate', $e, true);
        if (trim($reason) === '') {
            $e['reason'] = 'Indica il motivo.';
        }
        $fixedMax = DB::table('asset_depreciation')->join('assets', 'assets.id', '=', 'asset_depreciation.asset_id')->where('assets.asset_category_id', $cat->id)->where('asset_depreciation.status', '!=', 'stornato')->max('asset_depreciation.year');
        if ($fixedMax && $fromYear <= $fixedMax) {
            $e['from_year'] = "Esercizio {$fromYear} già calcolato per cespiti della categoria: la modifica vale dal ".($fixedMax + 1).' in poi.';
        }
        FiscalYears::assertOpen($c, $fromYear, $e, 'from_year');
        $e && RuleViolation::fail($e);
        $row = AssetCategoryRate::updateOrCreate(['asset_category_id' => $cat->id, 'from_year' => $fromYear], ['civil_rate' => $cv, 'fiscal_rate' => $fv, 'reason' => trim($reason), 'created_by' => $actor->id]);
        Audit::record('asset_category.rates_changed', $actor, $c, $cat, $reason, ['from_year' => $fromYear, 'civil' => $cv, 'fiscal' => $fv]);

        return $row;
    }

    /** Aliquote o quota manuale per un cespite da un anno (non per anni già calcolati). */
    public function changeAssetRates(Company $c, User $actor, Asset $a, int $fromYear, ?string $civil, ?string $fiscal, string $reason, ?string $manualCivil = null, ?string $manualFiscal = null): AssetRate
    {
        $e = [];
        $cv = $this->rate($civil, 'civil_rate', $e, true);
        $fv = $this->rate($fiscal, 'fiscal_rate', $e, true);
        $amt = function (?string $v, string $f) use (&$e) {
            $v = trim(str_replace(',', '.', (string) $v));
            if ($v === '') {
                return null;
            }
            if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v)) {
                $e[$f] = 'Importo non valido.';

                return null;
            }

            return Decimal::fromMinor(Decimal::toMinor($v));
        };
        $mc = $amt($manualCivil, 'manual_civil');
        $mf = $amt($manualFiscal, 'manual_fiscal');
        if (trim($reason) === '') {
            $e['reason'] = 'Indica il motivo.';
        }
        $fixedMax = $a->depreciations()->where('status', '!=', 'stornato')->max('year');
        if ($fixedMax && $fromYear <= $fixedMax) {
            $e['from_year'] = "Esercizio {$fromYear} già calcolato: la modifica vale solo per gli esercizi futuri (dal ".($fixedMax + 1).').';
        }
        FiscalYears::assertOpen($c, $fromYear, $e, 'from_year');
        $e && RuleViolation::fail($e);
        $row = AssetRate::updateOrCreate(['asset_id' => $a->id, 'from_year' => $fromYear], ['civil_rate' => $cv, 'fiscal_rate' => $fv, 'manual_civil' => $mc, 'manual_fiscal' => $mf, 'reason' => trim($reason), 'created_by' => $actor->id]);
        Audit::record('asset.rates_changed', $actor, $c, $a, $reason, ['from_year' => $fromYear, 'civil' => $cv, 'fiscal' => $fv, 'manual_civil' => $mc, 'manual_fiscal' => $mf]);

        return $row;
    }

    private const MONEY = ['original_cost', 'accessory_costs', 'nondeductible_vat', 'grants', 'residual_value', 'fiscal_cost', 'fiscal_cost_cap'];

    private const LOCKED = ['original_cost', 'accessory_costs', 'nondeductible_vat', 'grants', 'grant_treatment', 'residual_value', 'fiscal_cost', 'in_service_date', 'depreciation_start', 'purchase_date', 'asset_category_id', 'kind'];

    public function save(Company $c, User $actor, array $d, ?Asset $a = null): Asset
    {
        $e = [];
        $cat = $c->assetCategories()->find($d['asset_category_id'] ?? 0);
        if (! $cat) {
            $e['asset_category_id'] = 'Scegli la categoria.';
        }
        if (trim((string) ($d['description'] ?? '')) === '') {
            $e['description'] = 'Indica la descrizione.';
        }
        $date = fn ($k) => ($d[$k] ?? '') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d[$k]) ? $d[$k] : null;
        foreach (['purchase_date', 'invoice_date', 'in_service_date', 'depreciation_start'] as $k) {
            if (($d[$k] ?? '') !== '' && ! $date($k)) {
                $e[$k] = 'Data non valida.';
            }
        }
        if (! $date('purchase_date')) {
            $e['purchase_date'] = 'Indica la data di acquisto.';
        }
        if ($date('in_service_date') && $date('purchase_date') && $date('in_service_date') < $date('purchase_date')) {
            $e['in_service_date'] = 'L’entrata in funzione non può precedere l’acquisto.';
        }
        if ($date('depreciation_start') && $date('in_service_date') && $date('depreciation_start') < $date('in_service_date')) {
            $e['depreciation_start'] = 'L’ammortamento non può iniziare prima dell’entrata in funzione.';
        }
        $m = [];
        foreach (self::MONEY as $k) {
            $v = trim(str_replace(',', '.', (string) ($d[$k] ?? '')));
            if ($v === '') {
                $m[$k] = in_array($k, ['fiscal_cost', 'fiscal_cost_cap'], true) ? null : '0.00';

                continue;
            }
            if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v)) {
                $e[$k] = 'Importo non valido (es. 1250,00).';

                continue;
            }
            $m[$k] = Decimal::fromMinor(Decimal::toMinor($v));
        }
        if (! $e && Decimal::toMinor($m['original_cost']) <= 0) {
            $e['original_cost'] = 'Indica il valore originario.';
        }
        if (! $e && Decimal::toMinor($m['residual_value']) >= Decimal::toMinor($m['original_cost']) + Decimal::toMinor($m['accessory_costs'])) {
            $e['residual_value'] = 'Il valore residuo stimato deve essere inferiore al costo.';
        }
        $method = $d['method'] ?? 'ordinario';
        if (! isset(self::METHODS[$method])) {
            $e['method'] = 'Metodo non valido.';
        }
        $pct = function ($k) use ($d, &$e) {
            $v = trim(str_replace(',', '.', (string) ($d[$k] ?? '')));
            if ($v === '') {
                return null;
            }
            if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v)) {
                $e[$k] = 'Percentuale non valida.';

                return null;
            }

            return Decimal::fromMinor(Decimal::toMinor($v));
        };
        $code = trim((string) ($d['code'] ?? ''));
        if ($code !== '' && $c->assets()->where('code', $code)->when($a, fn ($q) => $q->where('id', '!=', $a->id))->exists()) {
            $e['code'] = 'Codice cespite già usato.';
        }
        if (! empty($d['invoice_id']) && ! $c->invoices()->whereKey($d['invoice_id'])->exists()) {
            $e['invoice_id'] = 'Fattura non valida.';
        }
        $acc = $this->accountsFor($c, $d, $e);
        $opening = $this->openingInput($d, $m, $date, $e);
        $e && RuleViolation::fail($e);
        $v = ['description' => trim($d['description']), 'description_long' => $d['description_long'] ?? null, 'asset_category_id' => $cat->id, 'subcategory' => $d['subcategory'] ?? null, 'kind' => $cat->kind,
            'location' => $d['location'] ?? null, 'cost_center' => $d['cost_center'] ?? null, 'serial_number' => $d['serial_number'] ?? null, 'matricola' => $d['matricola'] ?? null,
            'supplier_name' => $d['supplier_name'] ?? null, 'partner_id' => $d['partner_id'] ?? null, 'notes' => $d['notes'] ?? null,
            'purchase_date' => $date('purchase_date'), 'invoice_date' => $date('invoice_date'), 'invoice_number' => $d['invoice_number'] ?? null, 'invoice_id' => $d['invoice_id'] ?? null,
            'in_service_date' => $date('in_service_date'), 'depreciation_start' => $date('depreciation_start'), ...$m, 'grant_treatment' => ($d['grant_treatment'] ?? 'riduzione') === 'risconto' ? 'risconto' : 'riduzione',
            'method' => $method, 'reduced_pct' => null,
            'first_year_half' => array_key_exists('first_year_half', $d) && $d['first_year_half'] !== null && $d['first_year_half'] !== '' ? (bool) $d['first_year_half'] : null,
            'extra_deduction_pct' => $pct('extra_deduction_pct'), 'extra_deduction_note' => $d['extra_deduction_note'] ?? null, 'fiscal_deductible_pct' => $pct('fiscal_deductible_pct'),
            'low_value' => (bool) ($d['low_value'] ?? false), ...$acc];
        if ($v['low_value'] && Decimal::toMinor($m['original_cost']) + Decimal::toMinor($m['accessory_costs']) > Decimal::toMinor(self::settings($c)['low_value_threshold'])) {
            RuleViolation::fail(['low_value' => 'Bene di modesto valore solo fino a '.str_replace('.', ',', self::settings($c)['low_value_threshold']).' € (Impostazioni cespiti).']);
        }

        return DB::transaction(function () use ($c, $actor, $v, $a, $code, $d, $opening) {
            if ($a) {
                $a = Asset::whereKey($a->id)->lockForUpdate()->first();
                if ((int) ($d['version'] ?? $a->version) !== (int) $a->version) {
                    RuleViolation::fail(['version' => 'Il cespite è stato modificato da un altro utente: ricarica la pagina.']);
                }
                $locked = $a->depreciations()->where('status', '!=', 'stornato')->exists() || $a->movements()->where('type', '!=', 'acquisizione')->where('status', 'registrato')->exists();
                $changed = [];
                foreach (self::LOCKED as $k) {
                    $old = $a->{$k} instanceof \DateTimeInterface ? $a->{$k}->format('Y-m-d') : (string) $a->{$k};
                    if ($locked && (string) ($v[$k] ?? '') !== $old) {
                        RuleViolation::fail([$k => 'Il cespite ha già ammortamenti o movimenti: valori, date e categoria si cambiano con un movimento (incremento, decremento, riclassificazione, rettifica).']);
                    }
                    if ((string) ($v[$k] ?? '') !== $old) {
                        $changed[$k] = [$old, (string) ($v[$k] ?? '')];
                    }
                }
                $a->fill([...$v, 'code' => $code ?: $a->code, 'version' => $a->version + 1])->save();
                $this->saveOpening($c, $actor, $a, $opening, $locked);
                Audit::record('asset.updated', $actor, $c, $a, data: ['code' => $a->code, 'changed' => $changed]);
            } else {
                Company::whereKey($c->id)->lockForUpdate()->first(); // numerazione progressiva per azienda
                $n = (int) Asset::where('company_id', $c->id)->max('number') + 1;
                $a = Asset::create([...$v, 'company_id' => $c->id, 'number' => $n, 'code' => $code ?: sprintf('CSP-%05d', $n), 'status' => 'attivo', 'created_by' => $actor->id]);
                AssetMovement::create(['company_id' => $c->id, 'asset_id' => $a->id, 'type' => 'acquisizione', 'movement_date' => $a->purchase_date, 'year' => (int) $a->purchase_date->format('Y'),
                    'amount' => Decimal::fromMinor(AssetLedger::costCivil($a)), 'fiscal_amount' => Decimal::fromMinor(AssetLedger::costFiscal($a)), 'causale' => 'Acquisizione del cespite',
                    'invoice_id' => $a->invoice_id, 'created_by' => $actor->id]);
                $this->saveOpening($c, $actor, $a, $opening, false);
                Audit::record('asset.created', $actor, $c, $a, data: ['code' => $a->code, 'cost' => (string) $a->original_cost]);
            }

            return $a;
        });
    }

    /**
     * Progressivi nel modulo (decisione n. 81): cespite già ammortizzato prima di Semplicon (es. acquisto 2000, registrato 2026):
     * situazione del fondo al 31.12. dell'ultimo anno già ammortizzato, civilistico e fiscale. Dall'anno successivo Semplicon prosegue il calcolo.
     * false = campo non inviato (invariato), null = nessun progressivo.
     */
    private function openingInput(array $d, array $m, \Closure $date, array &$e): array|false|null
    {
        if (! array_key_exists('opening_enabled', $d)) {
            return false;
        }
        if (! filter_var($d['opening_enabled'], FILTER_VALIDATE_BOOL)) {
            return null;
        }
        $year = (int) ($d['opening_last_year'] ?? 0);
        $start = $date('depreciation_start') ?? $date('in_service_date') ?? $date('purchase_date');
        if ($year < 1900 || $year > (int) date('Y')) {
            $e['opening_last_year'] = 'Indica l’ultimo esercizio già ammortizzato (es. 2025).';
        } elseif ($start && $year < (int) substr($start, 0, 4)) {
            $e['opening_last_year'] = 'L’ultimo esercizio ammortizzato non può precedere l’inizio dell’ammortamento.';
        }
        $amt = function (string $k, bool $required) use ($d, &$e): ?int {
            $v = trim(str_replace(',', '.', (string) ($d[$k] ?? '')));
            if ($v === '') {
                $required && $e[$k] = 'Indica il fondo ammortamento al 31/12.';

                return null;
            }
            if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v)) {
                $e[$k] = 'Importo non valido (es. 1250,00).';

                return null;
            }

            return Decimal::toMinor($v);
        };
        $fc = $amt('opening_fund_civil', true);
        $ff = $amt('opening_fund_fiscal', false) ?? $fc;
        $cost = Decimal::toMinor($m['original_cost'] ?? '0.00') + Decimal::toMinor($m['accessory_costs'] ?? '0.00') + Decimal::toMinor($m['nondeductible_vat'] ?? '0.00');
        if ($fc !== null && $fc > $cost) {
            $e['opening_fund_civil'] = 'Il fondo non può superare il costo del cespite.';
        }
        $costF = ($m['fiscal_cost'] ?? null) !== null ? Decimal::toMinor($m['fiscal_cost']) : $cost;
        if ($ff !== null && $ff > $costF) {
            $e['opening_fund_fiscal'] = 'Il fondo fiscale non può superare il costo fiscale.';
        }

        return ['last_year' => $year, 'fund_civil' => $fc ?? 0, 'fund_fiscal' => $ff ?? 0];
    }

    private function saveOpening(Company $c, User $actor, Asset $a, array|false|null $o, bool $locked): void
    {
        if ($o === false) {
            return;
        }
        $cur = $a->opening()->first();
        if ($cur?->mode === 'storico') {
            return; // Storico dall'importazione: quote per anno, qui non modificabili
        }
        $same = $cur && $o && (int) $cur->last_year === $o['last_year'] && AssetLedger::m($cur->fund_civil) === $o['fund_civil'] && AssetLedger::m($cur->fund_fiscal) === $o['fund_fiscal'];
        if ($same || (! $cur && ! $o)) {
            return;
        }
        if ($locked) {
            RuleViolation::fail(['opening_fund_civil' => 'Il cespite ha già ammortamenti o movimenti in Semplicon: per correggere i progressivi storna prima i calcoli.']);
        }
        if (! $o) {
            $cur->delete();
            Audit::record('asset.opening_removed', $actor, $c, $a);

            return;
        }
        AssetOpeningBalance::updateOrCreate(['asset_id' => $a->id], ['mode' => 'saldi', 'last_year' => $o['last_year'], 'fund_civil' => Decimal::fromMinor($o['fund_civil']),
            'fund_fiscal' => Decimal::fromMinor($o['fund_fiscal']), 'increments' => '0.00', 'decrements' => '0.00', 'revaluations' => '0.00', 'created_by' => $cur->created_by ?? $actor->id]);
        $a->unsetRelation('opening');
        Audit::record('asset.opening_saved', $actor, $c, $a, data: ['ultimo_esercizio' => $o['last_year'], 'fondo_civilistico' => Decimal::fromMinor($o['fund_civil']), 'fondo_fiscale' => Decimal::fromMinor($o['fund_fiscal'])]);
    }

    public function duplicate(Company $c, User $actor, Asset $a): Asset
    {
        $d = $a->only(['description', 'description_long', 'asset_category_id', 'subcategory', 'location', 'cost_center', 'supplier_name', 'partner_id', 'notes', 'grant_treatment', 'method',
            'extra_deduction_note', 'low_value', ...array_map(fn ($r) => "account_{$r}_id", array_keys(self::ROLES))]);
        foreach (['original_cost', 'accessory_costs', 'nondeductible_vat', 'grants', 'residual_value', 'fiscal_cost', 'fiscal_cost_cap', 'reduced_pct', 'extra_deduction_pct', 'fiscal_deductible_pct'] as $k) {
            $d[$k] = $a->{$k} === null ? '' : (string) $a->{$k};
        }

        return $this->save($c, $actor, [...$d, 'description' => $a->description.' (copia)', 'purchase_date' => $a->purchase_date->format('Y-m-d'),
            'in_service_date' => $a->in_service_date?->format('Y-m-d'), 'first_year_half' => $a->first_year_half]);
    }

    public function exclude(Company $c, User $actor, Asset $a, bool $excluded, ?string $reason): void
    {
        if ($excluded && trim((string) $reason) === '') {
            RuleViolation::fail(['excluded_reason' => 'Indica il motivo dell’esclusione.']);
        }
        $a->update(['excluded' => $excluded, 'excluded_reason' => $excluded ? trim($reason) : null, 'version' => $a->version + 1]);
        app(AssetLedger::class)->refreshStatus($a);
        Audit::record($excluded ? 'asset.excluded' : 'asset.included', $actor, $c, $a, $reason);
    }

    public function attach(Company $c, User $actor, Asset $a, UploadedFile $file, ?string $label): AssetDocument
    {
        $doc = app(ManualInvoiceService::class)->storeAttachment($c, $actor, $file);
        $ad = AssetDocument::create(['asset_id' => $a->id, 'document_id' => $doc->id, 'label' => $label, 'created_by' => $actor->id]);
        Audit::record('asset.document_added', $actor, $c, $a, data: ['document' => $doc->original_name]);

        return $ad;
    }
}
