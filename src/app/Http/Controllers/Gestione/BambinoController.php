<?php

namespace App\Http\Controllers\Gestione;

use App\Http\Controllers\Controller;
use App\Models\Bambino;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The children of a city, managed by the city administrators.
 * Children are plain records (first name, optional surname): they never sign in.
 */
class BambinoController extends Controller
{
    private const PER_PAGINA = 25;

    public function index(Request $request): Response
    {
        $this->soloAdminCitta($request);

        $ricerca = trim((string) $request->query('q', ''));

        $bambini = Bambino::query()
            ->when($ricerca !== '', function ($query) use ($ricerca) {
                // Case-insensitive match on "first last" or "last first"; % and _ are literal.
                $parola = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($ricerca)).'%';

                $query->where(fn ($q) => $q
                    ->whereRaw("LOWER(nome || ' ' || cognome) LIKE ?", [$parola])
                    ->orWhereRaw("LOWER(cognome || ' ' || nome) LIKE ?", [$parola]));
            })
            // Children without a surname are sorted by their first name among the others.
            ->orderByRaw("LOWER(CASE WHEN cognome = '' THEN nome ELSE cognome END)")
            ->orderBy('nome')
            ->paginate(self::PER_PAGINA)
            ->withQueryString()
            ->through(fn (Bambino $bambino) => [
                'id' => $bambino->id,
                'nome' => $bambino->nome,
                'cognome' => $bambino->cognome,
            ]);

        return Inertia::render('bambini/Index', [
            'bambini' => $bambini,
            'ricerca' => $ricerca,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->soloAdminCitta($request);
        Gate::authorize('create', Bambino::class);

        return Inertia::render('bambini/Form', ['bambino' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('create', Bambino::class);

        // The city is the administrator's own: the model fills it in, never the request.
        $bambino = Bambino::query()->create($this->validati($request));

        return to_route('bambini.index')->with('status', "{$bambino->nome} {$bambino->cognome} aggiunto.");
    }

    public function edit(Request $request, Bambino $bambino): Response
    {
        $this->soloAdminCitta($request);
        Gate::authorize('update', $bambino);

        return Inertia::render('bambini/Form', [
            'bambino' => ['id' => $bambino->id, 'nome' => $bambino->nome, 'cognome' => $bambino->cognome],
        ]);
    }

    public function update(Request $request, Bambino $bambino): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('update', $bambino);

        $bambino->update($this->validati($request));

        return to_route('bambini.index')->with('status', 'Dati aggiornati.');
    }

    public function destroy(Request $request, Bambino $bambino): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('delete', $bambino);

        $bambino->delete();

        return to_route('bambini.index')->with('status', 'Bambino eliminato.');
    }

    /**
     * @return array{nome: string, cognome: string}
     */
    private function validati(Request $request): array
    {
        $request->merge([
            'nome' => trim((string) $request->input('nome')),
            'cognome' => trim((string) $request->input('cognome')),
        ]);

        $dati = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'cognome' => ['nullable', 'string', 'max:255'],
        ]);

        // The surname is optional: store an empty string when it is left out.
        $dati['cognome'] = (string) ($dati['cognome'] ?? '');

        return $dati;
    }

    private function soloAdminCitta(Request $request): void
    {
        abort_unless($request->user()->eAdminCitta(), 403);
    }
}
