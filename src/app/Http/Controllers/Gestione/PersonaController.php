<?php

namespace App\Http\Controllers\Gestione;

use App\Actions\InvitaPersona;
use App\Enums\Ruolo;
use App\Http\Controllers\Controller;
use App\Models\Citta;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The people of a city (administrators, managers, chaperones), managed by the
 * city administrators. Every query is limited to the administrator's own city
 * by the city scope; the policies check each action as well.
 */
class PersonaController extends Controller
{
    /** Roles a city administrator can give. */
    private const RUOLI_AMMESSI = [Ruolo::AdminCitta, Ruolo::Responsabile, Ruolo::Accompagnatore];

    public function index(Request $request): Response
    {
        $this->soloAdminCitta($request);

        $persone = User::query()
            ->with('ruoliAssegnati')
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get()
            ->map(fn (User $persona) => [
                'id' => $persona->id,
                'nome' => $persona->nome,
                'cognome' => $persona->cognome,
                'email' => $persona->email,
                'ruoli' => $persona->ruoli()->map(fn (Ruolo $ruolo) => $ruolo->etichetta())->values()->all(),
                'attivo' => $persona->password !== null,
                'sei_tu' => $persona->is($request->user()),
            ]);

        return Inertia::render('persone/Index', ['persone' => $persone]);
    }

    public function create(Request $request): Response
    {
        $this->soloAdminCitta($request);

        return Inertia::render('persone/Form', ['persona' => null]);
    }

    public function store(Request $request, InvitaPersona $invita): RedirectResponse
    {
        $this->soloAdminCitta($request);

        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $dati = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'cognome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            ...$this->regoleRuoli(),
        ]);

        // The city is always the administrator's own: it is never taken from the request.
        $citta = Citta::query()->findOrFail($request->user()->citta_id);
        $ruoli = $this->ruoliDa($dati['ruoli']);

        Gate::authorize('invitare', [User::class, $citta, $ruoli]);

        $persona = $invita($dati['nome'], $dati['cognome'], $dati['email'], $ruoli, $citta);

        return to_route('persone.index')->with('status', "Invito inviato a {$persona->email}.");
    }

    public function edit(Request $request, User $utente): Response
    {
        $this->soloAdminCitta($request);
        Gate::authorize('update', $utente);

        return Inertia::render('persone/Form', [
            'persona' => [
                'id' => $utente->id,
                'nome' => $utente->nome,
                'cognome' => $utente->cognome,
                'email' => $utente->email,
                'attivo' => $utente->password !== null,
                'ruoli' => $utente->ruoli()->map(fn (Ruolo $ruolo) => $ruolo->value)->values()->all(),
                'sei_tu' => $utente->is($request->user()),
            ],
        ]);
    }

    public function update(Request $request, User $utente, InvitaPersona $invita): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('update', $utente);

        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $dati = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'cognome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($utente->id)],
            ...$this->regoleRuoli(),
        ]);

        $nuovi = $this->ruoliDa($dati['ruoli']);
        Gate::authorize('assegnareRuoli', [$utente, $nuovi]);

        // Administrators cannot lock themselves out.
        if ($utente->is($request->user()) && ! in_array(Ruolo::AdminCitta, $nuovi, true)) {
            throw ValidationException::withMessages([
                'ruoli' => 'Non puoi togliere a te stesso il ruolo di amministratore di città.',
            ]);
        }

        $emailCambiata = $dati['email'] !== $utente->email;

        DB::transaction(function () use ($utente, $dati, $nuovi) {
            $utente->update(['nome' => $dati['nome'], 'cognome' => $dati['cognome'], 'email' => $dati['email']]);

            $attuali = $utente->ruoli()->all();

            // Enum cases are singletons, so strict in_array() compares them correctly.
            foreach ($nuovi as $ruolo) {
                if (! in_array($ruolo, $attuali, true)) {
                    $utente->assegnaRuolo($ruolo);
                }
            }

            foreach ($attuali as $ruolo) {
                if (! in_array($ruolo, $nuovi, true)) {
                    $utente->rimuoviRuolo($ruolo);
                }
            }
        });

        // Someone who has not activated the account yet gets the invitation again, at the new address.
        if ($emailCambiata && $utente->password === null) {
            $invita->invia($utente);

            return to_route('persone.index')->with('status', "Dati aggiornati. Invito inviato a {$utente->email}.");
        }

        return to_route('persone.index')->with('status', 'Dati aggiornati.');
    }

    public function reinvia(Request $request, User $utente, InvitaPersona $invita): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('update', $utente);

        try {
            $invita->invia($utente);
        } catch (DomainException $e) {
            return back()->with('errore', $e->getMessage());
        }

        return back()->with('status', "Invito inviato di nuovo a {$utente->email}.");
    }

    public function destroy(Request $request, User $utente): RedirectResponse
    {
        $this->soloAdminCitta($request);
        Gate::authorize('delete', $utente);

        $utente->delete();

        return to_route('persone.index')->with('status', 'Persona eliminata.');
    }

    /**
     * This area belongs to city administrators.
     */
    private function soloAdminCitta(Request $request): void
    {
        abort_unless($request->user()->eAdminCitta(), 403);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function regoleRuoli(): array
    {
        return [
            'ruoli' => ['required', 'array', 'min:1'],
            'ruoli.*' => ['string', Rule::in(array_map(fn (Ruolo $ruolo) => $ruolo->value, self::RUOLI_AMMESSI))],
        ];
    }

    /**
     * @param  list<string>  $valori
     * @return list<Ruolo>
     */
    private function ruoliDa(array $valori): array
    {
        return collect($valori)->unique()->map(fn (string $valore) => Ruolo::from($valore))->values()->all();
    }
}
