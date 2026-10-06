<?php

namespace App\Http\Controllers\Gestione;

use App\Enums\Ruolo;
use App\Http\Controllers\Controller;
use App\Models\Arrivo;
use App\Models\Bambino;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Support\LineeVisibili;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attendance reports (read-only), for a period and for the lines the person may look at:
 * managers their own lines, city administrators their city, global administrators one city at a time.
 *
 * Attendance rates count only the days a child was actually marked: a day with no mark says
 * nothing about the child, so it is neither a presence nor an absence.
 */
class ReportController extends Controller
{
    /** Default period: the last 30 days, today included. */
    private const GIORNI_PREDEFINITI = 30;

    /** Longest period that can be asked for, in days. */
    private const GIORNI_MASSIMI = 366;

    /**
     * By line and by day, for the period.
     */
    public function index(Request $request): Response
    {
        $c = $this->contesto($request);
        $ids = $c['linee']->pluck('id')->all();

        // One row per line.
        $perLinea = $this->aggregati($ids, $c['da'], $c['a'], 'linea_id', 'COUNT(DISTINCT data) AS giorni, COUNT(DISTINCT bambino_id) AS bambini');
        $puntualita = $this->puntualita($c['linee'], $c['da'], $c['a']);

        $riepilogo = $c['linee']->map(function (Linea $linea) use ($perLinea, $puntualita) {
            $r = $perLinea->get($linea->id);

            return [
                'id' => $linea->id,
                'nome' => $linea->nome,
                'giorni' => (int) ($r->giorni ?? 0),
                'presenti' => (int) ($r->presenti ?? 0),
                'assenti' => (int) ($r->assenti ?? 0),
                'bambini' => (int) ($r->bambini ?? 0),
                'frequenza' => $this->frequenza((int) ($r->presenti ?? 0), (int) ($r->assenti ?? 0)),
                'puntualita' => $puntualita[$linea->id] ?? null,
            ];
        })->values();

        // One row per day: the chosen line, or every visible line together.
        $idsGiorni = $c['linea'] === null ? $ids : [$c['linea']->id];
        $giorni = $this->perGiorno($idsGiorni, $c['da'], $c['a'], $c['linea']);

        $presenti = $riepilogo->sum('presenti');
        $assenti = $riepilogo->sum('assenti');

        return Inertia::render('report/Index', $this->filtri($c) + [
            'totali' => [
                'presenti' => $presenti,
                'assenti' => $assenti,
                'frequenza' => $this->frequenza($presenti, $assenti),
                'giorni' => $giorni->count(),
            ],
            'linee_riepilogo' => $riepilogo,
            'giorni' => $giorni,
        ]);
    }

    /**
     * By child, for the period.
     */
    public function bambini(Request $request): Response
    {
        $c = $this->contesto($request);
        $ids = $this->idsLinee($c);

        $dati = $this->aggregati($ids, $c['da'], $c['a'], 'bambino_id', 'COUNT(DISTINCT data) AS giorni, SUM(CASE WHEN temporaneo THEN 1 ELSE 0 END) AS temporanei');

        // Children with marks in the period, plus those assigned to these lines (who may have none).
        $bambini = Bambino::query()
            ->where(fn ($query) => $query
                ->whereIn('id', $dati->keys())
                ->orWhereHas('fermate', fn ($q) => $q->whereIn('fermate.linea_id', $ids)))
            ->get()
            ->sortBy(fn (Bambino $bambino) => $this->chiaveOrdine($bambino))
            ->map(function (Bambino $bambino) use ($dati) {
                $r = $dati->get($bambino->id);

                return [
                    'id' => $bambino->id,
                    'nome' => $bambino->nomeCompleto,
                    'giorni' => (int) ($r->giorni ?? 0),
                    'presenti' => (int) ($r->presenti ?? 0),
                    'assenti' => (int) ($r->assenti ?? 0),
                    'temporanei' => (int) ($r->temporanei ?? 0),
                    'frequenza' => $this->frequenza((int) ($r->presenti ?? 0), (int) ($r->assenti ?? 0)),
                ];
            })
            ->values();

        return Inertia::render('report/Bambini', $this->filtri($c) + ['bambini' => $bambini]);
    }

    /**
     * One child, day by day, for the period.
     */
    public function bambino(Request $request, Bambino $bambino): Response
    {
        $c = $this->contesto($request);
        $ids = $this->idsLinee($c);

        $assegnato = Fermata::query()
            ->whereIn('linea_id', $ids)
            ->whereHas('bambini', fn ($query) => $query->where('bambini.id', $bambino->id))
            ->exists();
        $segnato = Presenza::query()->whereIn('linea_id', $ids)->where('bambino_id', $bambino->id)->exists();

        // Administrators see every child of their city; managers only children of their own lines.
        $amministratore = $request->user()->eAdminCitta()
            || ($request->user()->eAdminGlobale() && $c['cittaScelta'] === (int) $bambino->citta_id);

        abort_unless($amministratore || $assegnato || $segnato, 404);

        $registri = Presenza::query()
            ->where('bambino_id', $bambino->id)
            ->whereIn('linea_id', $ids)
            ->whereBetween('data', [$c['da']->toDateString(), $c['a']->toDateString()])
            ->with(['linea', 'fermata', 'registrataDa'])
            ->orderByDesc('data')
            ->orderBy('id')
            ->get();

        $presenti = $registri->where('presente', true)->count();
        $assenti = $registri->where('presente', false)->count();

        return Inertia::render('report/Bambino', $this->filtri($c) + [
            'bambino' => ['id' => $bambino->id, 'nome' => $bambino->nomeCompleto],
            'totali' => [
                'giorni' => $registri->pluck('data')->map(fn ($data) => $data->toDateString())->unique()->count(),
                'presenti' => $presenti,
                'assenti' => $assenti,
                'temporanei' => $registri->where('temporaneo', true)->count(),
                'frequenza' => $this->frequenza($presenti, $assenti),
            ],
            'registri' => $registri->map(fn (Presenza $presenza) => [
                'id' => $presenza->id,
                'data' => $presenza->data->toDateString(),
                'linea' => $presenza->linea?->nome,
                'fermata' => $presenza->fermata?->nome,
                'stato' => $presenza->presente,
                'temporaneo' => $presenza->temporaneo,
                'registrata_da' => $presenza->registrataDa === null ? null : trim("{$presenza->registrataDa->nome} {$presenza->registrataDa->cognome}"),
                'registrata_alle' => ($presenza->registrata_il ?? $presenza->updated_at)?->format('H:i'),
            ])->values(),
        ]);
    }

    /**
     * The attendance of the period as a CSV file (opens in Excel and similar programs).
     */
    public function esporta(Request $request): StreamedResponse
    {
        $c = $this->contesto($request);
        $ids = $this->idsLinee($c);
        $da = $c['da']->toDateString();
        $a = $c['a']->toDateString();

        return response()->streamDownload(function () use ($ids, $da, $a) {
            $uscita = fopen('php://output', 'w');

            // The byte order mark makes Excel read the accents correctly.
            fwrite($uscita, "\xEF\xBB\xBF");
            fputcsv($uscita, ['Data', 'Linea', 'Fermata', 'Bambino', 'Stato', 'Solo quel giorno', 'Segnato da', 'Segnato alle'], ';');

            Presenza::query()
                ->whereIn('linea_id', $ids)
                ->whereBetween('data', [$da, $a])
                ->with(['linea', 'fermata', 'bambino', 'registrataDa'])
                ->orderBy('data')
                ->orderBy('linea_id')
                ->orderBy('fermata_id')
                ->orderBy('id')
                ->chunk(500, function (Collection $presenze) use ($uscita) {
                    foreach ($presenze as $presenza) {
                        fputcsv($uscita, array_map($this->sicuraPerFoglio(...), [
                            $presenza->data->format('d/m/Y'),
                            $presenza->linea?->nome ?? '',
                            $presenza->fermata?->nome ?? '',
                            $presenza->bambino?->nomeCompleto ?? '',
                            $presenza->presente ? 'Presente' : 'Assente',
                            $presenza->temporaneo ? 'sì' : 'no',
                            $presenza->registrataDa === null ? '' : trim("{$presenza->registrataDa->nome} {$presenza->registrataDa->cognome}"),
                            ($presenza->registrata_il ?? $presenza->updated_at)?->format('H:i') ?? '',
                        ]), ';');
                    }
                });

            fclose($uscita);
        }, "presenze-{$da}_{$a}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------ the filters

    /**
     * Who is asking, which period, which lines.
     *
     * @return array{citte: Collection, cittaScelta: ?int, linee: Collection, linea: ?Linea, da: CarbonImmutable, a: CarbonImmutable}
     */
    private function contesto(Request $request): array
    {
        $utente = $request->user();

        abort_unless($utente->eAdminGlobale() || $utente->eAdminCitta() || $utente->haRuolo(Ruolo::Responsabile), 403);

        $dati = $request->validate([
            'da' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'a' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'linea' => ['nullable', 'integer'],
            'citta' => ['nullable', 'integer', 'exists:citta,id'],
        ]);

        $a = isset($dati['a']) ? CarbonImmutable::createFromFormat('Y-m-d', $dati['a'])->startOfDay() : CarbonImmutable::today();
        $da = isset($dati['da']) ? CarbonImmutable::createFromFormat('Y-m-d', $dati['da'])->startOfDay() : $a->subDays(self::GIORNI_PREDEFINITI - 1);

        if ($da->greaterThan($a)) {
            throw ValidationException::withMessages(['da' => 'La data di inizio deve essere uguale o precedente a quella di fine.']);
        }

        if ($da->diffInDays($a) >= self::GIORNI_MASSIMI) {
            throw ValidationException::withMessages(['da' => 'Il periodo non può superare '.self::GIORNI_MASSIMI.' giorni.']);
        }

        $citte = LineeVisibili::citte($utente);
        $cittaScelta = LineeVisibili::cittaScelta($utente, isset($dati['citta']) ? (int) $dati['citta'] : null, $citte);
        $linee = LineeVisibili::linee($utente, $cittaScelta);

        $linea = null;
        if (isset($dati['linea'])) {
            $linea = $linee->firstWhere('id', (int) $dati['linea']);

            if ($linea === null) {
                throw ValidationException::withMessages(['linea' => 'Linea non trovata.']);
            }
        }

        return compact('citte', 'cittaScelta', 'linee', 'linea', 'da', 'a');
    }

    /**
     * What every report page needs to draw its filter bar.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function filtri(array $c): array
    {
        return [
            'da' => $c['da']->toDateString(),
            'a' => $c['a']->toDateString(),
            'linea_scelta' => $c['linea']?->id,
            'linee' => $c['linee']->map(fn (Linea $linea) => ['id' => $linea->id, 'nome' => $linea->nome])->values(),
            'citte' => $c['citte'],
            'citta_scelta' => $c['cittaScelta'],
        ];
    }

    /**
     * Ids of the lines a report covers: the chosen line, or all the visible ones.
     *
     * @param  array<string, mixed>  $c
     * @return list<int>
     */
    private function idsLinee(array $c): array
    {
        return $c['linea'] === null ? $c['linee']->pluck('id')->all() : [$c['linea']->id];
    }

    // ---------------------------------------------------------------- numbers

    /**
     * Presences and absences in the period, summed by a column, plus extra columns.
     * The lines come from the policy check above, so the rows are limited to what the person may see.
     *
     * @param  list<int>  $ids
     * @return Collection<int, object>
     */
    private function aggregati(array $ids, CarbonImmutable $da, CarbonImmutable $a, string $per, string $extra): Collection
    {
        return DB::table('presenze')
            ->whereIn('linea_id', $ids)
            ->whereBetween('data', [$da->toDateString(), $a->toDateString()])
            ->selectRaw("{$per}, SUM(CASE WHEN presente THEN 1 ELSE 0 END) AS presenti, SUM(CASE WHEN presente THEN 0 ELSE 1 END) AS assenti, {$extra}")
            ->groupBy($per)
            ->get()
            ->keyBy($per);
    }

    /**
     * The days of the period that have marks, with presences and absences; for a line with a
     * destination also when it arrived against when it was expected.
     *
     * @param  list<int>  $ids
     * @return Collection<int, array<string, mixed>>
     */
    private function perGiorno(array $ids, CarbonImmutable $da, CarbonImmutable $a, ?Linea $linea): Collection
    {
        $righe = DB::table('presenze')
            ->whereIn('linea_id', $ids)
            ->whereBetween('data', [$da->toDateString(), $a->toDateString()])
            ->selectRaw('data, SUM(CASE WHEN presente THEN 1 ELSE 0 END) AS presenti, SUM(CASE WHEN presente THEN 0 ELSE 1 END) AS assenti')
            ->groupBy('data')
            ->orderBy('data')
            ->get()
            ->keyBy(fn ($riga) => substr((string) $riga->data, 0, 10));

        // Arrivals are per line: shown only when a single line is chosen.
        $arrivi = collect();
        $previsto = null;
        if ($linea !== null) {
            $arrivi = Arrivo::query()
                ->where('linea_id', $linea->id)
                ->whereBetween('data', [$da->toDateString(), $a->toDateString()])
                ->get()
                ->keyBy(fn (Arrivo $arrivo) => $arrivo->data->toDateString());
            $previsto = $linea->destinazione()?->orario;
        }

        // A day with an arrival but no marks still belongs in the list.
        $date = $righe->keys()->merge($arrivi->keys())->unique()->sort()->values();

        return $date->map(function (string $data) use ($righe, $arrivi, $previsto) {
            $riga = $righe->get($data);
            $arrivo = $arrivi->get($data);

            return [
                'data' => $data,
                'presenti' => (int) ($riga->presenti ?? 0),
                'assenti' => (int) ($riga->assenti ?? 0),
                'arrivo' => $arrivo?->arrivata_alle->format('H:i'),
                'scarto_minuti' => $arrivo !== null && $previsto !== null ? $this->scarto($arrivo, $previsto) : null,
            ];
        });
    }

    /**
     * Punctuality of each line with a destination: average minutes between the expected arrival
     * and the real one (positive = late) over the days an arrival was marked.
     *
     * @param  Collection<int, Linea>  $linee
     * @return array<int, array{arrivi: int, scarto_medio: float|null}>
     */
    private function puntualita(Collection $linee, CarbonImmutable $da, CarbonImmutable $a): array
    {
        $risultato = [];
        $arrivi = Arrivo::query()
            ->whereIn('linea_id', $linee->pluck('id'))
            ->whereBetween('data', [$da->toDateString(), $a->toDateString()])
            ->get()
            ->groupBy('linea_id');

        foreach ($linee as $linea) {
            $previsto = $linea->destinazione()?->orario;

            if ($previsto === null) {
                continue;
            }

            $scarti = $arrivi->get($linea->id, collect())->map(fn (Arrivo $arrivo) => $this->scarto($arrivo, $previsto));

            $risultato[$linea->id] = [
                'arrivi' => $scarti->count(),
                'scarto_medio' => $scarti->isEmpty() ? null : round($scarti->avg(), 1),
            ];
        }

        return $risultato;
    }

    /**
     * Minutes between the expected time ("HH:MM:SS") and the real arrival, on the arrival's day.
     */
    private function scarto(Arrivo $arrivo, string $previsto): int
    {
        $atteso = CarbonImmutable::parse($arrivo->data->toDateString().' '.$previsto, config('app.timezone'));

        return (int) round(($arrivo->arrivata_alle->getTimestamp() - $atteso->getTimestamp()) / 60);
    }

    /**
     * Share of presences among the marked days, as a percentage with one decimal; null when nothing
     * was marked (an unmarked day is unknown, not an absence).
     */
    private function frequenza(int $presenti, int $assenti): ?float
    {
        $totale = $presenti + $assenti;

        return $totale === 0 ? null : round($presenti * 100 / $totale, 1);
    }

    /**
     * Children are sorted by surname, or by first name when they have no surname.
     */
    private function chiaveOrdine(Bambino $bambino): string
    {
        return mb_strtolower(($bambino->cognome !== '' ? $bambino->cognome : $bambino->nome).' '.$bambino->nome);
    }

    /**
     * Spreadsheets run text that starts with = + - @ (or a tab or carriage return) as a formula:
     * a name like that must not.
     */
    private function sicuraPerFoglio(string $valore): string
    {
        return $valore !== '' && str_contains("=+-@\t\r", $valore[0]) ? "'".$valore : $valore;
    }
}
