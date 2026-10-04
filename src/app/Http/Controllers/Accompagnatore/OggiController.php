<?php

namespace App\Http\Controllers\Accompagnatore;

use App\Enums\Ruolo;
use App\Http\Controllers\Controller;
use App\Models\Bambino;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Models\User;
use App\Policies\PresenzaPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The chaperone's morning screen. Shows today's line with its stops in order, the children of
 * the chaperone's own stops, and records attendance one tap at a time.
 *
 * A chaperone is assigned to the stop where they start and goes on with the group to the end of
 * the line, so the screen lists every stop from the starting one onwards.
 *
 * A stop can have no children: it is shown as such and children can still be added for the day.
 * Once the modification window has closed (30 minutes after the line's arrival) nothing can change.
 */
class OggiController extends Controller
{
    /** Search results shown while adding a child for the day. */
    private const RISULTATI = 10;

    /**
     * The chaperone's lines. With a single line, go straight to it.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $utente = $this->accompagnatore($request);

        // Starting stops, grouped by line: the line list is ordered by the time of the starting stop.
        $linee = $utente->fermateAccompagnatore()
            ->with('linea')
            ->get()
            ->filter(fn (Fermata $fermata) => $fermata->linea !== null)
            ->groupBy('linea_id')
            ->map(function (Collection $fermate) {
                // Normally one per line; if data says otherwise, the earliest stop is where they start.
                $inizio = $fermate->sortBy('ordine')->first();

                return [
                    'id' => $inizio->linea->id,
                    'nome' => $inizio->linea->nome,
                    'prima_orario' => $this->orario($inizio->orario),
                    'inizio' => ['nome' => $inizio->nome, 'orario' => $this->orario($inizio->orario)],
                ];
            })
            ->sortBy('prima_orario')
            ->values();

        if ($linee->count() === 1) {
            return to_route('oggi.linea', $linee->first()['id']);
        }

        return Inertia::render('oggi/Index', [
            'linee' => $linee,
            'data' => $this->dataEstesa(),
        ]);
    }

    /**
     * One line: every stop in order. The chaperone's own stops list their children
     * with today's attendance; the other stops are shown only as landmarks.
     */
    public function linea(Request $request, Linea $linea): Response
    {
        $utente = $this->accompagnatore($request);
        Gate::authorize('view', $linea);

        $oggi = today();

        // Today's attendance for the whole line, by child.
        $presenze = Presenza::query()
            ->where('data', $oggi->toDateString())
            ->where('linea_id', $linea->id)
            ->get()
            ->keyBy('bambino_id');

        $nomiFermate = $linea->fermate->pluck('nome', 'id');
        // From the starting stop to the end of the line: the chaperone is present at all of them.
        $coperte = $utente->fermateCoperte()->where('fermate.linea_id', $linea->id)->pluck('fermate.id');
        $modificaAperta = PresenzaPolicy::modificaAperta($linea);
        $arrivo = $linea->arrivoPrevisto($oggi);

        $fermate = $linea->fermate->map(function (Fermata $fermata) use ($coperte, $presenze, $nomiFermate, $modificaAperta) {
            $mia = $coperte->contains($fermata->id);

            return [
                'id' => $fermata->id,
                'nome' => $fermata->nome,
                'orario' => $this->orario($fermata->orario),
                'mia' => $mia,
                'bambini' => $mia ? $this->bambiniDellaFermata($fermata, $presenze, $nomiFermate, $modificaAperta) : [],
            ];
        })->values();

        return Inertia::render('oggi/Linea', [
            'linea' => ['id' => $linea->id, 'nome' => $linea->nome],
            'data' => $this->dataEstesa(),
            'fermate' => $fermate,
            'arrivo' => $arrivo?->format('H:i'),
            'modifica_aperta' => $modificaAperta,
            'minuti_modifica' => PresenzaPolicy::FINESTRA_MINUTI,
        ]);
    }

    /**
     * Record (or correct) today's attendance of a child at one of the chaperone's stops.
     * Repeating the same request changes nothing: one row per child, line and day.
     */
    public function registra(Request $request, Fermata $fermata): JsonResponse
    {
        $utente = $this->accompagnatore($request);

        $dati = $request->validate([
            'bambino_id' => ['required', 'integer'],
            'presente' => ['required', 'boolean'],
        ]);

        // The query is limited to the person's city: a child of another city is "not found".
        $bambino = Bambino::query()->find($dati['bambino_id']);

        if ($bambino === null) {
            return response()->json(['message' => 'Bambino non trovato.'], 422);
        }

        // Not the chaperone of this stop: a plain refusal.
        abort_unless($utente->eAccompagnatoreDi($fermata), 403);

        // Allowed by the rules (today only, and only while the modification window is open)?
        if (Gate::denies('registrare', [Presenza::class, $fermata, $bambino])) {
            return response()->json([
                'message' => 'Il tempo per modificare le presenze è scaduto: sono passati più di '
                    .PresenzaPolicy::FINESTRA_MINUTI.' minuti dall\'arrivo della linea.',
            ], 403);
        }

        // A child who is not usually on this stop joins it for the day only.
        $temporaneo = ! $fermata->bambini()->where('bambini.id', $bambino->id)->exists();

        $presenza = Presenza::registra($fermata, $bambino, today(), (bool) $dati['presente'], $temporaneo, $utente);

        return response()->json([
            'bambino_id' => $bambino->id,
            'presente' => $presenza->presente,
            'temporaneo' => $presenza->temporaneo,
        ]);
    }

    /**
     * Children of the city that can be added to the stop for today: not already in its list.
     */
    public function cerca(Request $request, Fermata $fermata): JsonResponse
    {
        $utente = $this->accompagnatore($request);
        abort_unless($utente->eAccompagnatoreDi($fermata), 403);

        $ricerca = trim((string) $request->query('q', ''));

        // Nothing can be added once the modification window has closed.
        if (mb_strlen($ricerca) < 2 || ! PresenzaPolicy::modificaAperta($fermata->linea)) {
            return response()->json(['risultati' => []]);
        }

        $oggi = today()->toDateString();
        $parola = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($ricerca)).'%';

        // Already in the stop's list today: assigned to it, or added to it for today.
        $giaInLista = $fermata->bambini()->pluck('bambini.id')
            ->merge(Presenza::query()->where('data', $oggi)->where('fermata_id', $fermata->id)->pluck('bambino_id'));

        $trovati = Bambino::query()
            ->where(fn ($query) => $query
                ->whereRaw("LOWER(nome || ' ' || cognome) LIKE ?", [$parola])
                ->orWhereRaw("LOWER(cognome || ' ' || nome) LIKE ?", [$parola]))
            ->whereNotIn('id', $giaInLista)
            ->orderByRaw("LOWER(CASE WHEN cognome = '' THEN nome ELSE cognome END)")
            ->orderBy('nome')
            ->limit(self::RISULTATI)
            ->get();

        // Children already marked today at another stop of this line would be moved here.
        $altrove = Presenza::query()
            ->where('data', $oggi)
            ->where('linea_id', $fermata->linea_id)
            ->whereIn('bambino_id', $trovati->pluck('id'))
            ->with('fermata')
            ->get()
            ->keyBy('bambino_id');

        return response()->json([
            'risultati' => $trovati->map(fn (Bambino $bambino) => [
                'id' => $bambino->id,
                'nome' => $bambino->nomeCompleto,
                'altrove' => $altrove->get($bambino->id)?->fermata?->nome,
            ])->values(),
        ]);
    }

    /**
     * The children shown under one stop today: those assigned to it plus those added to it
     * for the day, each with today's state.
     *
     * @param  Collection<int, Presenza>  $presenze  today's attendance of the line, by child
     * @param  Collection<int, string>  $nomiFermate  stop names by id
     * @return list<array<string, mixed>>
     */
    private function bambiniDellaFermata(Fermata $fermata, Collection $presenze, Collection $nomiFermate, bool $modificaAperta): array
    {
        $assegnati = $fermata->bambini;

        $aggiuntiOggi = Bambino::query()
            ->whereIn('id', $presenze->filter(fn (Presenza $p) => $p->fermata_id === $fermata->id && $p->temporaneo)->keys())
            ->get();

        return $assegnati->merge($aggiuntiOggi)
            ->unique('id')
            ->sortBy(fn (Bambino $bambino) => mb_strtolower(($bambino->cognome !== '' ? $bambino->cognome : $bambino->nome).' '.$bambino->nome))
            ->map(function (Bambino $bambino) use ($fermata, $presenze, $nomiFermate, $modificaAperta) {
                /** @var Presenza|null $presenza */
                $presenza = $presenze->get($bambino->id);

                return [
                    'id' => $bambino->id,
                    'nome' => $bambino->nomeCompleto,
                    'presente' => $presenza?->presente,
                    'temporaneo' => $presenza !== null && $presenza->fermata_id === $fermata->id && $presenza->temporaneo,
                    // Already marked at another stop of the line today (tapping here moves it).
                    'altrove' => $presenza !== null && $presenza->fermata_id !== $fermata->id ? $nomiFermate->get($presenza->fermata_id) : null,
                    // Nothing can be marked or changed once the modification window has closed.
                    'modificabile' => $modificaAperta,
                ];
            })
            ->values()
            ->all();
    }

    private function accompagnatore(Request $request): User
    {
        $utente = $request->user();

        abort_unless($utente->haRuolo(Ruolo::Accompagnatore), 403);

        return $utente;
    }

    /**
     * "07:45:00" -> "07:45".
     */
    private function orario(?string $orario): ?string
    {
        return $orario === null ? null : substr($orario, 0, 5);
    }

    /**
     * Today's date in words, e.g. "lunedì 5 ottobre".
     */
    private function dataEstesa(): string
    {
        return today()->translatedFormat('l j F');
    }
}
