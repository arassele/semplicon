<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** I clienti si registrano da soli. Gli amministratori nascono solo dalla console. */
class RegisterController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ], [], ['name' => 'nome', 'password' => 'password']);

        $user = User::create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => $data['password'],
            'is_admin' => false,
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        Audit::record('auth.registered', $user);

        return redirect()->route('companies.index')->with('success', 'Account creato. Ora aggiungi la tua azienda.');
    }
}
