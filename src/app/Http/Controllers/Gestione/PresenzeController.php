<?php

namespace App\Http\Controllers\Gestione;

use App\Enums\Ruolo;
use App\Http\Controllers\Controller;
use App\Models\Bambino;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Policies\PresenzaPolicy;
use App\Support\CoperturaAccompagnatori;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance dashboard (read-only): for a day, every line the person can see with its stops,
 * who is present, who is absent and who has not been marked yet.
 *
 * Managers see their own lines; city administrators see every line of their city.
 */
class PresenzeController extends Controller
{
    public function index(Request $request): Response
    {
        $utente = $request->user();

        abort_unless($utente->eAdminCitta() || $utente->haRuolo(Ruolo::Responsabile), 403);

        $dati = $request->validate([
            'data' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $oggi = CarbonImmutable::today();
        $giorno = isset($dati['data']) ? CarbonImmutable::createFromFormat('Y-m-d', $dati['data'])->startOfDay() : $oggi;

        // The lines this person may see attendance of (policy), with what is needed to show them.
        $linee = Linea::query()
            ->with(['fermate.bambini', 'fermate.accompagnatori'])
            ->orderBy('nome')
            ->get()
            ->filter(fn (Linea $linea) => $utente->can('vedereLinea', [Presenza::class, $linea]))
            ->values();

        $presenze = Presenza::query()
            ->where('data', $giorno->toDateString())
            ->whereIn('linea_id', $linee->pluck('id'))
            ->with(['bambino', 'registrataDa'])
            ->get()
            ->groupBy('linea_id');

        $schede = $linee->map(fn (Linea $linea) => $this->scheda($linea, $presenze->get($linea->id, collect()), $giorno, $oggi))->values();

        return Inertia::render('presenze/Index', [
            'data' => $giorno->toDateString(),
            'data_estesa' => $giorno->translatedFormat('l j F Y'),
            'oggi' => $giorno->equalTo($oggi),
            'precedente' => $giorno->subDay()->toDateString(),
            'successiva' => $giorno->lessThan($oggi) ? $giorno->addDay()->toDateString() : null,
            'totali' => [
                'presenti' => $schede->sum('presenti'),
                'assenti' => $schede->sum('assenti'),
                'non_segnati' => $schede->sum('non_segnati'),
            ],
            'linee' => $schede,
        ]);
    }

    /**
     * One line on one day.
     *
     * @param  Collection<int, Presenza>  $presenzeDellaLinea
     * @return array<string, mixed>
     */
    private function scheda(Linea $linea, Collection $presenzeDellaLinea, CarbonImmutable $giorno, CarbonImmutable $oggi): array
    {
        // At most one attendance row per child on a line and day.
        $presenze = $presenzeDellaLinea->keyBy('bambino_id');
        $coperture = CoperturaAccompagnatori::perFermata($linea->fermate);

        $fermate = $linea->fermate->map(function (Fermata $fermata) use ($presenze, $coperture) {
            return [
                'id' => $fermata->id,
                'nome' => $fermata->nome,
                'orario' => substr($fermata->orario, 0, 5),
                'accompagnatori' => collect($coperture[$fermata->id])->map(fn (array $voce) => ['nome' => $voce['nome'], 'da' => $voce['da']])->all(),
                'bambini' => $this->bambiniDellaFermata($fermata, $presenze),
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
