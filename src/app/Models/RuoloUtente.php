<?php

namespace App\Models;

use App\Enums\Ruolo;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of utente_ruolo: a role held by a person.
 */
class RuoloUtente extends Model
{
    protected $table = 'utente_ruolo';

    // The table has a composite primary key (user_id, ruolo) and no timestamps.
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['user_id', 'ruolo'];

    protected function casts(): array
    {
        return ['ruolo' => Ruolo::class];
    }
}
