<?php

namespace App\Rules;

use App\Models\User;
use App\Support\CittaCorrente;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An email address can belong to only one person in the whole service, in any city.
 *
 * The usual "unique" rule asks the database, and a city administrator's request only sees their own
 * city's people: it would miss an address already used elsewhere and let the save fail later with a
 * database error. This rule looks across all cities, without exposing anything about the other person.
 */
class EmailNonUsata implements ValidationRule
{
    /**
     * @param  int|null  $ignora  id of the person being edited, whose own address is not a clash
     */
    public function __construct(private readonly ?int $ignora = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $gia = app(CittaCorrente::class)->senzaLimiti(
            fn () => User::query()
                ->withoutGlobalScopes()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($value)])
                ->when($this->ignora !== null, fn ($query) => $query->whereKeyNot($this->ignora))
                ->exists()
        );

        if ($gia) {
            $fail('Questo indirizzo email è già usato da un\'altra persona.');
        }
    }
}
