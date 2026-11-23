<?php

namespace Tests\Concerns;

use App\Domain\CompanyService;
use App\Models\Company;
use App\Models\User;
use App\Support\Validation\PartitaIva;

trait BuildsTenants
{
    protected function customer(string $name = 'Cliente'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    protected function admin(): User
    {
        return User::factory()->create(['name' => 'Admin', 'is_admin' => true]);
    }

    protected function companyFor(User $owner, array $attrs = []): Company
    {
        return app(CompanyService::class)->create($owner, [
            'name' => $attrs['name'] ?? fake()->company(),
            'partita_iva' => $attrs['partita_iva'] ?? PartitaIva::complete(fake()->unique()->numerify('##########')),
            'tipologia' => $attrs['tipologia'] ?? 'ditta_individuale',
        ]);
    }
}
