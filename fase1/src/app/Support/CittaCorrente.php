<?php

namespace App\Support;

use Closure;

/**
 * Holds the "current city" restriction for the running request.
 *
 * - Not restricted: queries are not filtered (global administrators, console
 *   commands, queue jobs, guests such as the login form).
 * - Restricted to a city id: queries only see that city's records.
 * - Restricted to null: a signed-in user without a city sees nothing at all.
 *
 * Registered as a singleton in AppServiceProvider.
 */
class CittaCorrente
{
    private bool $limitato = false;

    private ?int $cittaId = null;

    public function limitaA(?int $cittaId): void
    {
        $this->limitato = true;
        $this->cittaId = $cittaId;
    }

    public function nessunLimite(): void
    {
        $this->limitato = false;
        $this->cittaId = null;
    }

    public function limitato(): bool
    {
        return $this->limitato;
    }

    public function id(): ?int
    {
        return $this->cittaId;
    }

    /**
     * Run a callback with the city restriction temporarily lifted.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function senzaLimiti(Closure $callback): mixed
    {
        $limitato = $this->limitato;
        $cittaId = $this->cittaId;
        $this->nessunLimite();

        try {
            return $callback();
        } finally {
            $this->limitato = $limitato;
            $this->cittaId = $cittaId;
        }
    }
}
