<?php

namespace App\Http\Controllers\Gestione;

use App\Actions\InvitaPersona;
use App\Enums\Ruolo;
use App\Http\Controllers\Controller;
use App\Models\Citta;
use App\Models\RuoloUtente;
use App\Models\User;
use App\Rules\EmailNonUsata;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrators (global and city ones), managed by global administrators.
 *
 * The list also shows administrators who hold other roles (manager, chaperone): those other roles,
 * and the people who hold only them, remain managed by city administrators. A global administrator
 * can correct an administrator's data and take the city administrator role away, with a warning if
 * the city is left without one.
 */
class AmministratoreController extends Controller
{
    /** Roles that make a person appear in this list. */
    private const RUOLI_AMMINISTRATORE = ['admin_globale', 'admin_citta'];

    public function index(Request $request): Response
    {
        $this->soloGlobali();

        $utente = $request->user();
        $amministratori = User::query()
            ->whereHas('ruoliAssegnati', fn ($query) => $query->whereIn('ruolo', self::RUOLI_AMMINISTRATORE))
            ->with(['citta', 'ruoliAssegnati'])
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get()
            ->filter(fn (User $persona) => $utente->can('view', $persona))
            ->map(function (User $persona) use ($utente) {
                $ruoli = $this->ruoliOrdinati($persona);
                // Roles that city administrators, not global ones, manage.
                $altriRuoli = $ruoli->reject(fn (Ruolo $ruolo) => in_array($ruolo, [Ruolo::AdminGlobale, Ruolo::AdminCitta], true));

                return [
                    'id' => $persona->id,
                    'nome' => $persona->nome,
                    'cognome' => $persona->cognome,
                    'email' => $persona->email,
                    'ruoli' => $ruoli->map(fn (Ruolo $ruolo) => $ruolo->etichetta())->values()->all(),
                    'altri_ruoli' => $altriRuoli->map(fn (Ruolo $ruolo) => $ruolo->etichetta())->values()->all(),
                    'citta' => $persona->citta?->nome,
                    'citta_id' => $persona->citta_id,
                    'attivo' => $persona->password !== null,
                    'sei_tu' => $persona->is($utente),
                    // Delete only people whose roles are all administrator roles; revoke when other roles remain.
                    'puo_eliminare' => $utente->can('delete', $persona),
                    'puo_revocare' => $utente->can('revocareAmministratore', $persona) && $altriRuoli->isNotEmpty(),
                ];
            })
            ->values();

        return Inertia::render('amministratori/Index', [
            'amministratori' => $amministratori,
            // For the city filter, and to flag the cities that have no administrator at all.
            'citte' => $this->elencoCitta(),
            'citte_senza_amministratori' => $this->citteSenzaAmministratori(),
        ]);
    }

    public function create(): Response
    {
        $this->soloGlobali();
        Gate::authorize('invitare', [User::class, null, [Ruolo::AdminGlobale]]);

        return Inertia::render('amministratori/Form', [
            'persona' => null,
            'citta' => $this->elencoCitta(),
        ]);
    }

    public function store(Request $request, InvitaPersona $invita): RedirectResponse
    {
        $this->soloGlobali();

        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $dati = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'cognome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new EmailNonUsata],
            'tipo' => ['required', Rule::in(['globale', 'citta'])],
            'citta_id' => ['required_if:tipo,citta', 'nullable', 'integer', 'exists:citta,id'],
        ]);

        $citta = $dati['tipo'] === 'citta' ? Citta::query()->findOrFail($dati['citta_id']) : null;
        $ruoli = [$citta === null ? Ruolo::AdminGlobale : Ruolo::AdminCitta];

        Gate::authorize('invitare', [User::class, $citta, $ruoli]);

        $persona = $invita($dati['nome'], $dati['cognome'], $dati['email'], $ruoli, $citta);

        return to_route('amministratori.index')
            ->with('status', "Invito inviato a {$persona->email}.");
    }

    public function edit(User $utente): Response
    {
        $this->soloGlobali();
        Gate::authorize('update', $utente);

        return Inertia::render('amministratori/Form', [
            'persona' => [
                'id' => $utente->id,
                'nome' => $utente->nome,
                'cognome' => $utente->cognome,
                'email' => $utente->email,
                'attivo' => $utente->password !== null,
            ],
            'citta' => [],
        ]);
    }

    public function update(Request $request, User $utente, InvitaPersona $invita): RedirectResponse
    {
        $this->soloGlobali();
        Gate::authorize('update', $utente);

        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $dati = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'cognome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new EmailNonUsata($utente->id)],
        ]);

        $emailCambiata = $dati['email'] !== $utente->email;
        $utente->update($dati);

        // Someone who has not activated the account yet gets the invitation again, at the new address.
        if ($emailCambiata && $utente->password === null) {
            $invita->invia($utente);

            return to_route('amministratori.index')
                ->with('status', "Dati aggiornati. Invito inviato a {$utente->email}.");
        }

        return to_route('amministratori.index')->with('status', 'Dati aggiornati.');
    }

    public function reinvia(User $utente, InvitaPersona $invita): RedirectResponse
    {
        $this->soloGlobali();
        Gate::authorize('update', $utente);

        try {
            $invita->invia($utente);
        } catch (DomainException $e) {
            return back()->with('errore', $e->getMessage());
        }

        return back()->with('status', "Invito inviato di nuovo a {$utente->email}.");
    }

    public function destroy(User $utente): RedirectResponse
    {
        $this->soloGlobali();
        Gate::authorize('delete', $utente);

        $ultimoGlobale = $utente->eAdminGlobale()
            && RuoloUtente::query()->where('ruolo', Ruolo::AdminGlobale->value)->count() <= 1;

        if ($ultimoGlobale) {
            return back()->with('errore', 'Non si può eliminare l\'ultimo amministratore globale.');
        }

        $cittaId = $utente->haRuolo(Ruolo::AdminCitta) ? $utente->citta_id : null;

        $utente->delete();

        return to_route('amministratori.index')
            ->with('status', 'Persona eliminata.')
            ->with('avviso', $this->avvisoCittaSenzaAmministratori($cittaId));
    }

    /**
     * Take the city administrator role away from a person who holds other roles too (they keep them).
     * A person whose only role this is is deleted instead. Warns if the city is left without administrators.
     */
    public function revocaRuoloCitta(User $utente): RedirectResponse
    {
        $this->soloGlobali();
        Gate::authorize('revocareAmministratore', $utente);

        $altriRuoli = $this->ruoliOrdinati($utente)->reject(fn (Ruolo $ruolo) => $ruolo === Ruolo::AdminCitta)->values();

        if ($altriRuoli->isEmpty()) {
            return back()->with('errore', 'Questa persona ha solo il ruolo di amministratore di città: per toglierglielo eliminala.');
        }

        $cittaId = $utente->citta_id;
        $utente->rimuoviRuolo(Ruolo::AdminCitta);

        $nome = trim("{$utente->nome} {$utente->cognome}");
        $rimasti = $altriRuoli->map(fn (Ruolo $ruolo) => mb_strtolower($ruolo->etichetta()))->join(', ', ' e ');

        return back()
            ->with('status', "{$nome} non è più amministratore di città. Resta: {$rimasti}.")
            ->with('avviso', $this->avvisoCittaSenzaAmministratori($cittaId));
    }

    /**
     * The person's roles in a fixed order (global, city, manager, chaperone), whatever the database returns.
     *
     * @return \Illuminate\Support\Collection<int, Ruolo>
     */
    private function ruoliOrdinati(User $persona): \Illuminate\Support\Collection
    {
        return $persona->ruoli()
            ->sortBy(fn (Ruolo $ruolo) => array_search($ruolo, Ruolo::cases(), true))
            ->values();
    }

    /**
     * A warning for the global administrator when a city has no city administrator any more, or null.
     */
    private function avvisoCittaSenzaAmministratori(?int $cittaId): ?string
    {
        if ($cittaId === null || $this->amministratoriDellaCitta($cittaId) > 0) {
            return null;
        }

        $nome = Citta::query()->whereKey($cittaId)->value('nome');

        return "Attenzione: la città «{$nome}» non ha più amministratori. Invitane uno nuovo, altrimenti nessuno potrà gestirla.";
    }

    private function amministratoriDellaCitta(int $cittaId): int
    {
        return User::query()
            ->where('citta_id', $cittaId)
            ->whereHas('ruoliAssegnati', fn ($query) => $query->where('ruolo', Ruolo::AdminCitta->value))
            ->count();
    }

    /**
     * Cities that have no city administrator.
     *
     * @return list<array{id: int, nome: string}>
     */
    private function citteSenzaAmministratori(): array
    {
        return Citta::query()
            ->whereDoesntHave('utenti', fn ($query) => $query->whereHas('ruoliAssegnati', fn ($ruoli) => $ruoli->where('ruolo', Ruolo::AdminCitta->value)))
            ->orderBy('nome')
            ->get(['id', 'nome'])
            ->map(fn (Citta $citta) => ['id' => $citta->id, 'nome' => $citta->nome])
            ->all();
    }

    /**
     * This area belongs to global administrators: city administrators manage
     * the people of their city from their own screens.
     */
    private function soloGlobali(): void
    {
        Gate::authorize('viewAny', Citta::class);
    }

    /**
     * @return list<array{id: int, nome: string}>
     */
    private function elencoCitta(): array
    {
        return Citta::query()->orderBy('nome')->get(['id', 'nome'])
            ->map(fn (Citta $citta) => ['id' => $citta->id, 'nome' => $citta->nome])
            ->all();
    }
}
