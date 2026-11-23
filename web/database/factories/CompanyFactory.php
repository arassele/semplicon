<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\Validation\PartitaIva;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' S.r.l.',
            'partita_iva' => PartitaIva::complete(fake()->unique()->numerify('##########')),
            'codice_fiscale' => null,
            'created_by' => User::factory(),
        ];
    }
}
