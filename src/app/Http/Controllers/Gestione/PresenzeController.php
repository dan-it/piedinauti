<?php

namespace App\Http\Controllers\Gestione;

use App\Enums\Ruolo;
use App\Http\Controllers\Controller;
use App\Models\Arrivo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Policies\PresenzaPolicy;
use App\Support\CoperturaAccompagnatori;
use App\Support\LineeVisibili;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance dashboard (read-only): for a day, every line the person can see with its stops,
 * who is present, who is absent and who has not been marked yet.
 *
 * Managers see their own lines; city administrators see every line of their city; global
 * administrators pick a city. Administrators can also correct any child's state, on any day and at
 * any time (chaperones are limited to the morning window, see PresenzaPolicy).
 */
class PresenzeController extends Controller
{
    public function index(Request $request): Response
    {
        $utente = $request->user();

        abort_unless($utente->eAdminGlobale() || $utente->eAdminCitta() || $utente->haRuolo(Ruolo::Responsabile), 403);

        $dati = $request->validate([
            'data' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'citta' => ['nullable', 'integer', 'exists:citta,id'],
        ]);

        $oggi = CarbonImmutable::today();
        $giorno = isset($dati['data']) ? CarbonImmutable::createFromFormat('Y-m-d', $dati['data'])->startOfDay() : $oggi;

        // Global administrators see one city at a time: the chosen one, or the first by name.
        $citte = LineeVisibili::citte($utente);
        $cittaScelta = LineeVisibili::cittaScelta($utente, isset($dati['citta']) ? (int) $dati['citta'] : null, $citte);

        // The lines this person may see attendance of (policy), with what is needed to show them.
        $linee = LineeVisibili::linee($utente, $cittaScelta, ['fermate.bambini', 'fermate.accompagnatori']);

        $presenze = Presenza::query()
            ->where('data', $giorno->toDateString())
            ->whereIn('linea_id', $linee->pluck('id'))
            ->with(['bambino', 'registrataDa'])
            ->get()
            ->groupBy('linea_id');

        $arrivi = Arrivo::query()
            ->where('data', $giorno->toDateString())
            ->whereIn('linea_id', $linee->pluck('id'))
            ->with('registrataDa')
            ->get()
            ->keyBy('linea_id');

        $schede = $linee->map(fn (Linea $linea) => $this->scheda($linea, $presenze->get($linea->id, collect()), $arrivi->get($linea->id), $giorno, $oggi))->values();

        return Inertia::render('presenze/Index', [
            'data' => $giorno->toDateString(),
            'data_estesa' => $giorno->translatedFormat('l j F Y'),
            'oggi' => $giorno->equalTo($oggi),
            'precedente' => $giorno->subDay()->toDateString(),
            'successiva' => $giorno->lessThan($oggi) ? $giorno->addDay()->toDateString() : null,
            // Administrators can change any state, on any day; managers only look.
            'puo_modificare' => $utente->eAdminGlobale() || $utente->eAdminCitta(),
            'citte' => $citte,
            'citta_scelta' => $cittaScelta,
            'totali' => [
                'presenti' => $schede->sum('presenti'),
                'assenti' => $schede->sum('assenti'),
                'non_segnati' => $schede->sum('non_segnati'),
            ],
            'linee' => $schede,
        ]);
    }

    /**
     * Set or clear one child's state at a stop on a day (administrators only, any day, any time).
     * "presente" true/false records it; null removes the record, back to "not marked".
     */
    public function correggi(Request $request): JsonResponse
    {
        $utente = $request->user();

        abort_unless($utente->eAdminGlobale() || $utente->eAdminCitta(), 403);

        $dati = $request->validate([
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'fermata_id' => ['required', 'integer'],
            'bambino_id' => ['required', 'integer'],
            'presente' => ['present', 'nullable', 'boolean'],
        ]);

        // A city administrator's queries are limited to their city: another city's stop is "not found".
        $fermata = Fermata::query()->find($dati['fermata_id']);

        if ($fermata === null) {
            return response()->json(['message' => 'Fermata non trovata.'], 404);
        }

        abort_unless($utente->can('correggere', [Presenza::class, $fermata]), 403);

        $bambino = Bambino::query()->find($dati['bambino_id']);

        if ($bambino === null || (int) $bambino->citta_id !== (int) $fermata->citta_id) {
            return response()->json(['message' => 'Bambino non trovato.'], 422);
        }

        $giorno = CarbonImmutable::createFromFormat('Y-m-d', $dati['data'])->startOfDay();

        $esistente = Presenza::query()
            ->where('data', $giorno->toDateString())
            ->where('linea_id', $fermata->linea_id)
            ->where('bambino_id', $bambino->id)
            ->first();

        if ($dati['presente'] === null) {
            $esistente?->delete();

            return response()->json(['bambino_id' => $bambino->id, 'stato' => null, 'temporaneo' => false, 'registrata_da' => null, 'registrata_alle' => null]);
        }

        // Keep "added for the day" as it was when correcting the same stop; otherwise a child who
        // is not assigned to the stop is a temporary one.
        $temporaneo = $esistente !== null && $esistente->fermata_id === $fermata->id
            ? $esistente->temporaneo
            : ! $fermata->bambini()->where('bambini.id', $bambino->id)->exists();

        // The correction is stamped "now": a chaperone's older mark that reaches the server later cannot undo it.
        $presenza = Presenza::registra($fermata, $bambino, $giorno, (bool) $dati['presente'], $temporaneo, $utente, now());

        return response()->json([
            'bambino_id' => $bambino->id,
            'stato' => $presenza->presente,
            'temporaneo' => $presenza->temporaneo,
            'registrata_da' => trim("{$utente->nome} {$utente->cognome}"),
            'registrata_alle' => $presenza->registrata_il?->format('H:i'),
        ]);
    }

    /**
     * Set or clear the arrival time of a line on a day (administrators only, any day, any time).
     * "ora" ("HH:MM") records it; null removes it, back to "not marked".
     */
    public function correggiArrivo(Request $request): JsonResponse
    {
        $utente = $request->user();

        abort_unless($utente->eAdminGlobale() || $utente->eAdminCitta(), 403);

        $dati = $request->validate([
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'linea_id' => ['required', 'integer'],
            'ora' => ['present', 'nullable', 'date_format:H:i'],
        ]);

        // A city administrator's queries are limited to their city: another city's line is "not found".
        $linea = Linea::query()->find($dati['linea_id']);

        if ($linea === null) {
            return response()->json(['message' => 'Linea non trovata.'], 404);
        }

        abort_unless($utente->can('correggereArrivo', [Presenza::class, $linea]), 403);

        if ($linea->destinazione() === null) {
            return response()->json(['message' => 'Questa linea non ha una destinazione.'], 422);
        }

        if ($dati['ora'] === null) {
            Arrivo::query()->where('data', $dati['data'])->where('linea_id', $linea->id)->delete();

            return response()->json(['ora' => null, 'da' => null]);
        }

        $momento = CarbonImmutable::createFromFormat('Y-m-d H:i', $dati['data'].' '.$dati['ora'], config('app.timezone'));

        if ($momento->isFuture()) {
            return response()->json(['message' => 'L\'orario di arrivo non può essere nel futuro.'], 422);
        }

        $arrivo = Arrivo::query()->updateOrCreate(
            ['data' => $dati['data'], 'linea_id' => $linea->id],
            ['citta_id' => $linea->citta_id, 'arrivata_alle' => $momento, 'registrata_da' => $utente->id],
        );

        return response()->json([
            'ora' => $arrivo->arrivata_alle->format('H:i'),
            'da' => trim("{$utente->nome} {$utente->cognome}"),
        ]);
    }

    /**
     * One line on one day.
     *
     * @param  Collection<int, Presenza>  $presenzeDellaLinea
     * @return array<string, mixed>
     */
    private function scheda(Linea $linea, Collection $presenzeDellaLinea, ?Arrivo $arrivoRegistrato, CarbonImmutable $giorno, CarbonImmutable $oggi): array
    {
        // At most one attendance row per child on a line and day.
        $presenze = $presenzeDellaLinea->keyBy('bambino_id');
        $coperture = CoperturaAccompagnatori::perFermata($linea->fermate);

        $fermate = $linea->fermate->map(function (Fermata $fermata) use ($presenze, $coperture) {
            return [
                'id' => $fermata->id,
                'nome' => $fermata->nome,
                'orario' => substr($fermata->orario, 0, 5),
                'destinazione' => $fermata->destinazione,
                'accompagnatori' => collect($coperture[$fermata->id])->map(fn (array $voce) => ['nome' => $voce['nome'], 'da' => $voce['da']])->all(),
                'bambini' => $fermata->destinazione ? [] : $this->bambiniDellaFermata($fermata, $presenze),
            ];
        })->values();

        $stati = $fermate->flatMap(fn (array $fermata) => collect($fermata['bambini'])->pluck('stato'));

        $arrivo = $linea->arrivoPrevisto($giorno);
        $eOggi = $giorno->equalTo($oggi);

        return [
            'id' => $linea->id,
            'nome' => $linea->nome,
            'arrivo' => $arrivo?->format('H:i'),
            // Today the line is "open" until 30 minutes after its arrival; any earlier day is closed.
            'chiusa' => ! $eOggi || ! PresenzaPolicy::modificaAperta($linea),
            'modificabile_fino' => $eOggi && $arrivo !== null ? $arrivo->addMinutes(PresenzaPolicy::FINESTRA_MINUTI)->format('H:i') : null,
            'con_destinazione' => $linea->fermate->contains('destinazione', true),
            // When the line actually arrived at its destination on the day, if it was marked.
            'arrivo_registrato' => $arrivoRegistrato === null ? null : [
                'ora' => $arrivoRegistrato->arrivata_alle->format('H:i'),
                'da' => $arrivoRegistrato->registrataDa === null ? null : trim("{$arrivoRegistrato->registrataDa->nome} {$arrivoRegistrato->registrataDa->cognome}"),
            ],
            'presenti' => $stati->filter(fn ($stato) => $stato === true)->count(),
            'assenti' => $stati->filter(fn ($stato) => $stato === false)->count(),
            'non_segnati' => $stati->filter(fn ($stato) => $stato === null)->count(),
            'fermate' => $fermate,
        ];
    }

    /**
     * The children listed under a stop on the day: those assigned to it who were not marked at
     * another stop, plus those marked at this stop (children added for the day included).
     *
     * @param  Collection<int, Presenza>  $presenze  the line's attendance of the day, by child
     * @return list<array<string, mixed>>
     */
    private function bambiniDellaFermata(Fermata $fermata, Collection $presenze): array
    {
        $voci = [];

        foreach ($fermata->bambini as $bambino) {
            $presenza = $presenze->get($bambino->id);

            // Marked at another stop of the line: listed there, not here.
            if ($presenza === null || $presenza->fermata_id === $fermata->id) {
                $voci[$bambino->id] = [$bambino, $presenza];
            }
        }

        foreach ($presenze as $presenza) {
            if ($presenza->fermata_id === $fermata->id && ! isset($voci[$presenza->bambino_id])) {
                $voci[$presenza->bambino_id] = [$presenza->bambino, $presenza];
            }
        }

        return collect($voci)
            ->sortBy(fn (array $voce) => $this->chiaveOrdine($voce[0]))
            ->map(function (array $voce) {
                /** @var Bambino $bambino */
                [$bambino, $presenza] = $voce;

                return [
                    'id' => $bambino->id,
                    'nome' => $bambino->nomeCompleto,
                    'stato' => $presenza?->presente,
                    'temporaneo' => $presenza !== null && $presenza->temporaneo,
                    'registrata_da' => $presenza?->registrataDa === null ? null : trim("{$presenza->registrataDa->nome} {$presenza->registrataDa->cognome}"),
                    // The time of day it was marked: the moment of the tap, which a phone without
                    // signal sends later. Rows saved before that was recorded show the saving time.
                    'registrata_alle' => ($presenza?->registrata_il ?? $presenza?->updated_at)?->format('H:i'),
                ];
            })
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
}
