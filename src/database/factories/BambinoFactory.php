<?php

namespace Database\Factories;

use App\Models\Bambino;
use App\Models\Citta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bambino>
 */
class BambinoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'citta_id' => Citta::factory(),
            'nome' => fake()->firstName(),
            'cognome' => fake()->lastName(),
        ];
    }
}
