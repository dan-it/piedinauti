<?php

namespace App\Http\Controllers\Gestione;

use App\Http\Controllers\Controller;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stops of a line, managed by city administrators. Stops are always numbered in
 * order of time, so there is no separate "order" field to maintain.
 *
 * A line can have one special stop, the destination (for example the school): it is always the
 * last one, nobody boards there, and the chaperones only mark "arrived".
 */
class FermataController extends Controller
{
    public function create(Request $request, Linea $linea): Response
    {
        $this->soloAdminCitta($request);
        Gate::authorize('create', [Fermata::class, $linea]);

        return Inertia::render('fermate/Form', [
            'linea' => ['id' => $linea->id, 'nome' => $linea->nome],
            'fermata' => null,
            'destinazione_esistente' => $linea->destinazione()?->nome,
        ]);
    }

    public function store(Request $request, Linea $linea): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('create', [Fermata::class, $linea]);

        $dati = $this->validati($request, $linea);

        DB::transaction(function () use ($linea, $dati) {
            // Put the stop at the end, then number everything again by time.
            Fermata::query()->create([
                'citta_id' => $linea->citta_id,
                'linea_id' => $linea->id,
                'nome' => $dati['nome'],
                'orario' => $dati['orario'].':00',
                'ordine' => ((int) Fermata::query()->where('linea_id', $linea->id)->max('ordine')) + 1,
                'destinazione' => $dati['destinazione'],
            ]);

            $linea->riordinaFermate();
        });

        return to_route('linee.edit', $linea)->with('status', "Fermata «{$dati['nome']}» aggiunta.");
    }

    public function edit(Request $request, Fermata $fermata): Response
    {
        $this->soloAdminCitta($request);
        Gate::authorize('update', $fermata);

        return Inertia::render('fermate/Form', [
            'linea' => ['id' => $fermata->linea->id, 'nome' => $fermata->linea->nome],
            'fermata' => [
                'id' => $fermata->id,
                'nome' => $fermata->nome,
                'orario' => substr($fermata->orario, 0, 5),
                'destinazione' => $fermata->destinazione,
            ],
            // Another stop of the line that is already the destination (this one cannot be it too).
            'destinazione_esistente' => Fermata::query()
                ->where('linea_id', $fermata->linea_id)
                ->where('destinazione', true)
                ->whereKeyNot($fermata->id)
                ->value('nome'),
        ]);
    }

    public function update(Request $request, Fermata $fermata): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('update', $fermata);

        $dati = $this->validati($request, $fermata->linea, $fermata);

        DB::transaction(function () use ($fermata, $dati) {
            $fermata->update(['nome' => $dati['nome'], 'orario' => $dati['orario'].':00', 'destinazione' => $dati['destinazione']]);
            $fermata->linea->riordinaFermate();
        });

        return to_route('linee.edit', $fermata->linea)->with('status', 'Fermata aggiornata.');
    }

    public function destroy(Request $request, Fermata $fermata): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('delete', $fermata);

        $linea = $fermata->linea;

        // Deleting a stop would also delete the attendance recorded there: refuse instead.
        if (Presenza::query()->where('fermata_id', $fermata->id)->exists()) {
            return back()->with('errore', 'Alla fermata ci sono presenze registrate: non si può eliminare.');
        }

        DB::transaction(function () use ($fermata, $linea) {
            $fermata->delete();
            $linea->riordinaFermate();
        });

        return to_route('linee.edit', $linea)->with('status', 'Fermata eliminata.');
    }

    /**
     * Validate a stop, including the rules of the destination:
     * a line has at most one, it is the last stop (never earlier than the others), and nobody
     * is assigned to it.
     *
     * @return array{nome: string, orario: string, destinazione: bool}
     */
    private function validati(Request $request, Linea $linea, ?Fermata $fermata = null): array
    {
        $request->merge(['nome' => trim((string) $request->input('nome'))]);

        $dati = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            // 24-hour "HH:MM", as sent by a time field.
            'orario' => ['required', 'date_format:H:i'],
            'destinazione' => ['boolean'],
        ]);

        $dati['destinazione'] = (bool) ($dati['destinazione'] ?? false);
        $orario = $dati['orario'].':00';

        $altre = Fermata::query()
            ->where('linea_id', $linea->id)
            ->when($fermata, fn ($query) => $query->whereKeyNot($fermata->id))
            ->get();
        $attuale = $altre->firstWhere('destinazione', true);

        if ($dati['destinazione']) {
            if ($attuale !== null) {
                throw ValidationException::withMessages([
                    'destinazione' => "Questa linea ha già una destinazione («{$attuale->nome}»): ce ne può essere una sola.",
                ]);
            }

            $ultima = $altre->max('orario');
            if ($ultima !== null && $orario < $ultima) {
                throw ValidationException::withMessages([
                    'orario' => 'La destinazione è l\'ultima fermata: scegli un orario uguale o successivo a quello delle altre (ore '.substr($ultima, 0, 5).').',
                ]);
            }

            if ($fermata !== null && ($fermata->bambini()->exists() || $fermata->accompagnatori()->exists())) {
                throw ValidationException::withMessages([
                    'destinazione' => 'Alla destinazione non si assegnano bambini né accompagnatori: toglili prima da questa fermata.',
                ]);
            }
        } elseif ($attuale !== null && $orario > $attuale->orario) {
            throw ValidationException::withMessages([
                'orario' => 'Dopo la destinazione (ore '.substr($attuale->orario, 0, 5).') non ci sono altre fermate: scegli un orario precedente.',
            ]);
        }

        return $dati;
    }

    private function soloAdminCitta(Request $request): void
    {
        abort_unless($request->user()->eAdminCitta(), 403);
    }
}
