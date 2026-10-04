<?php

namespace App\Models;

use App\Concerns\AppartieneACitta;
use App\Enums\Ruolo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;

class Fermata extends Model
{
    use AppartieneACitta, HasFactory;

    protected $table = 'fermate';

    protected $fillable = ['citta_id', 'linea_id', 'nome', 'orario', 'ordine'];

    protected function casts(): array
    {
        return ['ordine' => 'integer'];
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(Linea::class, 'linea_id');
    }

    public function accompagnatori(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'fermata_accompagnatore', 'fermata_id', 'user_id')
            ->withPivot('citta_id');
    }

    /**
     * Children usually assigned to this stop.
     */
    public function bambini(): BelongsToMany
    {
        return $this->belongsToMany(Bambino::class, 'fermata_bambino', 'fermata_id', 'bambino_id')
            ->withPivot('citta_id');
    }

    /**
     * Assign a chaperone to this stop. They must hold the "accompagnatore" role.
     */
    public function assegnaAccompagnatore(User $utente): void
    {
        if (! $utente->haRuolo(Ruolo::Accompagnatore)) {
            throw new InvalidArgumentException('La persona non ha il ruolo di accompagnatore.');
        }

        $this->accompagnatori()->syncWithoutDetaching([
            $utente->id => ['citta_id' => $this->citta_id],
        ]);
    }

    public function assegnaBambino(Bambino $bambino): void
    {
        $this->bambini()->syncWithoutDetaching([
            $bambino->id => ['citta_id' => $this->citta_id],
        ]);
    }
}
