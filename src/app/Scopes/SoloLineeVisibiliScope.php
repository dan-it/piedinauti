<?php

namespace App\Scopes;

use App\Models\Linea;
use App\Support\CittaCorrente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * For records that hang from a line (stops, attendance): when the line is hidden because it
 * is archived, its records are hidden too. The subquery uses Linea's own scopes, so the
 * rule lives in one place.
 */
class SoloLineeVisibiliScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! app(CittaCorrente::class)->limitato()) {
            return;
        }

        $builder->whereIn($model->qualifyColumn('linea_id'), Linea::query()->select('linee.id'));
    }
}
