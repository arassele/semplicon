<?php

namespace Tests\Feature;

use App\Domain\VatProfileService;
use App\Enums\ProfileStatus;
use App\Support\Validation\PartitaIva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Accettazione I00: dati di aziende altrui bloccati anche tramite ID manipolati; l'amministratore vede tutto. */
class TenancyTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_any_customer_can_create_own_company(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->post('/aziende', ['name' => 'Bottega Verde', 'tipologia' => 'ditta_individuale', 'partita_iva' => PartitaIva::complete('1234567890')])->assertRedirect();

        $company = $user->companies()->firstOrFail();
        $this->assertSame('Bottega Verde', $company->name);
        $this->assertTrue($user->canAccess($company));
    }

    public function test_company_list_only_shows_own_companies(): void
    {
        $a = $this->customer('A');
        $mine = $this->companyFor($a);
        $this->companyFor($this->customer('B'));

        $this->actingAs($a)->get('/aziende')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Companies/Index')
                ->has('items', 1)->where('items.0.id', $mine->id)->has('companies', 1));
    }

    public function test_foreign_company_pages_return_404(): void
    {
        $a = $this->customer('A');
        $foreign = $this->companyFor($this->customer('B'));

        foreach (['', '/profilo-iva'] as $path) {
            $this->actingAs($a)->get("/aziende/{$foreign->id}{$path}")->assertNotFound();
        }
    }

    public function test_foreign_company_writes_return_404_and_change_nothing(): void
    {
        $a = $this->customer('A');
        $b = $this->customer('B');
        $foreign = $this->companyFor($b);
        $draft = app(VatProfileService::class)->createDraft($foreign, $b, 2026);

        $this->actingAs($a);
        $this->post("/aziende/{$foreign->id}/profilo-iva/bozza", ['year' => 2026])->assertNotFound();
        $this->put("/aziende/{$foreign->id}/profilo-iva/{$draft->id}", ['periodicity' => 'mensile'])->assertNotFound();
        $this->post("/aziende/{$foreign->id}/profilo-iva/{$draft->id}/conferma")->assertNotFound();
        $this->delete("/aziende/{$foreign->id}/profilo-iva/{$draft->id}")->assertNotFound();

        $this->assertNull($draft->fresh()->periodicity);
        $this->assertSame(1, $foreign->vatProfiles()->count());
    }

    public function test_foreign_profile_id_under_own_company_url_returns_404(): void
    {
        $a = $this->customer('A');
        $b = $this->customer('B');
        $own = $this->companyFor($a);
        $foreign = $this->companyFor($b);
        $foreignDraft = app(VatProfileService::class)->createDraft($foreign, $b, 2026);

        $this->actingAs($a);
        $this->put("/aziende/{$own->id}/profilo-iva/{$foreignDraft->id}", ['periodicity' => 'mensile'])->assertNotFound();
        $this->post("/aziende/{$own->id}/profilo-iva/{$foreignDraft->id}/conferma")->assertNotFound();
        $this->delete("/aziende/{$own->id}/profilo-iva/{$foreignDraft->id}")->assertNotFound();

        $this->assertSame(ProfileStatus::Bozza, $foreignDraft->fresh()->status);
        $this->assertNull($foreignDraft->fresh()->periodicity);
    }

    public function test_non_numeric_ids_return_404(): void
    {
        $a = $this->customer();
        $this->actingAs($a)->get('/aziende/abc')->assertNotFound();
        $this->actingAs($a)->get('/aziende/1%20OR%201=1/profilo-iva')->assertNotFound();
    }

    public function test_admin_sees_and_edits_every_company(): void
    {
        $admin = $this->admin();
        $owner = $this->customer('Titolare');
        $c1 = $this->companyFor($owner);
        $this->companyFor($this->customer('Altro'));

        $this->actingAs($admin)->get('/aziende')->assertOk()
            ->assertInertia(fn ($p) => $p->has('items', 2)->where('isAdmin', true));
        $this->actingAs($admin)->get("/aziende/{$c1->id}/profilo-iva")->assertOk()
            ->assertInertia(fn ($p) => $p->where('currentCompany.admin_view', true));

        $this->actingAs($admin)->post("/aziende/{$c1->id}/profilo-iva/bozza", ['year' => 2026])->assertSessionHasNoErrors();
        $this->assertSame(1, $c1->vatProfiles()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'vat_profile.draft_created', 'user_id' => $admin->id, 'company_id' => $c1->id]);
    }

    public function test_admin_overview_is_only_for_admins(): void
    {
        $this->actingAs($this->customer())->get('/amministrazione')->assertNotFound();
        $this->actingAs($this->admin())->get('/amministrazione')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Index'));
    }
}
