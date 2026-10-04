<?php

namespace App\Policies;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\User;

/**
 * City administrators manage the children of their city. Managers and chaperones
 * can see them: chaperones need the whole city list to add a child for one day.
 */
class BambinoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->eAdminCitta()
            || $user->haRuolo(Ruolo::Responsabile)
            || $user->haRuolo(Ruolo::Accompagnatore);
    }

    public function view(User $user, Bambino $bambino): bool
    {
        return $this->viewAny($user) && $user->appartieneACitta($bambino->citta_id);
    }

    public function create(User $user): bool
    {
        return $user->eAdminCitta();
    }

    public function update(User $user, Bambino $bambino): bool
    {
        return $user->eAdminCitta() && $user->appartieneACitta($bambino->citta_id);
    }

    public function delete(User $user, Bambino $bambino): bool
    {
        return $this->update($user, $bambino);
    }
}
