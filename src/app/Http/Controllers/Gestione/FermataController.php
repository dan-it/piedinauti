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
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stops of a line, managed by city administrators. Stops are always numbered in
 * order of time, so there is no separate "order" field to maintain.
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
        ]);
    }

    public function store(Request $request, Linea $linea): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('create', [Fermata::class, $linea]);

        $dati = $this->validati($request);

        DB::transaction(function () use ($linea, $dati) {
            // Put the stop at the end, then number everything again by time.
            Fermata::query()->create([
                'citta_id' => $linea->citta_id,
                'linea_id' => $linea->id,
                'nome' => $dati['nome'],
                'orario' => $dati['orario'].':00',
                'ordine' => ((int) Fermata::query()->where('linea_id', $linea->id)->max('ordine')) + 1,
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
            ],
        ]);
    }

    public function update(Request $request, Fermata $fermata): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('update', $fermata);

        $dati = $this->validati($request);

        DB::transaction(function () use ($fermata, $dati) {
            $fermata->update(['nome' => $dati['nome'], 'orario' => $dati['orario'].':00']);
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
     * @return array{nome: string, orario: string}
     */
    private function validati(Request $request): array
    {
        $request->merge(['nome' => trim((string) $request->input('nome'))]);

        return $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            // 24-hour "HH:MM", as sent by a time field.
            'orario' => ['required', 'date_format:H:i'],
        ]);
    }

    private function soloAdminCitta(Request $request): void
    {
        abort_unless($request->user()->eAdminCitta(), 403);
    }
}
