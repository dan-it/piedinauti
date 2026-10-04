<?php

namespace App\Concerns;

use App\Models\Citta;
use App\Scopes\CittaScope;
use App\Support\CittaCorrente;
use DomainException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models that carry a citta_id column: applies the city scope and keeps
 * writes inside the current city.
 */
trait AppartieneACitta
{
    public static function bootAppartieneACitta(): void
    {
        static::addGlobalScope(new CittaScope);

        static::creating(function ($model) {
            $corrente = app(CittaCorrente::class);

            if (! $corrente->limitato()) {
                return;
            }

            // New records inherit the current city; foreign cities are refused.
            if ($model->citta_id === null) {
                $model->citta_id = $corrente->id();
            } elseif ((int) $model->citta_id !== $corrente->id()) {
                throw new DomainException('Non si può creare un record in un\'altra città.');
            }
        });
    }

    public function citta(): BelongsTo
    {
        return $this->belongsTo(Citta::class, 'citta_id');
    }
}
