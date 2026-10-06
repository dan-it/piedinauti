<?php

namespace App\Models;

use App\Concerns\AppartieneACitta;
use App\Scopes\SoloLineeVisibiliScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The time a line arrived at its destination on a day. One row per line and day.
 */
class Arrivo extends Model
{
    use AppartieneACitta;

    protected $table = 'arrivi';

    protected $fillable = ['citta_id', 'data', 'linea_id', 'arrivata_alle', 'registrata_da'];

    protected static function booted(): void
    {
        // An archived line is hidden together with its arrivals.
        static::addGlobalScope(new SoloLineeVisibiliScope);
    }

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'arrivata_alle' => 'datetime',
        ];
    }

    /**
     * Record the arrival of the line on the given moment's day. The earliest tap wins: a later tap
     * (another chaperone, or a tap sent late by a device that had no signal) changes nothing, while
     * an earlier one that arrives late replaces the later time.
     */
    public static function registra(Linea $linea, CarbonInterface $momento, ?User $registrataDa = null): self
    {
        $arrivo = static::query()->firstOrCreate(
            ['data' => $momento->toDateString(), 'linea_id' => $linea->id],
            ['citta_id' => $linea->citta_id, 'arrivata_alle' => $momento, 'registrata_da' => $registrataDa?->id],
        );

        if (! $arrivo->wasRecentlyCreated && $arrivo->arrivata_alle->gt($momento)) {
            $arrivo->update(['arrivata_alle' => $momento, 'registrata_da' => $registrataDa?->id]);
        }

        return $arrivo;
    }

    /**
     * Cancel the arrival recorded for the moment's day. A cancellation older than the arrival refers
     * to an earlier state of things (for example a phone that had no signal): the newer arrival stays.
     * Returns the arrival that remains, or null when there is none.
     */
    public static function annulla(Linea $linea, CarbonInterface $momento): ?self
    {
        $arrivo = static::query()
            ->where('data', $momento->toDateString())
            ->where('linea_id', $linea->id)
            ->first();

        if ($arrivo === null) {
            return null;
        }

        if ($arrivo->arrivata_alle->gt($momento)) {
            return $arrivo;
        }

        $arrivo->delete();

        return null;
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(Linea::class, 'linea_id');
    }

    public function registrataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrata_da');
    }
}
