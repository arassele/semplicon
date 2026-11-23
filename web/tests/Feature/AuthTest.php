<?php

namespace Tests\Feature;

use App\Domain\ImmutableRecordException;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/accedi');
        $this->get('/aziende')->assertRedirect('/accedi');
        $this->get('/accedi')->assertOk();
    }

    public function test_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create();

        $this->post('/accedi', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login', 'user_id' => $user->id]);

        $this->post('/esci')->assertRedirect('/accedi');
        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->post('/accedi', ['email' => $user->email, 'password' => 'falsch'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/accedi', ['email' => $user->email, 'password' => 'falsch']);
        }

        $this->post('/accedi', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Troppi tentativi', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_customer_can_register_and_is_not_admin(): void
    {
        $this->get('/registrati')->assertOk();
        $this->post('/registrati', [
            'name' => 'Lucia Verdi', 'email' => 'Lucia@Example.com',
            'password' => 'una-password-lunga', 'password_confirmation' => 'una-password-lunga',
            'is_admin' => true,
        ])->assertRedirect('/aziende');

        $user = User::where('email', 'lucia@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_admin, 'la registrazione non deve assegnare diritti di amministratore');
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.registered', 'user_id' => $user->id]);
    }

    public function test_registration_validates_password_and_unique_email(): void
    {
        User::factory()->create(['email' => 'esiste@example.com']);

        $this->post('/registrati', ['name' => 'X', 'email' => 'esiste@example.com', 'password' => 'corta', 'password_confirmation' => 'altra'])
            ->assertSessionHasErrors(['email', 'password']);
        $this->assertGuest();
    }

    public function test_audit_events_are_append_only(): void
    {
        $user = User::factory()->create();
        $this->post('/accedi', ['email' => $user->email, 'password' => 'password']);
        $event = AuditEvent::firstOrFail();

        $this->expectException(ImmutableRecordException::class);
        $event->update(['action' => 'manipuliert']);
    }
}
