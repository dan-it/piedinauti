<?php

namespace App\Http\Controllers\Accompagnatore;

use App\Enums\Ruolo;
use App\Http\Controllers\Controller;
use App\Models\Arrivo;
use App\Models\Bambino;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Models\User;
use App\Policies\PresenzaPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The chaperone's morning screen. Shows today's line with its stops in order, the children of
 * the chaperone's own stops, and records attendance one tap at a time.
 *
 * A chaperone is assigned to the stop where they start and goes on with the group to the end of
 * the line, so the screen lists every stop from the starting one onwards.
 *
 * The last stop can be the line's destination: nobody boards there, the chaperone only taps
 * "arrived" and the time of the tap is saved.
 *
 * A stop can have no children: it is shown as such and children can still be added for the day.
 * Once the modification window has closed (30 minutes after the line's arrival) chaperones can no
 * longer change anything.
 *
 * The screen also works without signal: each tap is kept on the phone and sent later, together
 * with the moment it was made ("registrata_il"). The server judges the window at that moment, and
 * keeps the most recent of two conflicting marks, so late or repeated sending is harmless.
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
        $arrivoPrevisto = $linea->arrivoPrevisto($oggi);

        // The stops just before the chaperone's starting stop that the line lets them work on.
        $quanteVisibili = max(0, (int) $linea->fermate_precedenti_visibili);
        $posizioneInizio = $linea->fermate->search(fn (Fermata $candidata) => $coperte->contains($candidata->id));

        $fermate = $linea->fermate->map(function (Fermata $fermata, int $indice) use ($coperte, $presenze, $nomiFermate, $modificaAperta, $quanteVisibili, $posizioneInizio) {
            $mia = $coperte->contains($fermata->id);
            $precedente = ! $mia
                && $posizioneInizio !== false
                && $indice < $posizioneInizio
                && ($posizioneInizio - $indice) <= $quanteVisibili;

            return [
                'id' => $fermata->id,
                'nome' => $fermata->nome,
                'orario' => $this->orario($fermata->orario),
                'destinazione' => $fermata->destinazione,
                'mia' => $mia,
                // Just before the chaperone's start and allowed by the line: the chaperone works on it like on their own.
                'precedente' => $precedente,
                // Nobody boards at the destination.
                'bambini' => ($mia || $precedente) && ! $fermata->destinazione
                    ? $this->bambiniDellaFermata($fermata, $presenze, $nomiFermate, $modificaAperta)
                    : [],
            ];
        })->values();

        $arrivo = Arrivo::query()
            ->where('data', $oggi->toDateString())
            ->where('linea_id', $linea->id)
            ->with('registrataDa')
            ->first();

        return Inertia::render('oggi/Linea', [
            'linea' => ['id' => $linea->id, 'nome' => $linea->nome],
            'data' => $this->dataEstesa(),
            // The day this page is about (YYYY-MM-DD): a page kept on the phone from another day is stale.
            'data_iso' => $oggi->toDateString(),
            'fermate' => $fermate,
            'arrivo' => $arrivoPrevisto?->format('H:i'),
            // The time the line actually arrived, once a chaperone has tapped "arrived".
            'arrivo_registrato' => $arrivo === null ? null : [
                'ora' => $arrivo->arrivata_alle->format('H:i'),
                'da' => $arrivo->registrataDa === null ? null : trim("{$arrivo->registrataDa->nome} {$arrivo->registrataDa->cognome}"),
            ],
            'modifica_aperta' => $modificaAperta,
            'minuti_modifica' => PresenzaPolicy::FINESTRA_MINUTI,
            'precedenti_visibili' => $quanteVisibili,
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
            // When the tap was made, as stated by the phone (sent late if there was no signal).
            'registrata_il' => ['nullable', 'date'],
        ]);

        $momento = $this->momentoAzione($dati['registrata_il'] ?? null);

        // The query is limited to the person's city: a child of another city is "not found".
        $bambino = Bambino::query()->find($dati['bambino_id']);

        if ($bambino === null) {
            return response()->json(['message' => 'Bambino non trovato.'], 422);
        }

        // A stop the chaperone cannot work on (neither theirs nor one of the allowed previous ones): a plain refusal.
        abort_unless($utente->puoOperareSu($fermata), 403);

        if ($fermata->destinazione) {
            return response()->json(['message' => 'Alla destinazione non salgono bambini: qui si segna solo l\'arrivo.'], 422);
        }

        // Allowed by the rules at the moment of the tap (the window is judged then, not now)?
        if (Gate::denies('registrare', [Presenza::class, $fermata, $bambino, $momento])) {
            return response()->json([
                'message' => 'Il tempo per modificare le presenze è scaduto: sono passati più di '
                    .PresenzaPolicy::FINESTRA_MINUTI.' minuti dall\'arrivo della linea.',
            ], 403);
        }

        // A child who is not usually on this stop joins it for the day only.
        $temporaneo = ! $fermata->bambini()->where('bambini.id', $bambino->id)->exists();

        $presenza = Presenza::registra($fermata, $bambino, $momento, (bool) $dati['presente'], $temporaneo, $utente, $momento);

        return response()->json([
            'bambino_id' => $bambino->id,
            'presente' => $presenza->presente,
            'temporaneo' => $presenza->temporaneo,
            // False when a more recent mark (for example an administrator's correction) was already there.
            'applicata' => ! $presenza->ignorata,
        ]);
    }

    /**
     * Every child of the city (names only). The phone keeps this list so that a child can be
     * found and added for the day even without signal.
     */
    public function bambini(Request $request): JsonResponse
    {
        $this->accompagnatore($request);

        $elenco = Bambino::query()
            ->orderByRaw("LOWER(CASE WHEN cognome = '' THEN nome ELSE cognome END)")
            ->orderBy('nome')
            ->limit(5000)
            ->get()
            ->map(fn (Bambino $bambino) => ['id' => $bambino->id, 'nome' => $bambino->nomeCompleto])
            ->values();

        return response()->json(['bambini' => $elenco]);
    }

    /**
     * The chaperone taps "arrived": the time of the tap is saved as the line's arrival.
     * The first tap of the day wins, so tapping again (or a second chaperone) changes nothing.
     */
    public function arrivo(Request $request, Linea $linea): JsonResponse
    {
        $utente = $this->accompagnatore($request);

        $dati = $request->validate(['registrata_il' => ['nullable', 'date']]);
        $momento = $this->momentoAzione($dati['registrata_il'] ?? null);

        $destinazione = $linea->destinazione();

        if ($destinazione === null) {
            return response()->json(['message' => 'Questa linea non ha una destinazione.'], 422);
        }

        // Not with the group at the destination: a plain refusal.
        abort_unless($utente->eAccompagnatoreDi($destinazione), 403);

        if (Gate::denies('registrareArrivo', [Presenza::class, $linea, $momento])) {
            return response()->json([
                'message' => 'Il tempo per segnare l\'arrivo è scaduto: sono passati più di '
                    .PresenzaPolicy::FINESTRA_MINUTI.' minuti dall\'arrivo previsto della linea.',
            ], 403);
        }

        $arrivo = Arrivo::registra($linea, $momento, $utente)->load('registrataDa');

        return response()->json([
            'ora' => $arrivo->arrivata_alle->format('H:i'),
            'da' => $arrivo->registrataDa === null ? null : trim("{$arrivo->registrataDa->nome} {$arrivo->registrataDa->cognome}"),
            'nuovo' => $arrivo->wasRecentlyCreated,
            'applicata' => true,
        ]);
    }

    /**
     * The chaperone cancels the arrival they tapped by mistake. Same rule as every other change:
     * allowed until 30 minutes after the line's expected arrival, judged at the moment of the tap.
     */
    public function annullaArrivo(Request $request, Linea $linea): JsonResponse
    {
        $utente = $this->accompagnatore($request);

        $dati = $request->validate(['registrata_il' => ['nullable', 'date']]);
        $momento = $this->momentoAzione($dati['registrata_il'] ?? null);

        $destinazione = $linea->destinazione();

        if ($destinazione === null) {
            return response()->json(['message' => 'Questa linea non ha una destinazione.'], 422);
        }

        // Not with the group at the destination: a plain refusal.
        abort_unless($utente->eAccompagnatoreDi($destinazione), 403);

        if (Gate::denies('registrareArrivo', [Presenza::class, $linea, $momento])) {
            return response()->json([
                'message' => 'Il tempo per modificare l\'arrivo è scaduto: sono passati più di '
                    .PresenzaPolicy::FINESTRA_MINUTI.' minuti dall\'arrivo previsto della linea.',
            ], 403);
        }

        $rimasto = Arrivo::annulla($linea, $momento)?->load('registrataDa');

        return response()->json([
            // What is recorded now: nothing, unless a newer arrival was already there.
            'ora' => $rimasto?->arrivata_alle->format('H:i'),
            'da' => $rimasto?->registrataDa === null ? null : trim("{$rimasto->registrataDa->nome} {$rimasto->registrataDa->cognome}"),
            'applicata' => $rimasto === null,
        ]);
    }

    /**
     * Children of the city that can be added to the stop for today: not already in its list.
     */
    public function cerca(Request $request, Fermata $fermata): JsonResponse
    {
        $utente = $this->accompagnatore($request);
        abort_unless($utente->puoOperareSu($fermata), 403);

        $ricerca = trim((string) $request->query('q', ''));

        // Nothing can be added at the destination, nor once the modification window has closed.
        if (mb_strlen($ricerca) < 2 || $fermata->destinazione || ! PresenzaPolicy::modificaAperta($fermata->linea)) {
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

    /**
     * The moment an action was really made, as stated by the phone. Without one, it is now.
     * A small clock difference is tolerated; an action in the future, or older than a day, is refused.
     */
    private function momentoAzione(?string $indicato): CarbonImmutable
    {
        $adesso = CarbonImmutable::now();

        if ($indicato === null) {
            return $adesso;
        }

        $momento = CarbonImmutable::parse($indicato)->setTimezone(config('app.timezone'));

        if ($momento->greaterThan($adesso->addMinutes(2))) {
            throw ValidationException::withMessages(['registrata_il' => 'L\'orario dell\'azione è nel futuro: controlla l\'orologio del telefono.']);
        }

        if ($momento->lessThan($adesso->subHours(24))) {
            throw ValidationException::withMessages(['registrata_il' => 'L\'azione è troppo vecchia per essere inviata (più di 24 ore).']);
        }

        // Never store a moment that has not happened yet.
        return $momento->greaterThan($adesso) ? $adesso : $momento;
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
