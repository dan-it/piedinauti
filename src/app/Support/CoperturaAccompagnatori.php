<?php

namespace App\Support;

use App\Models\Fermata;

/**
 * Who is present at each stop of a line.
 *
 * A chaperone is assigned only to the stop where they start and stays with the group to the end
 * of the line, so they are present at that stop and every later one.
 */
class CoperturaAccompagnatori
{
    /**
     * @param  iterable<Fermata>  $fermate  the line's stops in order, with their chaperones loaded
     * @return array<int, list<array{id: int, nome: string, da: string|null}>> by stop id; "da" is the name of
     *                                                                         the starting stop, null at that stop
     */
    public static function perFermata(iterable $fermate): array
    {
        $presenti = [];
        $risultato = [];

        foreach ($fermate as $fermata) {
            foreach ($fermata->accompagnatori as $persona) {
                $presenti[$persona->id] ??= [
                    'id' => $persona->id,
                    'nome' => trim("{$persona->nome} {$persona->cognome}"),
                    'daId' => $fermata->id,
                    'daNome' => $fermata->nome,
                ];
            }

            $risultato[$fermata->id] = collect($presenti)
                ->map(fn (array $voce) => [
                    'id' => $voce['id'],
                    'nome' => $voce['nome'],
                    'da' => $voce['daId'] === $fermata->id ? null : $voce['daNome'],
                ])
                ->sortBy('nome')
                ->values()
                ->all();
        }

        return $risultato;
    }
}
