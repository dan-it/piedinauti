<?php

namespace App\Models;

use App\Concerns\AppartieneACitta;
use App\Enums\Ruolo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class Linea extends Model
{
    use AppartieneACitta, HasFactory;

    protected $table = 'linee';

    protected $fillable = ['citta_id', 'nome'];

    /**
     * Stops in the order the line visits them.
     */
    public function fermate(): HasMany
    {
        return $this->hasMany(Fermata::class, 'linea_id')->orderBy('ordine');
    }

    public function responsabili(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'linea_responsabile', 'linea_id', 'user_id')
            ->withPivot('citta_id');
    }

    /**
     * Put a person in charge of this line. They must hold the "responsabile" role.
     */
    public function assegnaResponsabile(User $utente): void
    {
        if (! $utente->haRuolo(Ruolo::Responsabile)) {
            throw new InvalidArgumentException('La persona non ha il ruolo di responsabile.');
        }

        $this->responsabili()->syncWithoutDetaching([
            $utente->id => ['citta_id' => $this->citta_id],
        ]);
    }

    public function presenze(): HasMany
    {
        return $this->hasMany(Presenza::class, 'linea_id');
    }
}
