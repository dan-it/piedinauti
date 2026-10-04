<?php

namespace App\Policies;

use App\Models\Citta;
use App\Models\User;

/**
 * Only global administrators create and edit cities. Everybody can see their own city.
 * Abilities that are not defined here (such as delete) are denied.
 */
class CittaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->eAdminGlobale();
    }

    public function view(User $user, Citta $citta): bool
    {
        return $user->eAdminGlobale() || $user->appartieneACitta($citta->id);
    }

    public function create(User $user): bool
    {
        return $user->eAdminGlobale();
    }

    public function update(User $user, Citta $citta): bool
    {
        return $user->eAdminGlobale();
    }
}
