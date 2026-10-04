<?php

namespace Database\Factories;

use App\Models\Fermata;
use App\Models\Linea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fermata>
 */
class FermataFactory extends Factory
{
    public function definition(): array
    {
        return [
            'linea_id' => Linea::factory(),
            // The city always follows the line, so the two can never disagree.
            'citta_id' => fn (array $attributes) => Linea::withoutGlobalScopes()->find($attributes['linea_id'])->citta_id,
            'nome' => fake()->streetName(),
            'orario' => '07:45:00',
            'ordine' => fake()->unique()->numberBetween(1, 9999),
        ];
    }
}
