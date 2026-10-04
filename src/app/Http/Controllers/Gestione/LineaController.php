<?php

namespace App\Http\Controllers\Gestione;

use App\Enums\Ruolo;
use App\Http\Controllers\Controller;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lines (outward and return trips are separate lines), managed by city administrators.
 * Stops are managed by FermataController; this controller also chooses the managers.
 */
class LineaController extends Controller
{
    public function index(Request $request): Response
    {
        $this->soloAdminCitta($request);
        Gate::authorize('viewAny', Linea::class);

        $linee = Linea::query()
            ->with(['fermate', 'responsabili'])
            ->orderBy('nome')
            ->get()
            ->map(fn (Linea $linea) => [
                'id' => $linea->id,
                'nome' => $linea->nome,
                'fermate' => $linea->fermate->count(),
                'primo_orario' => $this->orario($linea->fermate->first()?->orario),
                'ultimo_orario' => $this->orario($linea->fermate->last()?->orario),
                'responsabili' => $linea->responsabili
                    ->sortBy(['cognome', 'nome'])
                    ->map(fn (User $persona) => trim("{$persona->nome} {$persona->cognome}"))
                    ->values()
                    ->all(),
            ]);

        return Inertia::render('linee/Index', ['linee' => $linee]);
    }

    public function create(Request $request): Response
    {
        $this->soloAdminCitta($request);
        Gate::authorize('create', Linea::class);

        return Inertia::render('linee/Form', ['linea' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('create', Linea::class);

        $dati = $request->validate(['nome' => $this->regoleNome($request)], $this->messaggiNome());

        // The city is the administrator's own: the model fills it in, never the request.
        $linea = Linea::query()->create(['nome' => trim($dati['nome'])]);

        return to_route('linee.edit', $linea)->with('status', 'Linea creata. Ora aggiungi le fermate e scegli i responsabili.');
    }

    public function edit(Request $request, Linea $linea): Response
    {
        $this->soloAdminCitta($request);
        Gate::authorize('update', $linea);

        $responsabili = User::query()
            ->whereHas('ruoliAssegnati', fn ($query) => $query->where('ruolo', Ruolo::Responsabile->value))
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get()
            ->map(fn (User $persona) => ['id' => $persona->id, 'nome' => trim("{$persona->nome} {$persona->cognome}")]);

        return Inertia::render('linee/Edit', [
            'linea' => ['id' => $linea->id, 'nome' => $linea->nome],
            'fermate' => $linea->fermate->map(fn ($fermata) => [
                'id' => $fermata->id,
                'nome' => $fermata->nome,
                'orario' => $this->orario($fermata->orario),
            ])->values(),
            'responsabili' => $responsabili,
            'assegnati' => $linea->responsabili()->pluck('users.id')->values(),
        ]);
    }

    public function update(Request $request, Linea $linea): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('update', $linea);

        $dati = $request->validate(['nome' => $this->regoleNome($request, $linea)], $this->messaggiNome());
        $linea->update(['nome' => trim($dati['nome'])]);

        return back()->with('status', 'Linea aggiornata.');
    }

    /**
     * Replace the managers of the line with the chosen people.
     */
    public function aggiornaResponsabili(Request $request, Linea $linea): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('update', $linea);

        $dati = $request->validate([
            'responsabili' => ['array'],
            'responsabili.*' => ['integer'],
        ]);

        $scelti = collect($dati['responsabili'] ?? [])->unique()->values();

        // Only people of this city (the user query is limited to it) who hold the manager role.
        $validi = User::query()
            ->whereIn('id', $scelti)
            ->whereHas('ruoliAssegnati', fn ($query) => $query->where('ruolo', Ruolo::Responsabile->value))
            ->pluck('id');

        if ($validi->count() !== $scelti->count()) {
            throw ValidationException::withMessages([
                'responsabili' => 'Puoi scegliere solo responsabili della tua città.',
            ]);
        }

        $linea->responsabili()->sync(
            $validi->mapWithKeys(fn (int $id) => [$id => ['citta_id' => $linea->citta_id]])->all()
        );

        return back()->with('status', 'Responsabili aggiornati.');
    }

    /**
     * Form to duplicate a line: asks for the name of the copy.
     */
    public function formDuplica(Request $request, Linea $linea): Response
    {
        $this->soloAdminCitta($request);
        Gate::authorize('duplicare', $linea);

        return Inertia::render('linee/Duplica', [
            'linea' => ['id' => $linea->id, 'nome' => $linea->nome, 'fermate' => $linea->fermate->count()],
            'nome_proposto' => "{$linea->nome} (copia)",
        ]);
    }

    /**
     * Copy the line with all its stops (names, times and order). Managers, chaperones and
     * children are not copied: the new line starts empty of people.
     */
    public function duplica(Request $request, Linea $linea): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('duplicare', $linea);

        $dati = $request->validate(['nome' => $this->regoleNome($request)], $this->messaggiNome());

        $nuova = DB::transaction(function () use ($linea, $dati) {
            // The city is the administrator's own: the model fills it in.
            $nuova = Linea::query()->create(['nome' => trim($dati['nome'])]);

            foreach ($linea->fermate as $fermata) {
                Fermata::query()->create([
                    'citta_id' => $nuova->citta_id,
                    'linea_id' => $nuova->id,
                    'nome' => $fermata->nome,
                    'orario' => $fermata->orario,
                    'ordine' => $fermata->ordine,
                ]);
            }

            return $nuova;
        });

        $numero = $linea->fermate->count();

        return to_route('linee.edit', $nuova)->with('status', "Linea duplicata con {$numero} ".($numero === 1 ? 'fermata' : 'fermate').'.');
    }

    /**
     * Archive the line: hidden from everybody except global administrators, nothing is deleted.
     */
    public function archivia(Request $request, Linea $linea): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('archiviare', $linea);

        $linea->update(['archiviata_il' => now()]);

        return to_route('linee.index')->with('status', "Linea «{$linea->nome}» archiviata. Solo l'amministratore globale può ripristinarla.");
    }

    public function destroy(Request $request, Linea $linea): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('delete', $linea);

        // Deleting a line would also delete its attendance history: refuse instead.
        if ($linea->presenze()->exists()) {
            return back()->with('errore', 'La linea ha presenze registrate: non si può eliminare.');
        }

        $linea->delete();

        return to_route('linee.index')->with('status', 'Linea eliminata.');
    }

    /**
     * Line names are unique within a city (the same name can exist in another city).
     *
     * @return array<int, mixed>
     */
    private function regoleNome(Request $request, ?Linea $linea = null): array
    {
        return [
            'required',
            'string',
            'max:255',
            // Archived lines no longer hold their name: only active lines must be distinct.
            Rule::unique('linee', 'nome')
                ->where('citta_id', $request->user()->citta_id)
                ->whereNull('archiviata_il')
                ->ignore($linea?->id),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messaggiNome(): array
    {
        return ['nome.unique' => 'Esiste già una linea con questo nome.'];
    }

    /**
     * "07:45:00" -> "07:45".
     */
    private function orario(?string $orario): ?string
    {
        return $orario === null ? null : substr($orario, 0, 5);
    }

    private function soloAdminCitta(Request $request): void
    {
        abort_unless($request->user()->eAdminCitta(), 403);
    }
}
