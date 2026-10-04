<?php

namespace App\Models;

use App\Concerns\AppartieneACitta;
use App\Enums\Ruolo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
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

    public function rimuoviRuolo(Ruolo $ruolo): void
    {
        $this->ruoliAssegnati()->where('ruolo', $ruolo->value)->delete();
        $this->unsetRelation('ruoliAssegnati');
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
     * Stops where this person accompanies the children.
     */
    public function fermateAccompagnatore(): BelongsToMany
    {
        return $this->belongsToMany(Fermata::class, 'fermata_accompagnatore', 'user_id', 'fermata_id')
            ->withPivot('citta_id');
    }
}
