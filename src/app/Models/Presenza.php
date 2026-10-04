<?php

namespace App\Models;

use App\Concerns\AppartieneACitta;
use App\Scopes\SoloLineeVisibiliScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Presenza extends Model
{
    use AppartieneACitta, HasFactory;

    protected $table = 'presenze';

    protected $fillable = [
        'citta_id', 'data', 'linea_id', 'fermata_id', 'bambino_id',
        'presente', 'temporaneo', 'registrata_da',
    ];

    protected static function booted(): void
    {
        // Records of an archived line are hidden together with the line.
        static::addGlobalScope(new SoloLineeVisibiliScope);
    }

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'presente' => 'boolean',
            'temporaneo' => 'boolean',
        ];
    }

    /**
     * Record (or correct) a child's attendance on a line for a day.
     * Idempotent: repeating the call updates the same row instead of adding one.
     */
    public static function registra(
        Fermata $fermata,
        Bambino $bambino,
        CarbonInterface $data,
        bool $presente,
        bool $temporaneo = false,
        ?User $registrataDa = null,
    ): self {
        return static::query()->updateOrCreate(
            [
                'data' => $data->toDateString(),
                'linea_id' => $fermata->linea_id,
                'bambino_id' => $bambino->id,
            ],
            [
                'citta_id' => $fermata->citta_id,
                'fermata_id' => $fermata->id,
                'presente' => $presente,
                'temporaneo' => $temporaneo,
                'registrata_da' => $registrataDa?->id,
            ],
        );
    }

    /**
     * The chaperone who recorded the attendance.
     */
    public function registrataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrata_da');
    }

    public function fermata(): BelongsTo
    {
        return $this->belongsTo(Fermata::class, 'fermata_id');
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(Linea::class, 'linea_id');
    }

    public function bambino(): BelongsTo
    {
        return $this->belongsTo(Bambino::class, 'bambino_id');
    }
}
