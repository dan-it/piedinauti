<?php

namespace App\Http\Controllers\Gestione;

use App\Actions\InvitaPersona;
use App\Enums\Ruolo;
use App\Http\Controllers\Controller;
use App\Models\Citta;
use App\Models\RuoloUtente;
use App\Models\User;
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
 */
class AmministratoreController extends Controller
{
    public function index(Request $request): Response
    {
        $this->soloGlobali();

        $amministratori = User::query()
            ->whereHas('ruoliAssegnati', fn ($query) => $query->whereIn('ruolo', [
                Ruolo::AdminGlobale->value,
                Ruolo::AdminCitta->value,
            ]))
            ->with(['citta', 'ruoliAssegnati'])
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get()
            // People who also hold other roles are managed by their city administrator.
            ->filter(fn (User $persona) => $request->user()->can('view', $persona))
            ->map(fn (User $persona) => [
                'id' => $persona->id,
                'nome' => $persona->nome,
                'cognome' => $persona->cognome,
                'email' => $persona->email,
                'ruoli' => $persona->ruoli()->map(fn (Ruolo $ruolo) => $ruolo->etichetta())->values()->all(),
                'citta' => $persona->citta?->nome,
                'attivo' => $persona->password !== null,
            ])
            ->values();

        return Inertia::render('amministratori/Index', ['amministratori' => $amministratori]);
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
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
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
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($utente->id)],
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

        $utente->delete();

        return to_route('amministratori.index')->with('status', 'Persona eliminata.');
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
