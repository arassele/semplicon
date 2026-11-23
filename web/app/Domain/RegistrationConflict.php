<?php

namespace App\Domain;

use App\Models\VatRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Conflitto nella registrazione (ticket 06): fattura già registrata o stessa requestId con contenuto diverso.
 * Non viene registrato nulla; le richieste JSON ricevono 409 con la registrazione esistente, se nota.
 */
class RegistrationConflict extends RuntimeException
{
    public function __construct(string $message, public readonly ?VatRegistration $existing = null)
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            $e = $this->existing;

            return response()->json(['message' => $this->getMessage(), 'existing' => $e ? [
                'id' => $e->id, 'protocol' => "{$e->protocol}/{$e->year}",
                'url' => "/aziende/{$e->company_id}/fatture/{$e->invoice_id}",
            ] : null], 409);
        }

        return back()->withErrors(['registration' => $this->getMessage()]);
    }
}
