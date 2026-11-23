<?php

namespace App\Domain;

use Illuminate\Validation\ValidationException;

/** Violazione di una regola di dominio, viene mostrata come errore del modulo. */
final class RuleViolation
{
    /** @param array<string, string|array<int,string>> $messages */
    public static function fail(array $messages): never
    {
        throw ValidationException::withMessages($messages);
    }
}
