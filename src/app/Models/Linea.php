<?php

namespace App\Models;

use App\Concerns\AppartieneACitta;
use App\Enums\Ruolo;
use App\Scopes\LineeVisibiliScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Linea extends Model
{
    use AppartieneACitta, HasFactory;

    protected $table = 'linee';

    protected $fillable = ['citta_id', 'nome', 'archiviata_il', 'fermate_precedenti_visibili'];

    protected static function booted(): void
    {
        // Archived lines are visible to global administrators only.
        static::addGlobalScope(new LineeVisibiliScope);
    }

    protected function casts(): array
    {
        return ['archiviata_il' => 'datetime', 'fermate_precedenti_visibili' => 'integer'];
    }

    public function eArchiviata(): bool
    {
        return $this->archiviata_il !== null;
    }

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

    /**
     * Number the stops 1, 2, 3... in order of time (ties keep creation order).
     *
     * (linea_id, ordine) is unique, so stops are first moved out of the way and then
     * numbered: renumbering in place would collide with the stop that still holds a number.
     */
    public function riordinaFermate(): void
    {
        DB::transaction(function () {
            // The destination is always the last stop, whatever its time; the others go by time.
            $ids = Fermata::query()
                ->where('linea_id', $this->id)
                ->orderBy('destinazione')
                ->orderBy('orario')
                ->orderBy('id')
                ->pluck('id');

            Fermata::query()->where('linea_id', $this->id)->update(['ordine' => DB::raw('ordine + 10000')]);

            foreach ($ids as $posizione => $id) {
                Fermata::query()->whereKey($id)->update(['ordine' => $posizione + 1]);
            }
        });
    }

    /**
     * Expected arrival of the line on the given day: the time of its last stop.
     * Null when the line has no stops.
     */
    public function arrivoPrevisto(CarbonInterface $data): ?CarbonImmutable
    {
        $ultima = $this->fermate()->reorder('ordine', 'desc')->first();

        if ($ultima === null) {
            return null;
        }

        return CarbonImmutable::parse($data->toDateString().' '.$ultima->orario, config('app.timezone'));
    }

    /**
     * The special last stop where nobody boards and the chaperones mark "arrived", if the line has one.
     */
    public function destinazione(): ?Fermata
    {
        return Fermata::query()->where('linea_id', $this->id)->where('destinazione', true)->first();
    }

    public function presenze(): HasMany
    {
        return $this->hasMany(Presenza::class, 'linea_id');
    }
}
