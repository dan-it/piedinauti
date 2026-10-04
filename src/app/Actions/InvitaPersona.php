<?php

namespace App\Actions;

use App\Enums\Ruolo;
use App\Models\Citta;
use App\Models\User;
use App\Notifications\InvitoNotification;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creates a person and emails them the link to choose a password.
 * Authorization (who may invite whom) is checked by the caller.
 */
class InvitaPersona
{
    /**
     * @param  non-empty-list<Ruolo>  $ruoli
     *
     * @throws \InvalidArgumentException when roles and city do not match
     */
    public function __invoke(string $nome, string $cognome, string $email, array $ruoli, ?Citta $citta = null): User
    {
        // Create the person and the roles together: a failure leaves nothing behind.
        $utente = DB::transaction(function () use ($nome, $cognome, $email, $ruoli, $citta) {
            $utente = User::query()->create([
                'nome' => $nome,
                'cognome' => $cognome,
                'email' => Str::lower($email),
                'citta_id' => $citta?->id,
            ]);

            foreach ($ruoli as $ruolo) {
                $utente->assegnaRuolo($ruolo);
            }

            return $utente;
        });

        $this->invia($utente);

        return $utente;
    }

    /**
     * (Re)send the invitation. A new link invalidates the previous one.
     *
     * @throws DomainException when the person has already chosen a password
     */
    public function invia(User $utente): void
    {
        if ($utente->password !== null) {
            throw new DomainException('La persona ha già impostato la password.');
        }

        // The "inviti" broker issues links that last longer than normal resets.
        $token = Password::broker('inviti')->createToken($utente);

        $utente->notify(new InvitoNotification($token));
    }
}
