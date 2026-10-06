<?php

namespace App\Policies;

use App\Models\Bambino;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Attendance rules.
 *
 * Chaperones record attendance for today, at the stops where they are present (their starting
 * stop and every later one). Once FINESTRA_MINUTI minutes have passed since the line's expected
 * arrival, chaperones can no longer change it. Administrators can correct attendance at any
 * time, on any day (see correggere()).
 */
class PresenzaPolicy
{
    public const FINESTRA_MINUTI = 30;

    /**
     * See the attendance of a whole line.
     */
    public function vedereLinea(User $user, Linea $linea): bool
    {
        return $user->eAdminGlobale()
            || ($user->appartieneACitta($linea->citta_id) && ($user->eAdminCitta() || $user->eResponsabileDi($linea)));
    }

    /**
     * Correct the attendance recorded at a stop, whatever the day and the time. Reserved to
     * administrators: city administrators in their own city, global administrators everywhere.
     *
     * Usage: $user->can('correggere', [Presenza::class, $fermata])
     */
    public function correggere(User $user, Fermata $fermata): bool
    {
        return $user->eAdminGlobale()
            || ($user->eAdminCitta() && $user->appartieneACitta($fermata->citta_id));
    }

    /**
     * See the attendance of one stop. Chaperones also see the stops just before their own, as many as
     * the line allows (and can mark them, see registrare()).
     */
    public function vedereFermata(User $user, Fermata $fermata): bool
    {
        return $user->appartieneACitta($fermata->citta_id)
            && ($user->eAdminCitta() || $user->eResponsabileDi($fermata->linea) || $user->puoOperareSu($fermata));
    }

    /**
     * Record or change today's attendance of a child at a stop. The child may be assigned to the
     * stop or added for the day: any child of the city is accepted. Allowed only while the
     * modification window is open, for the first mark as well as for a correction.
     *
     * $momento is when the mark was made (default: now). A device without signal sends its marks
     * later, so the window is judged at the moment of the tap, not at the moment of arrival.
     *
     * Usage: $user->can('registrare', [Presenza::class, $fermata, $bambino, $momento])
     */
    public function registrare(User $user, Fermata $fermata, Bambino $bambino, ?CarbonInterface $momento = null): bool
    {
        if (! $user->appartieneACitta($fermata->citta_id) || (int) $bambino->citta_id !== (int) $fermata->citta_id) {
            return false;
        }

        // The stops where the chaperone is present, and the few before them that the line allows.
        if (! $user->puoOperareSu($fermata)) {
            return false;
        }

        // An archived line is hidden (null) or flagged: either way nothing is recorded on it.
        $linea = $fermata->linea;
        if ($linea === null || $linea->eArchiviata()) {
            return false;
        }

        // Nobody boards at the destination: only the arrival is marked there.
        if ($fermata->destinazione) {
            return false;
        }

        return self::modificaAperta($linea, $momento);
    }

    /**
     * Mark that the line has arrived at its destination. Like attendance, it can be done by the
     * chaperones present at the destination (everybody from their starting stop on) while the
     * modification window is open.
     *
     * Usage: $user->can('registrareArrivo', [Presenza::class, $linea, $momento])
     */
    public function registrareArrivo(User $user, Linea $linea, ?CarbonInterface $momento = null): bool
    {
        if (! $user->appartieneACitta($linea->citta_id) || $linea->eArchiviata()) {
            return false;
        }

        $destinazione = $linea->destinazione();

        return $destinazione !== null
            && $user->eAccompagnatoreDi($destinazione)
            && self::modificaAperta($linea, $momento);
    }

    /**
     * Set or clear the arrival time of a line on any day: administrators only, at any time.
     *
     * Usage: $user->can('correggereArrivo', [Presenza::class, $linea])
     */
    public function correggereArrivo(User $user, Linea $linea): bool
    {
        return $user->eAdminGlobale()
            || ($user->eAdminCitta() && $user->appartieneACitta($linea->citta_id));
    }

    /**
     * True while attendance of the line can still be recorded or changed by chaperones:
     * until FINESTRA_MINUTI minutes after the line's expected arrival (the time of its last stop)
     * on the day of $momento (default: now).
     */
    public static function modificaAperta(Linea $linea, ?CarbonInterface $momento = null): bool
    {
        $momento ??= now();
        $arrivo = $linea->arrivoPrevisto($momento);

        return $arrivo !== null
            && $momento->lessThanOrEqualTo($arrivo->addMinutes(self::FINESTRA_MINUTI));
    }
}
