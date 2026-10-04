<?php

namespace Database\Factories;

use App\Enums\Ruolo;
use App\Models\Citta;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => fake()->firstName(),
            'cognome' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'citta_id' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Person belonging to the given city (a new one when omitted).
     */
    public function perCitta(Citta|int|null $citta = null): static
    {
        return $this->state(fn (array $attributes) => [
            'citta_id' => $citta instanceof Citta ? $citta->id : ($citta ?? Citta::factory()),
        ]);
    }

    /**
     * Give the person a role after creation.
     */
    public function conRuolo(Ruolo $ruolo): static
    {
        return $this->afterCreating(fn (User $utente) => $utente->assegnaRuolo($ruolo));
    }
}
