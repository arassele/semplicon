<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tentativo di registrazione salvato (requestId) per ripetizioni idempotenti, ticket 06. */
class RegistrationRequest extends Model
{
    protected $guarded = ['id'];
}
