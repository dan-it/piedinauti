<?php

namespace App\Policies;

use App\Models\Fermata;
use App\Models\Linea;
use App\Models\User;

/**
 * Stops follow their line: city administrators manage them, managers assign
 * chaperones and children on their own lines.
 */
class FermataPolicy
{
    /**
     * A chaperone sees every stop of the lines where they have a stop of their own.
     */
    public function view(User $user, Fermata $fermata): bool
    {
        if (! $user->appartieneACitta($fermata->citta_id)) {
            return false;
        }

        return $user->eAdminCitta()
            || $user->eResponsabileDi($fermata->linea)
            || $user->eAccompagnatoreSu($fermata->linea);
    }

    /**
     * Add a stop. Usage: $user->can('create', [Fermata::class, $linea])
     */
    public function create(User $user, ?Linea $linea = null): bool
    {
        return $user->eAdminCitta() && ($linea === null || $user->appartieneACitta($linea->citta_id));
    }

    public function update(User $user, Fermata $fermata): bool
    {
        return $user->eAdminCitta() && $user->appartieneACitta($fermata->citta_id);
    }

    public function delete(User $user, Fermata $fermata): bool
    {
        return $this->update($user, $fermata);
    }

    /**
     * Assign chaperones and children to the stop.
     */
    public function gestireAssegnazioni(User $user, Fermata $fermata): bool
    {
        if (! $user->appartieneACitta($fermata->citta_id)) {
            return false;
        }

        return $user->eAdminCitta() || $user->eResponsabileDi($fermata->linea);
    }
}
