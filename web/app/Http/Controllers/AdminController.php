<?php

namespace App\Http\Controllers;

use App\Domain\UserAccountService;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Panoramica per l'amministratore: tutti i clienti e le loro aziende. */
class AdminController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->is_admin, 404);

        return Inertia::render('Admin/Index', [
            'users' => User::query()->with('companies:companies.id,companies.name')->orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'initials' => $u->initials(),
                    'is_admin' => $u->is_admin,
                    'created_at' => $u->created_at->toIso8601String(),
                    'companies' => $u->companies->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]),
                ]),
            'allCompanies' => Company::query()->orderBy('name')->get(['id', 'name', 'partita_iva']),
        ]);
    }

    /** Nuovo utente con aziende assegnate (richieste del 24.11.2026). */
    public function store(Request $request, UserAccountService $svc): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 404);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'], 'password' => ['required', Password::min(12)],
            'is_admin' => ['boolean'], 'companies' => ['array'], 'companies.*' => ['integer'],
        ], [], ['name' => 'nome', 'password' => 'password']);
        $svc->createUser($request->user(), $d);

        return back()->with('success', 'Utente creato.');
    }

    public function companies(Request $request, User $user, UserAccountService $svc): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 404);
        $d = $request->validate(['companies' => ['array'], 'companies.*' => ['integer']]);
        $svc->setCompanies($request->user(), $user, $d['companies'] ?? []);

        return back()->with('success', 'Aziende assegnate.');
    }
}
