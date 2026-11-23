<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\Validation\PartitaIva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Dati azienda: anagrafica, recapiti, attività, conti bancari. Solo dati sintetici. */
class CompanyProfileTest extends TestCase
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

    private function payload(array $override = []): array
    {
        return [...[
            'tipologia' => 'professionista', 'name' => '', 'partita_iva' => $this->company->partita_iva,
            'codice_fiscale' => 'bnclcu85c02l378c', 'codice_sdi' => 'abc1234',
            'titolo' => 'Dott.', 'cognome' => 'Bianchi', 'nome' => 'Luca', 'sesso' => 'M', 'data_nascita' => '1985-03-02',
            'comune_nascita' => 'Trento', 'provincia_nascita' => 'tn',
            'indirizzo' => 'Via Roma', 'numero_civico' => '1', 'cap' => '38122', 'comune' => 'Trento', 'provincia' => 'tn',
            'contacts' => [
                ['type' => 'email', 'value' => 'luca@example.com', 'description' => null, 'is_default' => true],
                ['type' => 'pec', 'value' => 'luca@pec.example', 'description' => null, 'is_default' => false],
                ['type' => 'telefono', 'value' => '0461 000000', 'description' => 'Ufficio', 'is_default' => false],
            ],
            'activities' => [['code' => '69.20.01', 'description' => null, 'is_primary' => true]],
            'bank_accounts' => [['iban' => 'IT60 X054 2811 1010 0000 0123 456', 'bank_name' => 'Banca di esempio', 'description' => null, 'is_default' => true]],
        ], ...$override];
    }

    private function save(array $data)
    {
        return $this->actingAs($this->owner)->put("/aziende/{$this->company->id}/dati-azienda", $data);
    }

    public function test_page_renders_for_owner(): void
    {
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/dati-azienda")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Companies/Profile')->where('company.partita_iva', $this->company->partita_iva));
    }

    public function test_owner_saves_full_profile(): void
    {
        $this->save($this->payload())->assertSessionHasNoErrors();

        $c = $this->company->fresh();
        $this->assertSame('Bianchi Luca', $c->name, 'nome ricavato da cognome e nome');
        $this->assertSame('BNCLCU85C02L378C', $c->codice_fiscale);
        $this->assertSame('Attività di commercialisti', $c->activities()->first()->description, 'denominazione dall’elenco ISTAT');
        $this->assertSame('ABC1234', $c->codice_sdi);
        $this->assertSame('TN', $c->provincia);
        $this->assertSame(3, $c->contacts()->count());
        $this->assertSame('IT60X0542811101000000123456', $c->bankAccounts()->first()->iban);
        $this->assertSame(['abi' => '05428', 'cab' => '11101'], $c->bankAccounts()->first()->abiCab());
        $this->assertTrue($c->activities()->first()->is_primary);
        $this->assertDatabaseHas('audit_events', ['action' => 'company.updated', 'company_id' => $c->id]);
    }

    public function test_saving_replaces_lists(): void
    {
        $this->save($this->payload())->assertSessionHasNoErrors();
        $this->save($this->payload(['contacts' => [], 'bank_accounts' => []]))->assertSessionHasNoErrors();

        $this->assertSame(0, $this->company->contacts()->count());
        $this->assertSame(0, $this->company->bankAccounts()->count());
        $this->assertSame(1, $this->company->activities()->count());
    }

    public function test_person_needs_surname_and_name_company_needs_denominazione(): void
    {
        $this->save($this->payload(['cognome' => '', 'nome' => '']))->assertSessionHasErrors(['cognome', 'nome']);
        $this->save($this->payload(['tipologia' => 'societa', 'name' => '', 'cognome' => '', 'nome' => '']))->assertSessionHasErrors('name');
        $this->save($this->payload(['tipologia' => 'societa', 'name' => 'Alfa S.r.l.', 'cognome' => '', 'nome' => '']))->assertSessionHasNoErrors();
        $this->assertSame('Alfa S.r.l.', $this->company->fresh()->name);
    }

    public function test_partita_iva_check_digit_and_uniqueness(): void
    {
        $this->save($this->payload(['partita_iva' => '01234567890']))->assertSessionHasErrors('partita_iva');
        $other = $this->companyFor($this->customer('Altro'));
        $this->save($this->payload(['partita_iva' => $other->partita_iva]))->assertSessionHasErrors('partita_iva');
        $new = PartitaIva::complete('1112223334');
        $this->save($this->payload(['partita_iva' => $new]))->assertSessionHasNoErrors();
        $this->assertSame($new, $this->company->fresh()->partita_iva);
    }

    public function test_formats_are_validated(): void
    {
        $this->save($this->payload([
            'codice_fiscale' => 'ABC', 'codice_sdi' => '12', 'cap' => '391', 'provincia' => 'Bolzano', 'sesso' => 'X',
            'data_nascita' => '2999-01-01',
        ]))->assertSessionHasErrors(['codice_fiscale', 'codice_sdi', 'cap', 'provincia', 'sesso', 'data_nascita']);
    }

    public function test_iban_ateco_and_email_are_checked(): void
    {
        $this->save($this->payload(['bank_accounts' => [['iban' => 'IT61X0542811101000000123456', 'is_default' => true]]]))->assertSessionHasErrors('bank_accounts.0.iban');
        $this->save($this->payload(['activities' => [['code' => '6920', 'is_primary' => true]]]))->assertSessionHasErrors('activities.0.code');
        // ATECO 2007/2022, non più presenti in ATECO 2025:
        $this->save($this->payload(['activities' => [['code' => '69.20.11', 'is_primary' => true]]]))->assertSessionHasErrors('activities.0.code');
        $this->save($this->payload(['contacts' => [['type' => 'pec', 'value' => 'non-una-mail', 'is_default' => true]]]))->assertSessionHasErrors('contacts.0.value');
        $this->save($this->payload(['contacts' => [['type' => 'telefono', 'value' => '0471 123', 'is_default' => true]]]))->assertSessionHasNoErrors();
    }

    public function test_only_one_default_per_list(): void
    {
        $two = [['type' => 'email', 'value' => 'a@example.com', 'is_default' => true], ['type' => 'email', 'value' => 'b@example.com', 'is_default' => true]];
        $this->save($this->payload(['contacts' => $two]))->assertSessionHasErrors('contacts');
        $acts = [['code' => '69.20.01', 'is_primary' => true], ['code' => '62.10.00', 'is_primary' => true]];
        $this->save($this->payload(['activities' => $acts]))->assertSessionHasErrors('activities');
    }

    public function test_foreign_customer_gets_404_and_admin_may_edit(): void
    {
        $stranger = $this->customer('Estraneo');
        $this->actingAs($stranger)->get("/aziende/{$this->company->id}/dati-azienda")->assertNotFound();
        $this->actingAs($stranger)->put("/aziende/{$this->company->id}/dati-azienda", $this->payload())->assertNotFound();

        $admin = $this->admin();
        $this->actingAs($admin)->put("/aziende/{$this->company->id}/dati-azienda", $this->payload(['comune' => 'Rovereto']))->assertSessionHasNoErrors();
        $this->assertSame('Rovereto', $this->company->fresh()->comune);
        $this->assertDatabaseHas('audit_events', ['action' => 'company.updated', 'user_id' => $admin->id]);
    }

    public function test_codice_fiscale_check_character(): void
    {
        $this->save($this->payload(['codice_fiscale' => 'BNCLCU85C02L378D']))->assertSessionHasErrors('codice_fiscale');
        $this->save($this->payload(['codice_fiscale' => '01234567890']))->assertSessionHasErrors('codice_fiscale');
        $this->save($this->payload(['codice_fiscale' => '01234567897']))->assertSessionHasNoErrors();
    }

    public function test_ateco_search_endpoint(): void
    {
        $this->get('/ateco?q=commercialisti')->assertRedirect('/accedi');
        $this->actingAs($this->owner)->getJson('/ateco?q=commercialisti')->assertOk()
            ->assertJsonPath('items.0.code', '69.20.01')
            ->assertJsonPath('source', 'Fonte: ISTAT – Classificazione ATECO 2025 (CC BY 4.0)');
        $this->actingAs($this->owner)->getJson('/ateco?q=6920')->assertOk()->assertJsonCount(7, 'items');
        $this->actingAs($this->owner)->getJson('/ateco?q=esperti contabili')->assertJsonPath('items.0.code', '69.20.03');
        $this->actingAs($this->owner)->getJson('/ateco?q=6')->assertJsonCount(0, 'items');
    }

    public function test_create_requires_valid_partita_iva(): void
    {
        $this->actingAs($this->owner)->post('/aziende', ['name' => 'X', 'tipologia' => 'societa', 'partita_iva' => '12345678901'])->assertSessionHasErrors('partita_iva');
        $this->actingAs($this->owner)->post('/aziende', ['name' => 'X', 'tipologia' => 'societa', 'partita_iva' => PartitaIva::complete('5554443332')])
            ->assertRedirect();
    }
}
