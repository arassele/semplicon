<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\RegistrationService;
use App\Domain\VatProfileService;
use App\Models\Account;
use App\Models\AtecoCode;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FatturaBuilder;
use Tests\TestCase;

/** Impostazioni › Tabelle: Piano dei conti con impostazioni fiscali (per azienda), Parametri fiscali e Codici ATECO (globali, solo amministratore). */
class TablesTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->customer();
        $this->company = $this->companyFor($this->owner);
    }

    private function url(string $suffix = ''): string
    {
        return "/aziende/{$this->company->id}/tabelle/piano-dei-conti{$suffix}";
    }

    private function acc(string $code)
    {
        return $this->company->accounts()->where('code', $code)->firstOrFail();
    }

    private function payload(array $over = []): array
    {
        return [...['code' => '602060', 'name' => 'Formazione online', 'type' => 'costo', 'active' => true, 'voce_cee' => 'B7', 'categoria' => 'Servizi',
            'sezione' => 'servizi', 'iva_rilevante' => true, 'iva_detraibile_pct' => '', 'iva_nota' => 'Secondo operazione', 'deducibilita_pct' => '100', 'deducibilita_nota' => '100% se inerente',
            'irap_nota' => null, 'limite' => null, 'normativa' => null, 'note' => null], ...$over];
    }

    private function registerOn(string $code): void
    {
        Storage::fake('local');
        $this->travelTo('2026-11-18');
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($this->company, $this->owner, 2026);
        $svc->updateDraft($this->company, $d, $this->owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'mensile']);
        $svc->confirm($this->company, $d->fresh(), $this->owner);
        $batch = ImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'in_coda']);
        $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $this->company->id, 'filename' => 'f.xml', 'status' => 'in_attesa']);
        app(InvoiceImporter::class)->import($item, FatturaBuilder::make()->supplier('IT11111111115')->customer('IT'.$this->company->partita_iva)->body('A', '2026-03-01')->xml(), $this->owner->id);
        app(RegistrationService::class)->register($this->company, $this->company->invoices()->first(), $this->owner, [
            'receipt_date' => '2026-03-02', 'registration_date' => '2026-03-03', 'year' => 2026, 'period' => 3,
            'lines' => [['account_id' => $this->acc($code)->id, 'deductible_pct' => '100']], 'acknowledged' => [],
        ]);
    }

    public function test_new_company_gets_model_2026_with_fiscal_settings(): void
    {
        $this->actingAs($this->owner)->get($this->url())->assertOk()
            ->assertInertia(fn ($p) => $p->component('Tables/Accounts')->has('accounts', 185)->where('cee.B7', 'Per servizi')->where('sezioni.servizi', 'Servizi')
                ->where('models.0', ['key' => 'semplificata', 'label' => 'Contabilità semplificata 2026', 'available' => true, 'size' => 186])
                ->where('models.1', ['key' => 'professionisti', 'label' => 'Contabilità professionisti 2026 (quadro RE)', 'available' => true, 'size' => 86])
                ->where('models.2', ['key' => 'ordinaria', 'label' => 'Contabilità ordinaria 2026', 'available' => false, 'size' => 217])
                ->where('currentModel', 'semplificata'));
        $car = $this->acc('601020');
        $this->assertSame(['acquisti', 'B6', 'Veicoli', true, '40.00', '20.00', 'Art. 164 TUIR', 'modello'], [$car->sezione, $car->voce_cee, $car->categoria, $car->iva_rilevante, $car->iva_detraibile_pct, $car->deducibilita_pct, $car->normativa, $car->source]);
        $this->assertSame('40% in uso promiscuo', $car->iva_nota);
        $this->assertFalse($this->acc('604001')->iva_rilevante, 'Salari fuori campo IVA');
        $this->assertNull($this->acc('602001')->iva_detraibile_pct, 'Secondo operazione: nessuna percentuale proposta');
        $this->assertSame('ricavo', $this->acc('802001')->type);
        // Ditta individuale = persona fisica: nessun conto IRAP, IRAP non dovuta.
        $this->assertSame(0, $this->company->accounts()->where('code', '951002')->count());
        $this->assertSame('Non dovuta (persona fisica)', $car->irap_nota);
        $soc = $this->companyFor($this->owner, ['tipologia' => 'societa']);
        $irap = $soc->accounts()->where('code', '951002')->firstOrFail();
        $this->assertStringContainsString('società di persone', $irap->deducibilita_nota);
        $this->assertSame(186, $soc->accounts()->count());
        // Solo per la contabilità ordinaria: non nel modello della semplificata.
        foreach (['702001', '606001', '607001', '901001', '951001', '999001'] as $code) {
            $this->assertSame(0, $this->company->accounts()->where('code', $code)->count(), $code);
        }
        // Rappresentanza: IVA non detraibile, omaggi fino a 50 euro detraibili (art. 19-bis1 c.1 lett. h DPR 633/72).
        $this->assertSame('0.00', $this->acc('60201903')->iva_detraibile_pct);
        $this->assertSame('100.00', $this->acc('60201901')->iva_detraibile_pct);
        $this->assertStringContainsString('19-bis1', $this->acc('60201902')->normativa);
        $this->assertSame('costo', $this->acc('803001')->type);
        $this->assertSame(1, $this->company->accounts()->where('type', 'costo')->where('is_default', true)->count());
        $this->assertSame(1, $this->company->accounts()->where('type', 'ricavo')->where('is_default', true)->count());
    }

    public function test_account_can_be_added_with_fiscal_settings_and_edited(): void
    {
        $this->actingAs($this->owner)->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $a = $this->acc('602060');
        $this->assertSame(['manuale', null, '100.00', 'B7'], [$a->source, $a->iva_detraibile_pct, $a->deducibilita_pct, $a->voce_cee]);

        $this->actingAs($this->owner)->put($this->url("/{$a->id}"), $this->payload(['name' => 'Formazione e webinar', 'iva_detraibile_pct' => '50,5', 'limite' => 'Solo corsi inerenti']))->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertSame(['Formazione e webinar', '50.50', 'Solo corsi inerenti'], [$a->name, $a->iva_detraibile_pct, $a->limite]);
        $this->assertDatabaseHas('audit_events', ['action' => 'accounts.updated', 'company_id' => $this->company->id]);

        $this->actingAs($this->owner)->delete($this->url("/{$a->id}"))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->company->accounts()->where('code', '602060')->count());
    }

    public function test_account_validation(): void
    {
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['code' => '602001']))->assertSessionHasErrors('code');
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['code' => '60 20']))->assertSessionHasErrors('code');
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['type' => 'patrimonio']))->assertSessionHasErrors('type');
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['iva_detraibile_pct' => '101']))->assertSessionHasErrors('iva_detraibile_pct');
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['deducibilita_pct' => '12.345']))->assertSessionHasErrors('deducibilita_pct');
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['voce_cee' => 'Z9']))->assertSessionHasErrors('voce_cee');
    }

    public function test_default_account_rules(): void
    {
        $def = $this->acc('602026');
        $this->actingAs($this->owner)->put($this->url("/{$def->id}"), $this->payload(['code' => '602026', 'name' => $def->name, 'active' => false]))->assertSessionHasErrors('active');
        $this->actingAs($this->owner)->put($this->url("/{$def->id}"), $this->payload(['code' => '602026', 'name' => $def->name, 'iva_rilevante' => false]))->assertSessionHasErrors('active');
        $this->actingAs($this->owner)->delete($this->url("/{$def->id}"))->assertSessionHasErrors('account');

        $this->actingAs($this->owner)->post($this->url('/'.$this->acc('604001')->id.'/predefinito'))->assertSessionHasErrors('account');
        $this->actingAs($this->owner)->post($this->url('/'.$this->acc('602001')->id.'/predefinito'))->assertSessionHasNoErrors();
        $this->assertSame(['602001', '701003'], $this->company->accounts()->where('is_default', true)->orderBy('code')->pluck('code')->all());
    }

    public function test_used_account_cannot_be_deleted_or_change_type(): void
    {
        $this->registerOn('602023');
        $a = $this->acc('602023');
        $this->actingAs($this->owner)->delete($this->url("/{$a->id}"))->assertSessionHasErrors('account');
        $this->actingAs($this->owner)->put($this->url("/{$a->id}"), $this->payload(['code' => '602023', 'type' => 'ricavo']))->assertSessionHasErrors('type');
        $this->actingAs($this->owner)->put($this->url("/{$a->id}"), $this->payload(['code' => '602023', 'name' => 'Hosting', 'active' => false]))->assertSessionHasNoErrors();
        $this->assertFalse($a->fresh()->active);
    }

    public function test_model_can_be_applied_to_an_existing_chart(): void
    {
        // Azienda con vecchio piano: un conto proprio utilizzato e uno inutilizzato, un conto del modello manca.
        $this->acc('602023')->update(['code' => '61.03']);
        $this->acc('609012')->delete();
        Account::create(['company_id' => $this->company->id, 'code' => '99.99', 'name' => 'Vecchio', 'type' => 'costo']);
        $this->registerOn('61.03');

        $this->actingAs($this->owner)->post($this->url('/modello'), ['remove_others' => true])->assertSessionHasNoErrors();
        $codes = $this->company->accounts()->pluck('code');
        $this->assertTrue($codes->contains('609012') && $codes->contains('602023'), 'conti del modello mancanti creati');
        $this->assertTrue($codes->contains('61.03'), 'il conto utilizzato resta');
        $this->assertFalse($codes->contains('99.99'), 'conto inutilizzato fuori dal modello rimosso');
        $this->assertSame(186, $codes->count());

        // Il modello dell'ordinaria è salvato, ma non ancora applicabile.
        $this->actingAs($this->owner)->post($this->url('/modello'), ['model' => 'ordinaria'])->assertSessionHasErrors('model');
        $this->actingAs($this->owner)->post($this->url('/modello'), ['model' => 'altro'])->assertStatus(422);
    }

    public function test_professional_gets_dedicated_model_for_quadro_re(): void
    {
        $pro = $this->companyFor($this->owner, ['tipologia' => 'professionista']);
        $acc = fn (string $code) => $pro->accounts()->where('code', $code)->firstOrFail();
        $this->assertSame(86, $pro->accounts()->count());
        $this->assertSame(['641009', '711001'], $pro->accounts()->where('is_default', true)->orderBy('code')->pluck('code')->all());
        $this->assertSame(0, $pro->accounts()->where('code', '602026')->count(), 'non è un conto della semplificata');
        $this->assertSame(['compensi', 'ricavo'], [$acc('711002')->sezione, $acc('711002')->type]);
        $this->assertSame(['0.00', 'Limite 1% dei compensi'], [$acc('637001')->iva_detraibile_pct, $acc('637001')->limite]);
        $this->assertSame(['40.00', '20.00'], [$acc('639001')->iva_detraibile_pct, $acc('639001')->deducibilita_pct]);
        $this->assertSame('75.00', $acc('636001')->deducibilita_pct);
        $this->assertStringContainsString('10.000', $acc('638001')->limite);
        $this->assertFalse($acc('643001')->iva_rilevante, 'contributi propri: fuori campo IVA e non nel quadro RE');
        $this->assertTrue($acc('711005')->iva_rilevante, 'spese anticipate art. 15 (N1) registrabili');
        // Indicazioni del committente 19.11.2026: contributo integrativo casse private e rimborsi analitici non sono reddito; IRAP non per le persone fisiche.
        $this->assertSame(['non_reddito', '0.00', true], [$acc('711003')->sezione, $acc('711003')->deducibilita_pct, $acc('711003')->iva_rilevante]);
        $this->assertSame(['non_reddito', '0.00'], [$acc('711004')->sezione, $acc('711004')->deducibilita_pct]);
        $this->assertSame(['compensi', null], [$acc('711002')->sezione, $acc('711002')->deducibilita_pct]);
        $this->assertSame(['fuori_re', '0.00'], [$acc('641010')->sezione, $acc('641010')->deducibilita_pct]);
        $this->assertSame(0, $pro->accounts()->where('name', 'IRAP')->count());
        $this->assertSame(0, $pro->accounts()->where('irap_nota', '!=', 'Non dovuta (persona fisica)')->count());

        $this->actingAs($this->owner)->get("/aziende/{$pro->id}/tabelle/piano-dei-conti")
            ->assertInertia(fn ($p) => $p->where('currentModel', 'professionisti')->has('accounts', 86)->where('sezioniGroups.Contabilità professionisti (quadro RE).0', 'compensi'));

        // Un'azienda con il piano della semplificata può aggiungere il modello RE.
        $this->actingAs($this->owner)->post($this->url('/modello'), ['model' => 'professionisti'])->assertSessionHasNoErrors();
        $this->assertSame(185 + 58, $this->company->accounts()->count());
    }

    public function test_fiscal_parameters_table(): void
    {
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/tabelle/parametri-fiscali")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Tables/FiscalParameters')->has('parameters', 10)->where('parameters.0.deducibilita', '20%'));
    }

    public function test_foreign_account_ids_and_foreign_company_are_rejected(): void
    {
        $other = $this->companyFor($this->customer('Altro'));
        $foreign = $other->accounts()->first();
        $this->actingAs($this->owner)->put($this->url("/{$foreign->id}"), $this->payload())->assertNotFound();
        $this->actingAs($this->owner)->delete($this->url("/{$foreign->id}"))->assertNotFound();
        $this->actingAs($this->owner)->post($this->url("/{$foreign->id}/predefinito"))->assertNotFound();

        $stranger = $this->customer('Estraneo');
        $this->actingAs($stranger)->get($this->url())->assertNotFound();
        $this->actingAs($stranger)->post($this->url(), $this->payload())->assertNotFound();
        $this->actingAs($stranger)->post($this->url('/modello'))->assertNotFound();
    }

    public function test_ateco_table_is_readable_by_customers_and_searchable(): void
    {
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/tabelle/ateco?q=commercialisti")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Tables/Ateco')->where('canEdit', false)->where('codes.data.0.code', '69.20.01')->where('total', 1290));
    }

    public function test_only_admin_can_change_ateco_codes(): void
    {
        $url = "/aziende/{$this->company->id}/tabelle/ateco";
        $this->actingAs($this->owner)->put("{$url}/69.20.01", ['title_it' => 'X', 'active' => false])->assertForbidden();
        $this->actingAs($this->owner)->post($url, ['code' => '99.99.99', 'title_it' => 'X'])->assertForbidden();

        $admin = $this->admin();
        $adminCompany = app(CompanyService::class)->create($admin, ['name' => 'Studio', 'tipologia' => 'societa', 'partita_iva' => '12312312312']);
        $aurl = "/aziende/{$adminCompany->id}/tabelle/ateco";
        $this->actingAs($admin)->put("{$aurl}/69.20.01", ['title_it' => 'Attività di commercialisti (rev.)', 'active' => true])->assertSessionHasNoErrors();
        $this->assertSame('Attività di commercialisti (rev.)', AtecoCode::find('69.20.01')->title_it);

        $this->actingAs($admin)->post($aurl, ['code' => '99.99.99', 'title_it' => 'Codice di prova'])->assertSessionHasNoErrors();
        $this->assertSame('manuale', AtecoCode::find('99.99.99')->source);
        $this->actingAs($admin)->post($aurl, ['code' => '9999', 'title_it' => 'X'])->assertSessionHasErrors('code');
        $this->actingAs($admin)->post($aurl, ['code' => '69.20.01', 'title_it' => 'X'])->assertSessionHasErrors('code');
    }

    public function test_deactivated_ateco_code_cannot_be_chosen(): void
    {
        AtecoCode::whereKey('62.10.00')->update(['active' => false]);
        $payload = [
            'tipologia' => 'societa', 'name' => 'Alfa', 'partita_iva' => $this->company->partita_iva,
            'contacts' => [], 'bank_accounts' => [], 'activities' => [['code' => '62.10.00', 'is_primary' => true]],
        ];
        $this->actingAs($this->owner)->put("/aziende/{$this->company->id}/dati-azienda", $payload)->assertSessionHasErrors('activities.0.code');
        $this->actingAs($this->owner)->getJson('/ateco?q=62.10')->assertJsonMissing(['code' => '62.10.00']);
    }
}
