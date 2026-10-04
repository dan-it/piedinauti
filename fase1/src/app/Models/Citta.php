<?php

namespace App\Models;

use App\Scopes\CittaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Citta extends Model
{
    use HasFactory;

    protected $table = 'citta';

    protected $fillable = ['nome'];

    protected static function booted(): void
    {
        // A city is identified by its own id, not by a citta_id column.
        static::addGlobalScope(new CittaScope('id'));
    }

    public function utenti(): HasMany
    {
        return $this->hasMany(User::class, 'citta_id');
    }

    public function bambini(): HasMany
    {
        return $this->hasMany(Bambino::class, 'citta_id');
    }

    public function linee(): HasMany
    {
        return $this->hasMany(Linea::class, 'citta_id');
    }
}
