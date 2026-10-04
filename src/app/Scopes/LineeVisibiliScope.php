<?php

namespace App\Scopes;

use App\Support\CittaCorrente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides archived lines from everybody who is restricted to a city. Global administrators
 * (and console commands, which are not restricted) still see them.
 */
class LineeVisibiliScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app(CittaCorrente::class)->limitato()) {
            $builder->whereNull($model->qualifyColumn('archiviata_il'));
        }
    }
}
