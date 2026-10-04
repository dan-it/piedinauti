<?php

namespace App\Scopes;

use App\Support\CittaCorrente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query to the current city, when a restriction is active.
 */
class CittaScope implements Scope
{
    /**
     * @param  string  $colonna  Column holding the city id (the cities table uses "id").
     */
    public function __construct(private readonly string $colonna = 'citta_id') {}

    public function apply(Builder $builder, Model $model): void
    {
        $corrente = app(CittaCorrente::class);

        if (! $corrente->limitato()) {
            return;
        }

        if ($corrente->id() === null) {
            // Restricted to "no city": nothing can match.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn($this->colonna), $corrente->id());
    }
}
