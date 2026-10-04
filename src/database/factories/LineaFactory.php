<?php

namespace Database\Factories;

use App\Models\Citta;
use App\Models\Linea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Linea>
 */
class LineaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'citta_id' => Citta::factory(),
            'nome' => 'Linea '.fake()->unique()->colorName(),
        ];
    }
}
