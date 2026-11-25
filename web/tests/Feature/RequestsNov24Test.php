<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\Issuing\InvoiceIssuer;
use App\Domain\VatProfileService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Richieste del 24.11.2026: notifiche, account, utenti dell'amministratore, cliente privato, confronto del conto economico. */
class RequestsNov24Test extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_notifications_return_tasks_and_deadlines_only_for_members(): void
    {
        $owner = $this->customer();
        $c = $this->companyFor($owner);
        $this->actingAs($owner)->getJson("/aziende/{$c->id}/notifiche")->assertOk()->assertJsonStructure(['tasks', 'deadlines']);
        $this->actingAs($this->customer('Altro'))->getJson("/aziende/{$c->id}/notifiche")->assertNotFound();
    }

    public function test_user_changes_email_and_password_with_current_password(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->put('/account/accesso', ['email' => 'nuova@example.com', 'current_password' => 'sbagliata'])->assertSessionHasErrors('current_password');
        $this->actingAs($u)->put('/account/accesso', ['email' => 'nuova@example.com', 'current_password' => 'password', 'password' => 'una-password-lunga', 'password_confirmation' => 'una-password-lunga'])
            ->assertSessionHasNoErrors();
        $u->refresh();
        $this->assertSame('nuova@example.com', $u->email);
        $this->assertTrue(Hash::check('una-password-lunga', $u->password));
    }

    public function test_admin_creates_user_with_companies_and_customer_cannot(): void
    {
        $owner = $this->customer();
        $c = $this->companyFor($owner);
        $this->actingAs($owner)->post('/amministrazione/utenti', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'una-password-lunga'])->assertNotFound();

        $this->actingAs($this->admin())->post('/amministrazione/utenti', ['name' => 'Luca Bianchi', 'email' => 'Luca@Example.com', 'password' => 'una-password-lunga', 'companies' => [$c->id]])
            ->assertSessionHasNoErrors();
        $u = User::where('email', 'luca@example.com')->firstOrFail();
        $this->assertTrue($u->canAccess($c));
        $this->assertFalse($u->is_admin);

        $this->actingAs($this->admin())->put("/amministrazione/utenti/{$u->id}/aziende", ['companies' => []])->assertSessionHasNoErrors();
        $this->assertFalse($u->fresh()->canAccess($c));
    }

    public function test_private_customer_in_italy_needs_no_vat_number(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-11-20 10:00:00');
        $owner = $this->customer();
        $c = app(CompanyService::class)->create($owner, ['name' => 'Alpha S.r.l.', 'tipologia' => 'societa', 'partita_iva' => '01234567897']);
        $c->update(['indirizzo' => 'Via Roma', 'numero_civico' => '10', 'cap' => '20121', 'comune' => 'Milano', 'provincia' => 'MI']);
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($c, $owner, 2026);
        $svc->updateDraft($c, $d, $owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'mensile']);
        $svc->confirm($c, $d->fresh(), $owner);
        $acc = (int) $c->accounts()->where('type', 'ricavo')->where('iva_rilevante', true)->where('active', true)->orderByDesc('is_default')->value('id');
        $inv = app(InvoiceIssuer::class)->issue($c, $owner, [
            'doc_type' => 'TD01', 'doc_date' => '2026-11-18', 'regime' => 'RF01', 'account_id' => $acc, 'payment_mode' => 'MP05',
            // Una partita IVA errata viene ignorata per il privato italiano: basta il codice fiscale.
            'customer' => ['kind' => 'persona', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'vat' => '12345', 'cf' => 'RSSMRA80A01F205X', 'country' => 'IT', 'address' => 'Via Verdi 1', 'zip' => '40121', 'city' => 'Bologna', 'province' => 'BO'],
            'lines' => [['description' => 'Consulenza', 'quantity' => '1', 'unit' => '', 'price' => '100,00', 'rate' => '22', 'natura' => '']],
            'payments' => [['date' => '2026-12-18', 'amount' => '122,00']],
        ]);
        $this->assertNull($inv->customer_vat);
    }

    public function test_liquidations_list_shows_payment_state_and_passed_terms(): void
    {
        $owner = $this->customer();
        $c = $this->companyFor($owner);
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($c, $owner, 2026);
        $svc->updateDraft($c, $d, $owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'mensile']);
        $svc->confirm($c, $d->fresh(), $owner);
        $this->travelTo('2026-11-25 10:00:00');
        $this->actingAs($owner)->get("/aziende/{$c->id}/liquidazioni")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Liquidazioni/Index')->has('rows.0.pay_state')->has('rows.0.term_passed'));
    }

    public function test_income_statement_offers_previous_years_for_comparison(): void
    {
        $owner = $this->customer();
        $c = $this->companyFor($owner);
        $this->actingAs($owner)->get("/aziende/{$c->id}/conto-economico?confronto=2020")->assertOk()
            ->assertInertia(fn ($p) => $p->component('IncomeStatement/Show')->has('compareYears', 5)->where('compare', null));
    }
}
