<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Holds the "current city" restriction for the running request.
 *
 * - Not restricted: queries are not filtered (global administrators, console
 *   commands, queue jobs, guests such as the login form).
 * - Restricted to a city id: queries only see that city's records.
 * - Restricted to null: a signed-in user without a city sees nothing at all.
 *
 * The restriction is kept in two places that always agree: here, for the model scopes, and in the
 * database session, for PostgreSQL's row-level security (see the enable_row_level_security
 * migration). The second one is the safety net: a query that skips the scopes (raw SQL,
 * withoutGlobalScopes(), a forgotten where) still cannot reach another city's rows.
 *
 * Registered as a singleton in AppServiceProvider.
 */
class CittaCorrente
{
    private bool $limitato = false;

    private ?int $cittaId = null;

    public function limitaA(?int $cittaId): void
    {
        $this->imposta(true, $cittaId);
    }

    public function nessunLimite(): void
    {
        $this->imposta(false, null);
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

            try {
                $this->comunicaAlDatabase();
            } catch (Throwable) {
                // The callback failed inside a transaction that PostgreSQL has aborted, so it refuses
                // any statement. Rolling that transaction back also undoes the setting made inside it,
                // which puts the database back to the restriction that was in force. The original
                // error is the one worth reporting, not this one.
            }
        }
    }

    private function imposta(bool $limitato, ?int $cittaId): void
    {
        $this->limitato = $limitato;
        $this->cittaId = $cittaId;

        $this->comunicaAlDatabase();
    }

    /**
     * Tell PostgreSQL, for this connection, whether it is limited and to which city.
     * The row-level security policies read these two settings.
     */
    private function comunicaAlDatabase(): void
    {
        $connessione = DB::connection();

        if ($connessione->getDriverName() !== 'pgsql') {
            return;
        }

        $connessione->select('select set_config(?, ?, false), set_config(?, ?, false)', [
            'app.limitato',
            $this->limitato ? 'on' : 'off',
            'app.citta_id',
            $this->cittaId === null ? '' : (string) $this->cittaId,
        ]);
    }
}
