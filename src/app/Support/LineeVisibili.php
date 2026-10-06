<?php

namespace App\Support;

use App\Models\Citta;
use App\Models\Linea;
use App\Models\Presenza;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The lines whose attendance a person may look at, in the screens that show it (the daily
 * dashboard and the reports): managers their own lines, city administrators every line of their
 * city, global administrators the lines of one city at a time.
 */
class LineeVisibili
{
    /**
     * Cities a global administrator can choose from (empty for everybody else).
     *
     * @return Collection<int, array{id: int, nome: string}>
     */
    public static function citte(User $utente): Collection
    {
        if (! $utente->eAdminGlobale()) {
            return collect();
        }

        return Citta::query()->orderBy('nome')->get(['id', 'nome'])
            ->map(fn (Citta $citta) => ['id' => $citta->id, 'nome' => $citta->nome])
            ->values();
    }

    /**
     * The city a global administrator is looking at: the requested one, or the first by name.
     * Null for everybody else, who always see their own.
     *
     * @param  Collection<int, array{id: int, nome: string}>  $citte
     */
    public static function cittaScelta(User $utente, ?int $richiesta, Collection $citte): ?int
    {
        if (! $utente->eAdminGlobale()) {
            return null;
        }

        return $richiesta ?? ($citte->first()['id'] ?? null);
    }

    /**
     * @param  list<string>  $con  relations to load with each line
     * @return Collection<int, Linea>
     */
    public static function linee(User $utente, ?int $cittaScelta, array $con = []): Collection
    {
        return Linea::query()
            ->with($con)
            // A global administrator sees one city at a time, without its archived lines.
            ->when($utente->eAdminGlobale(), fn ($query) => $query->where('citta_id', $cittaScelta)->whereNull('archiviata_il'))
            ->orderBy('nome')
            ->get()
            ->filter(fn (Linea $linea) => $utente->can('vedereLinea', [Presenza::class, $linea]))
            ->values();
    }
}
