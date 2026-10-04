<?php

namespace Database\Factories;

use App\Models\Citta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Citta>
 */
class CittaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nome' => fake()->unique()->city(),
        ];
    }
}
