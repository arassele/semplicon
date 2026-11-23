<?php

namespace Tests\Feature;

use App\Domain\Cespiti\AssetImportService;
use App\Domain\Cespiti\AssetLedger;
use App\Domain\Cespiti\AssetMovementService;
use App\Domain\Cespiti\AssetReportService;
use App\Domain\Cespiti\AssetService;
use App\Domain\Cespiti\DepreciationService;
use App\Domain\Cespiti\DepreciationTableImporter;
use App\Domain\Cespiti\FiscalYears;
use App\Domain\CompanyService;
use App\Domain\IncomeStatementService;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetOpeningBalance;
use App\Models\Company;
use App\Models\DepreciationRate;
use App\Models\DepreciationTable;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Modulo Cespiti: casi obbligatori della specifica (motore, esecuzioni, Prima nota, movimenti, chiusura d'anno). */
class CespitiTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2027-03-01 10:00:00');
        $this->owner = $this->customer();
        $this->company = app(CompanyService::class)->create($this->owner, ['name' => 'Alpha', 'tipologia' => 'societa', 'partita_iva' => '01234567897']);
    }

    private function dm(string $like, string $coef): DepreciationRate
    {
        return DepreciationRate::where('description', 'like', $like)->where('coefficient', $coef)->firstOrFail();
    }

    private function category(string $kind = 'materiale', string $rate = '20', array $extra = []): AssetCategory
    {
        $dm = $kind === 'materiale' && ! isset($extra['no_dm']) ? $this->dm('Macchine d%ufficio elettromeccaniche%', '20.00') : null;
        unset($extra['no_dm']);
        $text = $kind === 'immateriale' ? 'Software' : 'Macchine d’ufficio elettroniche';

        return app(AssetService::class)->saveCategory($this->company, $this->owner, ['code' => 'C'.random_int(100, 999999), 'name' => $text, 'kind' => $kind, 'depreciation_rate_id' => $dm?->id,
            'fiscal_rate' => $rate, 'civil_rate' => $rate, ...AssetService::suggestAccounts($this->company, $text, $kind), ...$extra]);
    }

    private function asset(AssetCategory $cat, string $cost = '10000.00', string $date = '2026-03-10', array $extra = []): Asset
    {
        return app(AssetService::class)->save($this->company, $this->owner, ['asset_category_id' => $cat->id, 'description' => 'Bene di prova', 'purchase_date' => $date, 'in_service_date' => $date,
            'original_cost' => $cost, ...$extra]);
    }

    private function row(Asset $a, int $year): ?array
    {
        return AssetLedger::rowFor($a->fresh(), $year);
    }

    private function eur(int $c): string
    {
        return number_format($c / 100, 2, '.', '');
    }

    private function runYear(int $year, bool $post = true): void
    {
        $svc = app(DepreciationService::class);
        $run = $svc->calculate($this->company, $this->owner, $year);
        if ($post) {
            $svc->post($this->company, $this->owner, $run);
        }
    }

    /** 1 + 3: Materiale, primo anno fiscalmente metà aliquota (10.000 × 20 % = 2.000 civilistico, 1.000 fiscale), poi ordinaria. */
    public function test_material_first_year_half_fiscal_and_ordinary_quota(): void
    {
        $a = $this->asset($this->category());
        $r = $this->row($a, 2026);
        $this->assertSame('2000.00', $this->eur($r['civil_amount']));
        $this->assertSame('1000.00', $this->eur($r['fiscal_limit']));
        $this->assertSame('1000.00', $this->eur($r['fiscal_amount']));
        $this->assertSame('1000.00', $this->eur($r['nondeductible']));
        $this->runYear(2026);
        $r2 = $this->row($a, 2027);
        $this->assertSame('2000.00', $this->eur($r2['civil_amount']));
        $this->assertSame('2000.00', $this->eur($r2['fiscal_amount']));
        $this->assertSame('4000.00', $this->eur($r2['fund_civil_end']));
        $this->assertSame('3000.00', $this->eur($r2['fund_fiscal_end']));
    }

    /** 2: Immateriale senza dimezzamento automatico. */
    public function test_intangible_has_no_automatic_half_rate(): void
    {
        $a = $this->asset($this->category('immateriale', '20'));
        $r = $this->row($a, 2026);
        $this->assertSame('2000.00', $this->eur($r['civil_amount']));
        $this->assertSame('2000.00', $this->eur($r['fiscal_amount']));
        $this->assertSame('0.00', $this->eur($r['nondeductible']));
        $this->expectException(ValidationException::class);
        app(AssetService::class)->saveCategory($this->company, $this->owner, ['code' => 'X1', 'name' => 'Software', 'kind' => 'immateriale', 'depreciation_rate_id' => $this->dm('Macchine d%ufficio elettromeccaniche%', '20.00')->id, 'fiscal_rate' => '20']);
    }

    /** 4: Quota ridotta (50 % dell'ordinaria): civilistico 1.000, fiscale mai oltre il contabilizzato. */
    public function test_reduced_quota(): void
    {
        $a = $this->asset($this->category());
        $svc = app(DepreciationService::class);
        $opt = DepreciationService::options(['reduced_pct' => '50']);
        $r = collect($svc->simulate($this->company, 2026, $opt)['rows'])->firstWhere('asset.id', $a->id)['row'];
        $this->assertSame(['1000.00', '1000.00', 'ridotto'], [$this->eur($r['civil_amount']), $this->eur($r['fiscal_amount']), $r['method']]);
        $run = $svc->calculate($this->company, $this->owner, 2026, $opt);
        $this->assertSame('50.00', $run->options['reduced_pct']);
        $this->assertSame('1000.00', $this->eur($this->row($a, 2026)['civil_amount']));
        $this->assertSame('2000.00', $this->eur($this->row($a, 2027)['civil_amount'])); // solo l'anno dell'esecuzione
    }

    /** 5: Aliquota fiscale superiore al coefficiente: avviso, il limite resta al coefficiente del D.M. */
    public function test_civil_fiscal_difference_and_rate_above_limit(): void
    {
        $a = $this->asset($this->category('materiale', '30'));
        $r = $this->row($a, 2026);
        $this->assertSame('3000.00', $this->eur($r['civil_amount']));
        $this->assertSame('1000.00', $this->eur($r['fiscal_amount']));
        $this->assertSame('2000.00', $this->eur($r['nondeductible']));
        $this->assertNotEmpty($r['warnings']);
    }

    /** 6: Completamente ammortizzato: stato, nessuna ulteriore quota. */
    public function test_fully_depreciated(): void
    {
        $a = $this->asset($this->category('immateriale', '50'), '1000.00', '2025-01-10');
        $this->runYear(2025);
        $this->runYear(2026);
        $this->assertSame('ammortizzato', $a->fresh()->status);
        $this->assertNull($this->row($a, 2027));
    }

    /** 9: Incremento: quota futura sul nuovo valore. */
    public function test_increment_updates_future_plan(): void
    {
        $a = $this->asset($this->category('immateriale', '20'));
        $this->runYear(2026);
        app(AssetMovementService::class)->register($this->company, $this->owner, $a, ['type' => 'incremento', 'movement_date' => '2027-02-01', 'amount' => '5000,00', 'causale' => 'Upgrade']);
        $r = $this->row($a, 2027);
        $this->assertSame('15000.00', $this->eur($r['base_civil']));
        $this->assertSame('3000.00', $this->eur($r['civil_amount']));
    }

    /** 10: Modifica di aliquota solo per anni futuri. */
    public function test_future_rate_change_keeps_past(): void
    {
        $a = $this->asset($this->category('immateriale', '20'));
        $this->runYear(2026);
        app(AssetService::class)->changeAssetRates($this->company, $this->owner, $a, 2027, '25', '25', 'Nuova stima vita utile');
        $this->assertSame('2500.00', $this->eur($this->row($a, 2027)['civil_amount']));
        $this->assertSame('2000.00', $a->fresh()->depreciations()->where('year', 2026)->value('civil_amount'));
        $this->expectException(ValidationException::class);
        app(AssetService::class)->changeAssetRates($this->company, $this->owner, $a, 2026, '30', '30', 'retroattivo');
    }

    /** 11: Rivalutazione civilistica senza riconoscimento fiscale. */
    public function test_revaluation_not_fiscally_recognized(): void
    {
        $a = $this->asset($this->category('immateriale', '20'));
        $this->runYear(2026);
        app(AssetMovementService::class)->register($this->company, $this->owner, $a, ['type' => 'rivalutazione', 'movement_date' => '2027-01-15', 'amount' => '2000,00', 'causale' => 'Perizia', 'confirm' => true]);
        $r = $this->row($a, 2027);
        $this->assertSame('12000.00', $this->eur($r['base_civil']));
        $this->assertSame('10000.00', $this->eur($r['base_fiscal']));
        $this->assertSame('2400.00', $this->eur($r['civil_amount']));
        $this->assertSame('2000.00', $this->eur($r['fiscal_amount']));
    }

    private function saleSetup(): Asset
    {
        $this->company->forceFill(['asset_settings' => ['sale_year_quota' => false]])->save();
        $a = $this->asset($this->category('immateriale', '20'), '30000.00', '2020-01-10');
        AssetOpeningBalance::create(['asset_id' => $a->id, 'mode' => 'saldi', 'last_year' => 2026, 'fund_civil' => '24000.00', 'fund_fiscal' => '24000.00', 'created_by' => $this->owner->id]);

        return $a->fresh();
    }

    /** 12: Vendita con plusvalenza 3.000; registrazione: Fondo 24.000 + Transitorio 9.000 = Costo 30.000 + Plus 3.000. */
    public function test_sale_with_gain_and_journal(): void
    {
        $a = $this->saleSetup();
        $m = app(AssetMovementService::class)->register($this->company, $this->owner, $a, ['type' => 'alienazione_totale', 'movement_date' => '2027-02-10', 'price' => '9000,00', 'causale' => 'Vendita', 'confirm' => true]);
        $this->assertSame('6000.00', (string) $m->book_value);
        $this->assertSame('3000.00', (string) $m->gain_civil);
        $this->assertSame('venduto', $a->fresh()->status);
        $lines = JournalLine::where('journal_entry_id', $m->journal_entry_id)->get();
        $this->assertSame($lines->sum(fn ($l) => (float) $l->dare), $lines->sum(fn ($l) => (float) $l->avere));
        $this->assertSame(33000.0, $lines->sum(fn ($l) => (float) $l->dare)); // Fondo 24.000 + Transitorio 9.000 = Costo 30.000 + Plus 3.000
        $this->expectException(ValidationException::class);
        app(AssetMovementService::class)->register($this->company, $this->owner, $a->fresh(), ['type' => 'alienazione_totale', 'movement_date' => '2027-02-11', 'price' => '1,00', 'causale' => 'di nuovo', 'confirm' => true]);
    }

    /** 13: Minusvalenza 2.000. */
    public function test_sale_with_loss(): void
    {
        $m = app(AssetMovementService::class)->register($this->company, $this->owner, $this->saleSetup(), ['type' => 'alienazione_totale', 'movement_date' => '2027-02-10', 'price' => '4000,00', 'causale' => 'Vendita', 'confirm' => true]);
        $this->assertSame('-2000.00', (string) $m->gain_civil);
    }

    /** 14: Eliminazione: valore residuo come perdita. */
    public function test_elimination_with_residual(): void
    {
        $m = app(AssetMovementService::class)->register($this->company, $this->owner, $this->saleSetup(), ['type' => 'eliminazione', 'movement_date' => '2027-02-10', 'causale' => 'Rottamazione', 'confirm' => true]);
        $this->assertSame('-6000.00', (string) $m->gain_civil);
        $this->assertSame('eliminato', Asset::find($m->asset_id)->status);
    }

    /** 15: Vendita parziale 40 %: costi e fondi proporzionali, il resto continua a essere ammortizzato. */
    public function test_partial_alienation(): void
    {
        $a = $this->saleSetup();
        $m = app(AssetMovementService::class)->register($this->company, $this->owner, $a, ['type' => 'alienazione_parziale', 'movement_date' => '2027-02-10', 'share_pct' => '40', 'price' => '3000,00', 'causale' => 'Vendita parziale', 'confirm' => true]);
        $this->assertSame('12000.00', (string) $m->cost_civil);
        $this->assertSame('9600.00', (string) $m->fund_civil);
        $this->assertSame('600.00', (string) $m->gain_civil);
        $r = $this->row($a, 2027);
        $this->assertSame('18000.00', $this->eur($r['base_civil']));
        $this->assertSame('14400.00', $this->eur($r['fund_civil_start']));
        $this->assertSame('3600.00', $this->eur($r['civil_amount']));
        $this->assertSame('attivo', $a->fresh()->status);
    }

    /** 16 + 17: Prima nota quadra, conto Fondo = fondi dei Cespiti; seconda contabilizzazione bloccata. */
    public function test_journal_balances_and_no_double_posting(): void
    {
        $cat = $this->category();
        $this->asset($cat);
        $this->asset($cat, '5000.00');
        $svc = app(DepreciationService::class);
        $run = $svc->calculate($this->company, $this->owner, 2026);
        $this->assertSame('3000.00', (string) $run->total_civil);
        $svc->post($this->company, $this->owner, $run);
        $e = JournalEntry::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('3000.00', number_format((float) $e->lines()->sum('dare'), 2, '.', ''));
        $this->assertSame(number_format((float) $e->lines()->sum('dare'), 2), number_format((float) $e->lines()->sum('avere'), 2));
        $this->assertSame('3000.00', number_format((float) JournalLine::where('account_id', $cat->account_fund_id)->sum('avere'), 2, '.', ''));
        try {
            $svc->post($this->company, $this->owner, $run->fresh());
            $this->fail('doppia contabilizzazione');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        $svc->calculate($this->company, $this->owner, 2026);
    }

    /** 17b: Storno dell'esecuzione, nuovo calcolo possibile, movimento «ammortamento» stornato. */
    public function test_reverse_run_and_recalculate(): void
    {
        $a = $this->asset($this->category());
        $svc = app(DepreciationService::class);
        $run = $svc->post($this->company, $this->owner, $svc->calculate($this->company, $this->owner, 2026));
        $svc->reverse($this->company, $this->owner, $run, 'Aliquota errata');
        $this->assertSame('stornato', $run->fresh()->status);
        $this->assertSame(2, JournalEntry::where('company_id', $this->company->id)->count());
        $this->assertSame(0, $a->movements()->where('type', 'ammortamento')->where('status', 'registrato')->count());
        $run2 = $svc->calculate($this->company, $this->owner, 2026);
        $this->assertSame('calcolato', $run2->status);
    }

    /** 18: Esercizio chiuso: nessuna esecuzione, nessun movimento; dopo la riapertura con motivazione di nuovo possibile. */
    public function test_closed_year_blocks_recalculation_and_movements(): void
    {
        $a = $this->asset($this->category());
        $this->runYear(2026);
        FiscalYears::close($this->company, $this->owner, 2026);
        try {
            app(DepreciationService::class)->calculate($this->company, $this->owner, 2026);
            $this->fail('closed');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('chiuso', json_encode($e->errors()));
        }
        try {
            app(AssetMovementService::class)->register($this->company, $this->owner, $a, ['type' => 'trasferimento', 'movement_date' => '2026-12-01', 'cost_center' => 'Magazzino', 'causale' => 'x']);
            $this->fail('closed movement');
        } catch (ValidationException) {
        }
        FiscalYears::reopen($this->company, $this->owner, 2026, 'Correzione');
        $this->assertFalse(FiscalYears::isClosed($this->company, 2026));
    }

    /** Arrotondamenti: 333,33 × 15 % = 50,00 (49,9995), aliquota dimezzata 25,00; fine del piano senza superamento. */
    public function test_rounding_and_plan_never_exceeds_cost(): void
    {
        $a = $this->asset($this->category('materiale', '15', ['no_dm' => true]), '333.33');
        $r = $this->row($a, 2026);
        $this->assertSame('50.00', $this->eur($r['civil_amount']));
        $this->assertSame('25.00', $this->eur($r['fiscal_amount']));
        $plan = AssetLedger::plan($a->fresh());
        $last = end($plan);
        $this->assertSame('333.33', $this->eur($last['fund_civil_end']));
        $this->assertSame('333.33', $this->eur($last['fund_fiscal_end']));
        $this->assertLessThanOrEqual(33333, max(array_column($plan, 'fund_civil_end')));
    }

    /** Estensione: bene di modesto valore (art. 102 c. 5) e deducibilità limitata (tetto di costo, %). */
    public function test_low_value_and_limited_deductibility(): void
    {
        $a = $this->asset($this->category(), '400.00', extra: ['low_value' => true]);
        $r = $this->row($a, 2026);
        $this->assertSame('80.00', $this->eur($r['civil_amount']));
        $this->assertSame('400.00', $this->eur($r['fiscal_amount']));
        $car = $this->asset($this->category('materiale', '25', ['no_dm' => true]), '30000.00', extra: ['fiscal_cost_cap' => '18075.99', 'fiscal_deductible_pct' => '20']);
        $r = $this->row($car, 2026);
        $this->assertSame('7500.00', $this->eur($r['civil_amount']));
        $this->assertSame('451.90', $this->eur($r['fiscal_amount'])); // 18.075,99 × 25 % / 2 = 2.259,50 × 20 %
    }

    /** 7: Importazione modalità A (saldi): 50.000, Fondo 30.000 al 31/12/2025, 20 % → 2026 quota 10.000, nessuna registrazione. */
    public function test_import_partially_depreciated_asset_opening_balances(): void
    {
        $cat = $this->category('immateriale', '20');
        $csv = "codice;descrizione;categoria;data_acquisto;costo_storico;aliquota_civilistica;fondo_civilistico;ultimo_esercizio;residuo\nM-001;Tornio;{$cat->code};15/03/2022;50.000,00;20;30.000,00;2025;20.000,00\n";
        $svc = app(AssetImportService::class);
        $job = $svc->preview($this->company, $this->owner, 'saldi.csv', $csv, 'saldi');
        $this->assertSame(0, $job->report['error_count']);
        $svc->import($this->company, $this->owner, $job);
        $a = Asset::where('code', 'M-001')->firstOrFail();
        $r = $this->row($a, 2026);
        $this->assertSame('30000.00', $this->eur($r['fund_civil_start']));
        $this->assertSame('10000.00', $this->eur($r['civil_amount']));
        $this->assertSame(0, JournalEntry::where('company_id', $this->company->id)->count());
        $this->assertCount(2, collect(AssetLedger::plan($a))->where('year', '>=', 2026)->all()); // residuo 20.000 in due anni
    }

    /** 8: Importazione modalità B (storico annuale): quote per anno come righe fisse, piano dall'anno successivo. */
    public function test_import_full_annual_history(): void
    {
        $cat = $this->category('immateriale', '20');
        $csv = "codice;descrizione;categoria;data_acquisto;costo_storico;anno;aliquota_civilistica;quota_civilistica\nM-002;Furgone;{$cat->code};10/06/2023;30000,00;2023;20;6000,00\nM-002;Furgone;{$cat->code};10/06/2023;30000,00;2024;20;6000,00\nM-002;Furgone;{$cat->code};10/06/2023;30000,00;2025;20;6000,00\n";
        $svc = app(AssetImportService::class);
        $svc->import($this->company, $this->owner, $svc->preview($this->company, $this->owner, 'storico.csv', $csv, 'storico'));
        $a = Asset::where('code', 'M-002')->firstOrFail();
        $this->assertSame(3, $a->depreciations()->where('status', 'storico')->count());
        $r = $this->row($a, 2026);
        $this->assertSame('18000.00', $this->eur($r['fund_civil_start']));
        $this->assertSame('6000.00', $this->eur($r['civil_amount']));
    }

    /** 20: Importazione con errori e duplicati: verifica, blocco, solo righe valide. */
    public function test_import_with_errors_and_duplicates(): void
    {
        $cat = $this->category('immateriale', '20');
        $this->asset($cat, extra: ['code' => 'EX-1']);
        $csv = "codice;descrizione;categoria;data_acquisto;costo_storico;fondo_civilistico;ultimo_esercizio;residuo\n"
            ."A-1;Ok;{$cat->code};01/01/2024;1000,00;400,00;2025;600,00\nA-2;Doppio;{$cat->code};01/01/2024;1000,00;100,00;2025;900,00\nA-2;Doppio;{$cat->code};01/01/2024;1000,00;100,00;2025;900,00\n"
            ."EX-1;Esiste;{$cat->code};01/01/2024;1000,00;0;2025;1000,00\nA-3;Quadratura;{$cat->code};01/01/2024;1000,00;100,00;2025;800,00\nA-4;Categoria;NONE;31/02/2024;1000,00;0;2025;\n";
        $svc = app(AssetImportService::class);
        $job = $svc->preview($this->company, $this->owner, 'errori.csv', $csv, 'saldi');
        $text = json_encode($job->report['rows'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('duplicato', $text);
        $this->assertStringContainsString('già presente', $text);
        $this->assertStringContainsString('Quadratura', $text);
        $this->assertStringContainsString('inesistente', $text);
        $this->assertStringContainsString('Data non valida', $text);
        try {
            $svc->import($this->company, $this->owner, $job);
            $this->fail('errori');
        } catch (ValidationException) {
        }
        $svc->import($this->company, $this->owner, $job->fresh(), onlyValid: true);
        $this->assertSame(['A-1', 'EX-1'], Asset::where('company_id', $this->company->id)->orderBy('code')->pluck('code')->all());
        $this->assertSame('10000.00', (string) Asset::where('code', 'EX-1')->firstOrFail()->original_cost); // esistente non sovrascritto
    }

    /** 16 + 19: Registro dei beni ammortizzabili e riconciliazione dopo la contabilizzazione senza differenze; PDF e CSV. */
    public function test_register_print_and_reconciliation(): void
    {
        $cat = $this->category();
        $a = $this->asset($cat);
        $this->runYear(2026);
        $rep = app(AssetReportService::class);
        $r = $rep->build($this->company, 'registro', 2026);
        $this->assertSame($a->code, $r['rows'][0][0]);
        $this->assertSame('2000.00', $r['rows'][0][11]);
        $this->assertSame('8000.00', $r['rows'][0][14]);
        $rec = $rep->build($this->company, 'riconciliazione', 2026);
        $fund = collect($rec['rows'])->firstWhere(1, 'Fondo ammortamento');
        $this->assertSame('0.00', $fund[7]);
        $exp = collect($rec['rows'])->first(fn ($x) => str_starts_with($x[1], 'Ammortamenti'));
        $this->assertSame('0.00', $exp[7]);
        foreach (array_keys(AssetReportService::REPORTS) as $k) {
            $this->assertNotEmpty($rep->build($this->company, $k, 2026, ['asset' => $a->id])['columns']);
        }
        $base = "/aziende/{$this->company->id}/cespiti";
        $this->actingAs($this->owner)->get("{$base}/stampe/registro.pdf?anno=2026")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $csv = $this->actingAs($this->owner)->get("{$base}/stampe/registro.csv?anno=2026");
        $csv->assertOk();
        $this->assertStringContainsString('Registro', AssetReportService::REPORTS['registro']);
        $this->assertStringContainsString('2000,00', $csv->getContent());
    }

    /** Pagine e diritti: tutte le pagine si caricano, azienda altrui 404, creazione via HTTP. */
    public function test_pages_render_and_http_flow(): void
    {
        $cat = $this->category();
        $base = "/aziende/{$this->company->id}/cespiti";
        $this->actingAs($this->owner)->post($base, ['asset_category_id' => $cat->id, 'description' => 'PC', 'purchase_date' => '2026-02-01', 'in_service_date' => '2026-02-01', 'original_cost' => '1500,00'])->assertRedirect();
        $a = Asset::where('company_id', $this->company->id)->firstOrFail();
        foreach (['', '/nuovo', "/{$a->id}", "/{$a->id}/modifica", '/categorie?gruppo=1&specie=I', '/ammortamenti', '/movimenti', '/importazione', '/stampe', '/stampe?stampa=scheda&asset='.$a->id, '/prima-nota', '/impostazioni'] as $u) {
            $this->actingAs($this->owner)->get($base.$u)->assertOk();
        }
        $this->actingAs($this->owner)->postJson("{$base}/{$a->id}/movimenti/anteprima", ['type' => 'eliminazione', 'movement_date' => '2026-12-31', 'causale' => 'Prova'])->assertOk()->assertJsonStructure(['values', 'lines', 'warnings']);
        $this->actingAs($this->owner)->post("{$base}/ammortamenti/calcola?anno=2026")->assertRedirect();
        $this->actingAs($this->customer())->get($base)->assertNotFound();
    }

    /** D.M.: ultima sezione senza numero di gruppo (internamente 23) con Specie 1 Fiere e rassegne e 2 Altre attività; Gruppo XXII Specie II completo. */
    public function test_dm_table_contains_unnumbered_last_section(): void
    {
        $t = DepreciationTable::where('code', DepreciationTableImporter::DM1988)->firstOrFail();
        $q = fn () => DepreciationRate::where('depreciation_table_id', $t->id);
        $this->assertSame(1128, $q()->count());
        $this->assertSame(['1' => 'Fiere e rassegne', '2' => "Altre attivita'"], $q()->where('gruppo', 23)->pluck('specie_desc', 'specie')->all());
        $this->assertSame(8, $q()->where('gruppo', 23)->where('specie', '1')->count());
        $this->assertSame(15, $q()->where('gruppo', 23)->where('specie', '2')->count());
        $this->assertSame('27.00', (string) $q()->where('gruppo', 23)->where('specie', '1')->where('description', 'Arredamento fiere (transenne, ecc.)')->value('coefficient'));
        $this->assertSame('10.00', (string) $q()->where('gruppo', 23)->where('specie', '2')->where('description', 'Stigliatura')->value('coefficient'));
        $this->assertSame(8, $q()->where('gruppo', 22)->where('specie', 'II')->count());
        $this->assertFalse($q()->where('gruppo', 22)->where('description', 'like', '%Banconi blindati%')->exists());
        $this->assertFalse($q()->where('description', 'like', '%...%')->exists());
        $this->assertSame(15, $q()->where('gruppo', 18)->where('specie', 'VI, VII, VIII e IX')->count());
    }

    /** Correzione di una tabella già importata: gli ID restano, le righe assegnate in modo errato si spostano, le righe accorpate vengono meno. */
    public function test_dm_resync_keeps_ids_and_moves_misattributed_rows(): void
    {
        DepreciationTable::where('code', DepreciationTableImporter::DM1988)->delete();
        $old = tempnam(sys_get_temp_dir(), 'dm');
        file_put_contents($old, "gruppo;gruppo_descrizione;specie;specie_descrizione;descrizione;coefficiente;da_verificare\n"
            ."22;SERVIZI IGIENICI ALLA PERSONA E DOMESTICI;II;Imprese di smaltimento rifiuti;Edifici;3;\n"
            ."22;SERVIZI IGIENICI ALLA PERSONA E DOMESTICI;II;Imprese di smaltimento rifiuti;Impianti ... 15% Mobili ... 12%;10;\n"
            ."22;SERVIZI IGIENICI ALLA PERSONA E DOMESTICI;II;Imprese di smaltimento rifiuti;Arredamento fiere (transenne, ecc.);27;\n");
        $t = DepreciationTableImporter::import($old, ['code' => DepreciationTableImporter::DM1988, 'title' => 'DM', 'source' => 'test', 'version' => 'v1']);
        $ids = DepreciationRate::where('depreciation_table_id', $t->id)->orderBy('position')->pluck('id')->all();
        $blob = $this->category('materiale', '10', ['no_dm' => true, 'depreciation_rate_id' => $ids[1]]);
        $fiere = $this->category('materiale', '27', ['no_dm' => true, 'depreciation_rate_id' => $ids[2]]);

        $n = DepreciationTableImporter::resync($t, database_path('data/dm_1988_coefficienti.csv'));

        $this->assertSame(['kept' => 1, 'moved' => 1, 'added' => 1126, 'removed' => 1], $n);
        $this->assertSame(1128, DepreciationRate::where('depreciation_table_id', $t->id)->count());
        $r = DepreciationRate::findOrFail($ids[2]);
        $this->assertSame([23, '1'], [(int) $r->gruppo, $r->specie]);
        $this->assertSame($ids[2], $fiere->fresh()->depreciation_rate_id);
        $this->assertNull($blob->fresh()->depreciation_rate_id);
        $this->assertSame('10.00', (string) $blob->fresh()->civil_rate);
        $this->assertSame(hash_file('sha256', database_path('data/dm_1988_coefficienti.csv')), $t->fresh()->source_sha256);
        @unlink($old);
    }

    /** Quota ridotta sovrascrivibile per categoria (100 = ordinaria), gli immateriali mai ridotta; metodo «ridotto» non più selezionabile nel cespite. */
    public function test_reduced_quota_per_category_and_never_intangible(): void
    {
        $k1 = $this->category();
        $k2 = $this->category();
        $ki = $this->category('immateriale', '20');
        $a1 = $this->asset($k1);
        $a2 = $this->asset($k2);
        $ai = $this->asset($ki);
        $opt = DepreciationService::options(['reduced_pct' => '50', 'categories' => [$k2->id => '100']]);
        $rows = collect(app(DepreciationService::class)->simulate($this->company, 2026, $opt)['rows'])->keyBy('asset.id');
        $this->assertSame('1000.00', $this->eur($rows[$a1->id]['row']['civil_amount']));
        $this->assertSame('2000.00', $this->eur($rows[$a2->id]['row']['civil_amount']));
        $this->assertSame('2000.00', $this->eur($rows[$ai->id]['row']['civil_amount']));
        $this->expectException(ValidationException::class);
        $this->asset($k1, extra: ['method' => 'ridotto']);
    }

    /** Progressivi nel modulo: acquisto 2000, registrato in Semplicon nel 2026, Fondo al 31.12.2025; Semplicon prosegue il calcolo dal 2026. */
    public function test_manual_opening_balance_in_form(): void
    {
        $cat = $this->category('materiale', '10');
        $a = $this->asset($cat, '10000.00', '2000-03-01', ['opening_enabled' => true, 'opening_last_year' => '2025', 'opening_fund_civil' => '9500,00', 'opening_fund_fiscal' => '']);
        $o = $a->opening()->first();
        $this->assertSame(['saldi', 2025, '9500.00', '9500.00'], [$o->mode, (int) $o->last_year, (string) $o->fund_civil, (string) $o->fund_fiscal]);
        $r = $this->row($a, 2026);
        $this->assertSame(['500.00', '10000.00'], [$this->eur($r['civil_amount']), $this->eur($r['fund_civil_end'])]);
        $sim = app(DepreciationService::class)->simulate($this->company, 2026);
        $this->assertSame([], $sim['blocking']);
        $this->assertNull($this->row($a, 2027));

        // Fondo superiore al Costo → errore
        try {
            $this->asset($cat, '1000.00', '2010-01-01', ['opening_enabled' => true, 'opening_last_year' => '2025', 'opening_fund_civil' => '1200,00']);
            $this->fail('Fondo > costo accettato');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('opening_fund_civil', $e->errors());
        }
        // Bloccato dopo il calcolo
        $this->runYear(2026, false);
        $this->expectException(ValidationException::class);
        app(AssetService::class)->save($this->company, $this->owner, ['asset_category_id' => $cat->id, 'description' => 'Bene di prova', 'purchase_date' => '2000-03-01', 'in_service_date' => '2000-03-01',
            'original_cost' => '10000.00', 'opening_enabled' => true, 'opening_last_year' => '2025', 'opening_fund_civil' => '9000,00', 'version' => $a->fresh()->version], $a->fresh());
    }

    /** Iperammortamento: maggiorazione = quota deducibile × %; nel calcolo, stampa K, conto economico fiscale come variazione in diminuzione. */
    public function test_extra_deduction_calculated_and_in_income_statement(): void
    {
        $a = $this->asset($this->category(), '10000.00', '2026-03-10', ['extra_deduction_pct' => '150', 'extra_deduction_note' => 'Iperammortamento L. 232/2016']);
        $r = $this->row($a, 2026);
        $this->assertSame(['1000.00', '1500.00'], [$this->eur($r['fiscal_amount']), $this->eur($r['extra_deduction'])]);
        $this->runYear(2026);
        $k = app(AssetReportService::class)->build($this->company, 'maggiorazioni', 2026);
        $this->assertSame('1500.00', $k['rows'][0][5]);
        $this->assertSame('1500.00', $k['totals']['Maggiorazioni dell’esercizio (variazione in diminuzione)']);
        $ce = app(IncomeStatementService::class)->build($this->company, 2026, 'fiscale');
        $this->assertSame('1500.00', $ce['totals']['maggiorazioni']);
        $this->assertSame(Decimal::fromMinor(Decimal::toMinor($ce['totals']['risultato_fiscale']) - 150000), $ce['totals']['risultato_dopo_variazioni']);
        $this->assertSame('0.00', app(IncomeStatementService::class)->build($this->company, 2026, 'competenza')['totals']['maggiorazioni']);
        $this->assertSame(0, JournalLine::whereHas('entry', fn ($q) => $q->where('company_id', $this->company->id))->where('description', 'like', '%aggioraz%')->count());
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/cespiti/ammortamenti?esercizio=2027&ridotta=50")->assertOk();
    }
}
