<?php

namespace App\Domain;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/** Account e utenti (richieste del 24.11.2026): l'utente cambia e-mail e password; l'amministratore crea utenti e assegna le aziende. */
class UserAccountService
{
    /** @param array{email: string, current_password: string, password?: string|null} $d */
    public function updateAccount(User $user, array $d): array
    {
        $e = [];
        $email = mb_strtolower(trim($d['email']));
        if (! Hash::check($d['current_password'], $user->password)) {
            $e['current_password'] = 'La password attuale non è corretta.';
        }
        if (User::where('email', $email)->whereKeyNot($user->id)->exists()) {
            $e['email'] = 'Esiste già un account con questa e-mail.';
        }
        if ($e) {
            RuleViolation::fail($e);
        }
        $changed = [];
        if ($email !== $user->email) {
            $user->email = $email;
            $changed[] = 'e-mail';
        }
        if (! empty($d['password'])) {
            $user->password = $d['password'];
            $changed[] = 'password';
        }
        if (! $changed) {
            RuleViolation::fail(['email' => 'Nessuna modifica da salvare.']);
        }
        $user->save();
        Audit::record('auth.account_updated', $user, data: ['changed' => implode(', ', $changed)]);

        return $changed;
    }

    /** @param array{name: string, email: string, password: string, is_admin?: bool, companies?: list<int>} $d */
    public function createUser(User $admin, array $d): User
    {
        abort_unless($admin->is_admin, 404);
        $email = mb_strtolower(trim($d['email']));
        if (User::where('email', $email)->exists()) {
            RuleViolation::fail(['email' => 'Esiste già un account con questa e-mail.']);
        }
        $user = User::create(['name' => trim($d['name']), 'email' => $email, 'password' => $d['password'], 'is_admin' => (bool) ($d['is_admin'] ?? false)]);
        $ids = Company::whereIn('id', $d['companies'] ?? [])->pluck('id')->all();
        $user->companies()->sync($ids);
        Audit::record('admin.user_created', $admin, data: ['user' => $email, 'companies' => count($ids), 'admin' => $user->is_admin]);

        return $user;
    }

    /** @param list<int> $companyIds */
    public function setCompanies(User $admin, User $user, array $companyIds): void
    {
        abort_unless($admin->is_admin, 404);
        $ids = Company::whereIn('id', $companyIds)->pluck('id')->all();
        $user->companies()->sync($ids);
        Audit::record('admin.user_companies', $admin, data: ['user' => $user->email, 'companies' => count($ids)]);
    }
}
