<?php

namespace App\Policies;

use App\Models\Bambino;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Models\User;

/**
 * Attendance rules.
 *
 * Chaperones record attendance for today, at the stops where they are present (their starting
 * stop and every later one). Once FINESTRA_MINUTI minutes have passed since the line's expected
 * arrival, attendance can no longer be changed by anyone.
 */
class PresenzaPolicy
{
    public const FINESTRA_MINUTI = 30;

    /**
     * See the attendance of a whole line.
     */
    public function vedereLinea(User $user, Linea $linea): bool
    {
        return $user->appartieneACitta($linea->citta_id)
            && ($user->eAdminCitta() || $user->eResponsabileDi($linea));
    }

    /**
     * See the attendance of one stop.
     */
    public function vedereFermata(User $user, Fermata $fermata): bool
    {
        return $user->appartieneACitta($fermata->citta_id)
            && ($user->eAdminCitta() || $user->eResponsabileDi($fermata->linea) || $user->eAccompagnatoreDi($fermata));
    }

    /**
     * Record or change today's attendance of a child at a stop. The child may be assigned to the
     * stop or added for the day: any child of the city is accepted. Allowed only while the
     * modification window is open, for the first mark as well as for a correction.
     *
     * Usage: $user->can('registrare', [Presenza::class, $fermata, $bambino])
     */
    public function registrare(User $user, Fermata $fermata, Bambino $bambino): bool
    {
        if (! $user->appartieneACitta($fermata->citta_id) || (int) $bambino->citta_id !== (int) $fermata->citta_id) {
            return false;
        }

        if (! $user->eAccompagnatoreDi($fermata)) {
            return false;
        }

        // An archived line is hidden (null) or flagged: either way nothing is recorded on it.
        $linea = $fermata->linea;
        if ($linea === null || $linea->eArchiviata()) {
            return false;
        }

        return self::modificaAperta($linea);
    }

    /**
     * True while today's attendance of the line can still be recorded or changed:
     * until FINESTRA_MINUTI minutes after the line's expected arrival (the time of its last stop).
     */
    public static function modificaAperta(Linea $linea): bool
    {
        $arrivo = $linea->arrivoPrevisto(today());

        return $arrivo !== null
            && now()->lessThanOrEqualTo($arrivo->addMinutes(self::FINESTRA_MINUTI));
    }
}
