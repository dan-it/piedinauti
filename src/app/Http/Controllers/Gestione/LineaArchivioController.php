<?php

namespace App\Http\Controllers\Gestione;

use App\Http\Controllers\Controller;
use App\Models\Linea;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Archived lines. Only global administrators see them, and only they can bring them back.
 */
class LineaArchivioController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('vedereArchiviate', Linea::class);

        // One group per city, cities in alphabetical order, newest archived line first.
        $gruppi = Linea::query()
            ->whereNotNull('archiviata_il')
            ->with('citta')
            ->withCount('fermate')
            ->orderByDesc('archiviata_il')
            ->get()
            ->groupBy('citta_id')
            ->map(fn ($linee) => [
                'citta' => $linee->first()->citta?->nome,
                'linee' => $linee->map(fn (Linea $linea) => [
                    'id' => $linea->id,
                    'nome' => $linea->nome,
                    'fermate' => $linea->fermate_count,
                    'archiviata_il' => $linea->archiviata_il->format('d/m/Y'),
                ])->values()->all(),
            ])
            ->sortBy('citta', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return Inertia::render('linee/Archiviate', ['gruppi' => $gruppi]);
    }

    /**
     * Bring the line back. Its name may have been taken by an active line in the meantime:
     * the form offers the current name, and a different one can be typed in.
     */
    public function ripristina(Request $request, Linea $linea): RedirectResponse
    {
        Gate::authorize('ripristinare', $linea);

        $request->merge(['nome' => trim((string) $request->input('nome', $linea->nome))]);

        $dati = $request->validate([
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('linee', 'nome')
                    ->where('citta_id', $linea->citta_id)
                    ->whereNull('archiviata_il')
                    ->ignore($linea->id),
            ],
        ], ['nome.unique' => 'In questa città esiste già una linea attiva con questo nome: scegline un altro.']);

        $linea->update(['nome' => $dati['nome'], 'archiviata_il' => null]);

        return to_route('linee-archiviate.index')->with('status', "Linea «{$linea->nome}» ripristinata.");
    }
}
