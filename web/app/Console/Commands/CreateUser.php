<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/** Crea un account. Gli amministratori nascono solo in questo modo. */
class CreateUser extends Command
{
    protected $signature = 'semplicon:user {email} {name} {--admin : Amministratore con accesso a tutte le aziende}';

    protected $description = 'Crea un account Semplicon (la password viene chiesta in modo nascosto)';

    public function handle(): int
    {
        $email = mb_strtolower($this->argument('email'));
        $password = $this->secret('Password (min. 12 caratteri)');

        $v = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', 'string', 'min:12']],
        );
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        User::create([
            'email' => $email,
            'name' => $this->argument('name'),
            'password' => $password,
            'is_admin' => (bool) $this->option('admin'),
        ]);
        $this->info("Account creato: {$email}");

        return self::SUCCESS;
    }
}
