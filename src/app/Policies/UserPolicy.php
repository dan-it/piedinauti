<?php

namespace App\Policies;

use App\Enums\Ruolo;
use App\Models\Citta;
use App\Models\User;

/**
 * Who may see, invite and manage people.
 *
 * - Global administrators see and edit the data of everybody who holds an administrator role (also
 *   when that person holds other roles too), and can take the city administrator role away; they
 *   delete only people whose roles are all administrator roles. Managers and chaperones are managed
 *   by city administrators only.
 * - City administrators manage everybody in their own city, except global administrators.
 * - Managers may see the chaperones of their city, to assign them to stops.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->eAdminGlobale()
            || $user->eAdminCitta()
            || $user->haRuolo(Ruolo::Responsabile);
    }

    public function view(User $user, User $persona): bool
    {
        if ($user->is($persona)) {
            return true;
        }

        // A global administrator sees every administrator, including those with other roles too.
        if ($user->eAdminGlobale()) {
            return $this->haRuoloAmministratore($persona);
        }

        if (! $user->appartieneACitta($persona->citta_id)) {
            return false;
        }

        return $user->eAdminCitta()
            || ($user->haRuolo(Ruolo::Responsabile) && $persona->haRuolo(Ruolo::Accompagnatore));
    }

    /**
     * Invite a new person with the given roles into the given city
     * (null for a global administrator).
     *
     * Usage: $user->can('invitare', [User::class, $citta, $ruoli])
     *
     * @param  list<Ruolo>  $ruoli
     */
    public function invitare(User $user, ?Citta $citta, array $ruoli): bool
    {
        return $this->puoGestireRuoli($user, $citta?->id, $ruoli);
    }

    /**
     * Change the person's data.
     */
    public function update(User $user, User $persona): bool
    {
        // Name and email: global administrators can correct them for anybody holding an administrator role.
        if ($user->eAdminGlobale()) {
            return $this->haRuoloAmministratore($persona);
        }

        return $this->puoGestire($user, $persona);
    }

    /**
     * Take the city administrator role away from a person (they keep any other role).
     * Only global administrators do this; roles of managers and chaperones are never theirs to change.
     *
     * Usage: $user->can('revocareAmministratore', $persona)
     */
    public function revocareAmministratore(User $user, User $persona): bool
    {
        return $user->eAdminGlobale() && $persona->haRuolo(Ruolo::AdminCitta);
    }

    /**
     * Give the person these roles.
     *
     * Usage: $user->can('assegnareRuoli', [$persona, $ruoli])
     *
     * @param  list<Ruolo>  $ruoli
     */
    public function assegnareRuoli(User $user, User $persona, array $ruoli): bool
    {
        return $this->puoGestire($user, $persona)
            && $this->puoGestireRuoli($user, $persona->citta_id, $ruoli);
    }

    /**
     * Nobody can delete themselves from this screen.
     */
    public function delete(User $user, User $persona): bool
    {
        return ! $user->is($persona) && $this->puoGestire($user, $persona);
    }

    private function puoGestire(User $user, User $persona): bool
    {
        if ($user->eAdminGlobale()) {
            return $this->soloAmministratori($persona);
        }

        return $user->eAdminCitta()
            && $user->appartieneACitta($persona->citta_id)
            && ! $persona->eAdminGlobale();
    }

    /**
     * @param  list<Ruolo>  $ruoli
     */
    private function puoGestireRuoli(User $user, ?int $cittaId, array $ruoli): bool
    {
        if ($ruoli === []) {
            return false;
        }

        if ($user->eAdminGlobale()) {
            // Global administrators only create other administrators.
            return collect($ruoli)->every(
                fn (Ruolo $ruolo) => in_array($ruolo, [Ruolo::AdminGlobale, Ruolo::AdminCitta], true)
            );
        }

        return $user->eAdminCitta()
            && $user->appartieneACitta($cittaId)
            && ! in_array(Ruolo::AdminGlobale, $ruoli, true);
    }

    /**
     * True when the person holds at least one administrator role (global or of a city).
     */
    private function haRuoloAmministratore(User $persona): bool
    {
        return $persona->haRuolo(Ruolo::AdminGlobale) || $persona->haRuolo(Ruolo::AdminCitta);
    }

    /**
     * True when every role of the person is an administrator role.
     */
    private function soloAmministratori(User $persona): bool
    {
        $ruoli = $persona->ruoli();

        return $ruoli->isNotEmpty()
            && $ruoli->every(fn (Ruolo $ruolo) => in_array($ruolo, [Ruolo::AdminGlobale, Ruolo::AdminCitta], true));
    }
}
