<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
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
        ]);
    }
}
