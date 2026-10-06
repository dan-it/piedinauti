<?php

namespace App\Models;

use App\Concerns\AppartieneACitta;
use App\Enums\Ruolo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use AppartieneACitta, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nome',
        'cognome',
        'email',
        'password',
        'citta_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Keep "name" in the serialized user: the starter kit's interface shows it.
     *
     * @var list<string>
     */
    protected $appends = ['name'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'citta_id' => 'integer',
        ];
    }

    /**
     * Full display name: first name followed by surname.
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim($this->nome.' '.$this->cognome));
    }

    // ------------------------------------------------------------ roles

    public function ruoliAssegnati(): HasMany
    {
        return $this->hasMany(RuoloUtente::class, 'user_id');
    }

    /**
     * @return Collection<int, Ruolo>
     */
    public function ruoli(): Collection
    {
        return $this->ruoliAssegnati->pluck('ruolo');
    }

    public function haRuolo(Ruolo $ruolo): bool
    {
        return $this->ruoli()->contains($ruolo);
    }

    /**
     * Give the person a role. Global administrators must have no city;
     * every other role requires one.
     */
    public function assegnaRuolo(Ruolo $ruolo): void
    {
        if ($ruolo->richiedeCitta() && $this->citta_id === null) {
            throw new InvalidArgumentException("Il ruolo {$ruolo->etichetta()} richiede una città.");
        }

        if (! $ruolo->richiedeCitta() && $this->citta_id !== null) {
            throw new InvalidArgumentException('Un amministratore globale non può appartenere a una città.');
        }

        RuoloUtente::query()->firstOrCreate(['user_id' => $this->id, 'ruolo' => $ruolo->value]);
        $this->unsetRelation('ruoliAssegnati');
    }

    /**
     * Take a role away, together with the assignments that depend on it:
     * a person who is no longer a manager leaves their lines, a person who
     * is no longer a chaperone leaves their stops.
     */
    public function rimuoviRuolo(Ruolo $ruolo): void
    {
        $this->ruoliAssegnati()->where('ruolo', $ruolo->value)->delete();
        $this->unsetRelation('ruoliAssegnati');

        match ($ruolo) {
            Ruolo::Responsabile => $this->lineeResponsabile()->detach(),
            Ruolo::Accompagnatore => $this->fermateAccompagnatore()->detach(),
            default => null,
        };
    }

    // ------------------------------------------------- authorization helpers

    public function eAdminGlobale(): bool
    {
        return $this->haRuolo(Ruolo::AdminGlobale);
    }

    public function eAdminCitta(): bool
    {
        return $this->haRuolo(Ruolo::AdminCitta);
    }

    /**
     * True when the person belongs to the given city (never true without a city).
     */
    public function appartieneACitta(?int $cittaId): bool
    {
        return $cittaId !== null && $this->citta_id === $cittaId;
    }

    public function eResponsabileDi(Linea $linea): bool
    {
        return $this->haRuolo(Ruolo::Responsabile)
            && $this->lineeResponsabile()->where('linee.id', $linea->id)->exists();
    }

    /**
     * A chaperone is assigned to the stop where they start and goes on with the group to the
     * end of the line: they are present at that stop and at every later one.
     */
    public function eAccompagnatoreDi(Fermata $fermata): bool
    {
        return $this->haRuolo(Ruolo::Accompagnatore)
            && $this->fermateAccompagnatore()
                ->where('fermate.linea_id', $fermata->linea_id)
                ->where('fermate.ordine', '<=', $fermata->ordine)
                ->exists();
    }

    /**
     * Whether the chaperone can work on a stop (look at it and mark attendance there): the stops where
     * they are present, plus - if the line allows it - the few stops just before their starting stop.
     *
     * The line's setting says how many: with 2, the two stops right before the starting stop. On those
     * stops the chaperone works exactly as on their own, under the same rules (the modification window,
     * working without signal, adding a child for the day).
     */
    public function puoOperareSu(Fermata $fermata): bool
    {
        if ($this->eAccompagnatoreDi($fermata)) {
            return true;
        }

        $quante = (int) ($fermata->linea?->fermate_precedenti_visibili ?? 0);

        if ($quante <= 0 || ! $this->haRuolo(Ruolo::Accompagnatore)) {
            return false;
        }

        $inizio = $this->fermateAccompagnatore()
            ->where('fermate.linea_id', $fermata->linea_id)
            ->orderBy('fermate.ordine')
            ->first();

        if ($inizio === null || $fermata->ordine >= $inizio->ordine) {
            return false;
        }

        // How many stops from this one up to the starting stop (not included).
        $distanza = Fermata::query()
            ->where('linea_id', $fermata->linea_id)
            ->where('ordine', '>=', $fermata->ordine)
            ->where('ordine', '<', $inizio->ordine)
            ->count();

        return $distanza <= $quante;
    }

    /**
     * Every stop where the chaperone is present: from their starting stop to the end of each line.
     *
     * @return Builder<Fermata>
     */
    public function fermateCoperte(): Builder
    {
        return Fermata::query()->whereExists(function ($query) {
            $query->select(DB::raw(1))
                ->from('fermata_accompagnatore as fa')
                ->join('fermate as inizio', 'inizio.id', '=', 'fa.fermata_id')
                ->where('fa.user_id', $this->id)
                ->whereColumn('inizio.linea_id', 'fermate.linea_id')
                ->whereColumn('inizio.ordine', '<=', 'fermate.ordine');
        });
    }

    /**
     * True when the person accompanies at least one stop of the line.
     */
    public function eAccompagnatoreSu(Linea $linea): bool
    {
        return $this->haRuolo(Ruolo::Accompagnatore)
            && $this->fermateAccompagnatore()->where('fermate.linea_id', $linea->id)->exists();
    }

    // ------------------------------------------------------ assignments

    /**
     * Lines this person is in charge of.
     */
    public function lineeResponsabile(): BelongsToMany
    {
        return $this->belongsToMany(Linea::class, 'linea_responsabile', 'user_id', 'linea_id')
            ->withPivot('citta_id');
    }

    /**
     * The stops where this person starts accompanying the children (one per line).
     * Use fermateCoperte() for every stop where they are present.
     */
    public function fermateAccompagnatore(): BelongsToMany
    {
        return $this->belongsToMany(Fermata::class, 'fermata_accompagnatore', 'user_id', 'fermata_id')
            ->withPivot('citta_id');
    }
}
