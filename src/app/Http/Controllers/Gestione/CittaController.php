<?php

namespace App\Http\Controllers\Gestione;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestione\SalvaCittaRequest;
use App\Models\Citta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cities, managed by global administrators.
 */
class CittaController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Citta::class);

        $citta = Citta::query()
            ->withCount(['utenti', 'bambini', 'linee'])
            ->orderBy('nome')
            ->get()
            ->map(fn (Citta $citta) => [
                'id' => $citta->id,
                'nome' => $citta->nome,
                'persone' => $citta->utenti_count,
                'bambini' => $citta->bambini_count,
                'linee' => $citta->linee_count,
            ]);

        return Inertia::render('citta/Index', ['citta' => $citta]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Citta::class);

        return Inertia::render('citta/Form', ['citta' => null]);
    }

    public function store(SalvaCittaRequest $request): RedirectResponse
    {
        $citta = Citta::query()->create($request->validated());

        return to_route('citta.index')->with('status', "Città «{$citta->nome}» creata.");
    }

    public function edit(Citta $citta): Response
    {
        Gate::authorize('update', $citta);

        return Inertia::render('citta/Form', [
            'citta' => ['id' => $citta->id, 'nome' => $citta->nome],
        ]);
    }

    public function update(SalvaCittaRequest $request, Citta $citta): RedirectResponse
    {
        $citta->update($request->validated());

        return to_route('citta.index')->with('status', "Città «{$citta->nome}» aggiornata.");
    }
}
