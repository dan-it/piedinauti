<?php

namespace App\Enums;

/**
 * Roles a person can hold. A person may hold several roles at once.
 */
enum Ruolo: string
{
    case AdminGlobale = 'admin_globale';
    case AdminCitta = 'admin_citta';
    case Responsabile = 'responsabile';
    case Accompagnatore = 'accompagnatore';

    /**
     * Human-readable label shown in the (Italian) interface.
     */
    public function etichetta(): string
    {
        return match ($this) {
            self::AdminGlobale => 'Amministratore globale',
            self::AdminCitta => 'Amministratore di città',
            self::Responsabile => 'Responsabile',
            self::Accompagnatore => 'Accompagnatore',
        };
    }

    /**
     * Only global administrators live outside any city.
     */
    public function richiedeCitta(): bool
    {
        return $this !== self::AdminGlobale;
    }
}
