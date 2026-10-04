<?php

namespace App\Http\Controllers\Gestione;

use App\Enums\Ruolo;
use App\Http\Controllers\Controller;
use App\Models\Bambino;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\User;
use App\Support\CoperturaAccompagnatori;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chaperones and children of each stop, managed by the managers of the line
 * (and by city administrators).
 *
 * A chaperone is assigned only to the stop where they start: from there they stay with the group
 * and are present at every later stop, so they are never repeated. A stop may have no children,
 * and the first stops of a line may have no chaperone yet: those are normal states, not errors.
 */
class AssegnazioneController extends Controller
{
    /** Search results shown while looking for a child to add. */
    private const RISULTATI = 10;

    /**
     * The lines the person can work on.
     */
    public function linee(Request $request): Response
    {
        $this->soloResponsabiliEAdmin($request);

        $linee = Linea::query()
            ->with(['fermate' => fn ($query) => $query->withCount('bambini')->with('accompagnatori')])
            ->orderBy('nome')
            ->get()
            ->filter(fn (Linea $linea) => $request->user()->can('gestireAssegnazioni', $linea))
            ->map(function (Linea $linea) {
                $coperture = CoperturaAccompagnatori::perFermata($linea->fermate);

                return [
                    'id' => $linea->id,
                    'nome' => $linea->nome,
                    'fermate' => $linea->fermate->count(),
                    'bambini' => $linea->fermate->sum('bambini_count'),
                    // Stops where nobody is present: those before the first chaperone's starting stop.
                    'senza_accompagnatore' => $linea->fermate->filter(fn (Fermata $fermata) => $coperture[$fermata->id] === [])->count(),
                ];
            })
            ->values();

        return Inertia::render('assegnazioni/Index', ['linee' => $linee]);
    }

    /**
     * Overview of a line: every stop with its chaperones and children.
     */
    public function linea(Request $request, Linea $linea): Response
    {
        $this->soloResponsabiliEAdmin($request);
        Gate::authorize('gestireAssegnazioni', $linea);

        $elenco = $linea->fermate()->with(['accompagnatori', 'bambini'])->get();
        $coperture = CoperturaAccompagnatori::perFermata($elenco);

        $fermate = $elenco->map(fn (Fermata $fermata) => [
            'id' => $fermata->id,
            'nome' => $fermata->nome,
            'orario' => substr($fermata->orario, 0, 5),
            // Everybody present at the stop; "da" names the stop where a chaperone started, if earlier.
            'accompagnatori' => collect($coperture[$fermata->id])->map(fn (array $voce) => ['nome' => $voce['nome'], 'da' => $voce['da']])->all(),
            'bambini' => $this->nomiBambini($fermata->bambini),
        ]);

        return Inertia::render('assegnazioni/Linea', [
            'linea' => ['id' => $linea->id, 'nome' => $linea->nome],
            'fermate' => $fermate,
        ]);
    }

    /**
     * Manage one stop: its chaperones and its children.
     */
    public function fermata(Request $request, Fermata $fermata): Response
    {
        $this->soloResponsabiliEAdmin($request);
        Gate::authorize('gestireAssegnazioni', $fermata);

        $linea = $fermata->linea;
        $ricerca = trim((string) $request->query('q', ''));

        // Where each chaperone starts on this line (a person has one starting stop per line).
        $elenco = $linea->fermate()->with('accompagnatori')->get();
        $coperture = CoperturaAccompagnatori::perFermata($elenco);
        $inizi = [];
        foreach ($elenco as $altra) {
            foreach ($altra->accompagnatori as $persona) {
                $inizi[$persona->id] ??= ['id' => $altra->id, 'nome' => $altra->nome];
            }
        }

        $accompagnatori = User::query()
            ->whereHas('ruoliAssegnati', fn ($query) => $query->where('ruolo', Ruolo::Accompagnatore->value))
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get()
            ->map(fn (User $persona) => [
                'id' => $persona->id,
                'nome' => trim("{$persona->nome} {$persona->cognome}"),
                // Name of the stop where they already start on this line, if it is another one.
                'inizia_a' => isset($inizi[$persona->id]) && $inizi[$persona->id]['id'] !== $fermata->id ? $inizi[$persona->id]['nome'] : null,
            ]);

        return Inertia::render('assegnazioni/Fermata', [
            'linea' => ['id' => $linea->id, 'nome' => $linea->nome],
            'fermata' => ['id' => $fermata->id, 'nome' => $fermata->nome, 'orario' => substr($fermata->orario, 0, 5)],
            'accompagnatori' => $accompagnatori,
            'assegnati' => $fermata->accompagnatori()->pluck('users.id')->values(),
            // Chaperones who started at an earlier stop and are with the group here too.
            'precedenti' => collect($coperture[$fermata->id])
                ->filter(fn (array $voce) => $voce['da'] !== null)
                ->map(fn (array $voce) => ['nome' => $voce['nome'], 'da' => $voce['da']])
                ->values()
                ->all(),
            'bambini' => $this->elencoBambini($fermata->bambini),
            'ricerca' => $ricerca,
            // Only computed when asked for (search as you type reloads just these two props).
            'risultati' => fn () => $this->cerca($fermata, $ricerca),
        ]);
    }

    /**
     * Replace the chaperones of the stop with the chosen people (possibly nobody).
     */
    public function aggiornaAccompagnatori(Request $request, Fermata $fermata): RedirectResponse
    {
        $this->soloResponsabiliEAdmin($request);
        Gate::authorize('gestireAssegnazioni', $fermata);

        $dati = $request->validate([
            'accompagnatori' => ['array'],
            'accompagnatori.*' => ['integer'],
        ]);

        $scelti = collect($dati['accompagnatori'] ?? [])->unique()->values();

        // Only people of this city (the user query is limited to it) who hold the chaperone role.
        $validi = User::query()
            ->whereIn('id', $scelti)
            ->whereHas('ruoliAssegnati', fn ($query) => $query->where('ruolo', Ruolo::Accompagnatore->value))
            ->pluck('id');

        if ($validi->count() !== $scelti->count()) {
            throw ValidationException::withMessages([
                'accompagnatori' => 'Puoi scegliere solo accompagnatori della tua città.',
            ]);
        }

        $spostati = DB::transaction(function () use ($fermata, $validi) {
            // A chaperone starts at one stop per line: anybody chosen here who already starts at
            // another stop of this line is moved to this one.
            $altre = Fermata::query()->where('linea_id', $fermata->linea_id)->whereKeyNot($fermata->id)->pluck('id');

            $spostati = User::query()
                ->whereIn('id', $validi)
                ->whereHas('fermateAccompagnatore', fn ($query) => $query->whereIn('fermate.id', $altre))
                ->count();

            DB::table('fermata_accompagnatore')->whereIn('fermata_id', $altre)->whereIn('user_id', $validi)->delete();

            $fermata->accompagnatori()->sync(
                $validi->mapWithKeys(fn (int $id) => [$id => ['citta_id' => $fermata->citta_id]])->all()
            );

            return $spostati;
        });

        return back()->with('status', $spostati === 0
            ? 'Accompagnatori aggiornati.'
            : 'Accompagnatori aggiornati: '.($spostati === 1 ? 'una persona è stata spostata' : "{$spostati} persone sono state spostate").' da un\'altra fermata della linea.');
    }

    /**
     * Put a child on this stop. On one line a child has one stop only, so a child
     * already assigned to another stop of the same line is moved here.
     */
    public function aggiungiBambino(Request $request, Fermata $fermata): RedirectResponse
    {
        $this->soloResponsabiliEAdmin($request);
        Gate::authorize('gestireAssegnazioni', $fermata);

        $dati = $request->validate(['bambino_id' => ['required', 'integer']]);

        // The query is limited to the person's city: a child of another city is "not found".
        $bambino = Bambino::query()->find($dati['bambino_id']);

        if ($bambino === null) {
            throw ValidationException::withMessages(['bambino_id' => 'Bambino non trovato.']);
        }

        $spostatoDa = DB::transaction(function () use ($fermata, $bambino) {
            $altre = Fermata::query()
                ->where('linea_id', $fermata->linea_id)
                ->whereKeyNot($fermata->id)
                ->whereHas('bambini', fn ($query) => $query->where('bambini.id', $bambino->id))
                ->get();

            foreach ($altre as $altra) {
                $altra->bambini()->detach($bambino->id);
            }

            $fermata->assegnaBambino($bambino);

            return $altre->first()?->nome;
        });

        $nome = $bambino->nomeCompleto;

        return back()->with('status', $spostatoDa === null
            ? "{$nome} aggiunto alla fermata."
            : "{$nome} spostato dalla fermata «{$spostatoDa}» a questa.");
    }

    public function rimuoviBambino(Request $request, Fermata $fermata, Bambino $bambino): RedirectResponse
    {
        $this->soloResponsabiliEAdmin($request);
        Gate::authorize('gestireAssegnazioni', $fermata);

        $fermata->bambini()->detach($bambino->id);

        return back()->with('status', "{$bambino->nomeCompleto} tolto dalla fermata.");
    }

    /**
     * Children matching the search, not yet on this stop. Each says whether it is already
     * on another stop of the same line (it would be moved).
     *
     * @return list<array{id: int, nome: string, altra_fermata: string|null}>
     */
    private function cerca(Fermata $fermata, string $ricerca): array
    {
        if (mb_strlen($ricerca) < 2) {
            return [];
        }

        $parola = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($ricerca)).'%';

        $trovati = Bambino::query()
            ->where(fn ($query) => $query
                ->whereRaw("LOWER(nome || ' ' || cognome) LIKE ?", [$parola])
                ->orWhereRaw("LOWER(cognome || ' ' || nome) LIKE ?", [$parola]))
            ->whereDoesntHave('fermate', fn ($query) => $query->where('fermate.id', $fermata->id))
            ->orderByRaw("LOWER(CASE WHEN cognome = '' THEN nome ELSE cognome END)")
            ->orderBy('nome')
            ->limit(self::RISULTATI)
            ->get();

        // Where each found child already is on this line (one query for all of them).
        $altreFermate = DB::table('fermata_bambino')
            ->join('fermate', 'fermate.id', '=', 'fermata_bambino.fermata_id')
            ->where('fermate.linea_id', $fermata->linea_id)
            ->whereIn('fermata_bambino.bambino_id', $trovati->pluck('id'))
            ->pluck('fermate.nome', 'fermata_bambino.bambino_id');

        return $trovati->map(fn (Bambino $bambino) => [
            'id' => $bambino->id,
            'nome' => $bambino->nomeCompleto,
            'altra_fermata' => $altreFermate[$bambino->id] ?? null,
        ])->all();
    }

    /**
     * @param  iterable<Bambino>  $bambini
     * @return list<string>
     */
    private function nomiBambini(iterable $bambini): array
    {
        return collect($bambini)
            ->sortBy(fn (Bambino $bambino) => $this->chiaveOrdine($bambino))
            ->map(fn (Bambino $bambino) => $bambino->nomeCompleto)
            ->values()
            ->all();
    }

    /**
     * @param  iterable<Bambino>  $bambini
     * @return list<array{id: int, nome: string}>
     */
    private function elencoBambini(iterable $bambini): array
    {
        return collect($bambini)
            ->sortBy(fn (Bambino $bambino) => $this->chiaveOrdine($bambino))
            ->map(fn (Bambino $bambino) => ['id' => $bambino->id, 'nome' => $bambino->nomeCompleto])
            ->values()
            ->all();
    }

    /**
     * Children are sorted by surname, or by first name when they have no surname.
     */
    private function chiaveOrdine(Bambino $bambino): string
    {
        return mb_strtolower(($bambino->cognome !== '' ? $bambino->cognome : $bambino->nome).' '.$bambino->nome);
    }

    /**
     * Managers and city administrators only; policies then check the specific line or stop.
     */
    private function soloResponsabiliEAdmin(Request $request): void
    {
        $utente = $request->user();

        abort_unless($utente->eAdminCitta() || $utente->haRuolo(Ruolo::Responsabile), 403);
    }
}
