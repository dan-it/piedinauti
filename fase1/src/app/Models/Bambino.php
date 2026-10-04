<?php

namespace App\Models;

use App\Concerns\AppartieneACitta;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bambino extends Model
{
    use AppartieneACitta, HasFactory;

    protected $table = 'bambini';

    protected $fillable = ['citta_id', 'nome', 'cognome'];

    protected function nomeCompleto(): Attribute
    {
        return Attribute::get(fn () => trim($this->nome.' '.$this->cognome));
    }

    /**
     * Stops the child is usually assigned to.
     */
    public function fermate(): BelongsToMany
    {
        return $this->belongsToMany(Fermata::class, 'fermata_bambino', 'bambino_id', 'fermata_id')
            ->withPivot('citta_id');
    }

    public function presenze(): HasMany
    {
        return $this->hasMany(Presenza::class, 'bambino_id');
    }
}
