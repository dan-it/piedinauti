<?php

namespace App\Policies;

use App\Enums\Ruolo;
use App\Models\Linea;
use App\Models\User;

/**
 * City administrators create and manage lines (including who is in charge of them).
 * Managers see their own lines; chaperones see the lines where they have a stop.
 */
class LineaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->eAdminCitta()
            || $user->haRuolo(Ruolo::Responsabile)
            || $user->haRuolo(Ruolo::Accompagnatore);
    }

    public function view(User $user, Linea $linea): bool
    {
        if (! $user->appartieneACitta($linea->citta_id)) {
            return false;
        }

        return $user->eAdminCitta()
            || $user->eResponsabileDi($linea)
            || $user->eAccompagnatoreSu($linea);
    }

    public function create(User $user): bool
    {
        return $user->eAdminCitta();
    }

    /**
     * Edit the line, its stops and its managers.
     */
    public function update(User $user, Linea $linea): bool
    {
        return $user->eAdminCitta() && $user->appartieneACitta($linea->citta_id);
    }

    public function delete(User $user, Linea $linea): bool
    {
        return $this->update($user, $linea);
    }

    /**
     * Assign chaperones and children to the stops of the line.
     */
    public function gestireAssegnazioni(User $user, Linea $linea): bool
    {
        return $user->appartieneACitta($linea->citta_id)
            && ($user->eAdminCitta() || $user->eResponsabileDi($linea));
    }

    /**
     * Copy a line with all its stops into a new line (for example to build the return trip).
     */
    public function duplicare(User $user, Linea $linea): bool
    {
        return $user->eAdminCitta() && $user->appartieneACitta($linea->citta_id);
    }

    /**
     * Archive a line: it disappears for everybody but global administrators.
     */
    public function archiviare(User $user, Linea $linea): bool
    {
        return $user->eAdminCitta() && $user->appartieneACitta($linea->citta_id) && ! $linea->eArchiviata();
    }

    /**
     * Bring an archived line back. Only global administrators can: they are the only ones who see it.
     */
    public function ripristinare(User $user, Linea $linea): bool
    {
        return $user->eAdminGlobale() && $linea->eArchiviata();
    }

    /**
     * See the list of archived lines (all cities).
     */
    public function vedereArchiviate(User $user): bool
    {
        return $user->eAdminGlobale();
    }
}
