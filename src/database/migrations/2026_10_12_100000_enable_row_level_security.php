<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-Level Security: the database itself refuses to show or change rows of another city.
 *
 * The application already filters by city (global scopes) and the foreign keys already refuse links
 * between cities. This is a third barrier, enforced by PostgreSQL on every query, so a query written
 * without the usual filter (raw SQL, withoutGlobalScopes(), a forgotten where) still cannot reach
 * another city's data while a request is limited to its own city.
 *
 * How it works
 * - The application tells the database, for the current connection, whether it is limited and to which
 *   city: set_config('app.limitato', 'on'|'off') and set_config('app.citta_id', '<id>'). See
 *   App\Support\CittaCorrente.
 * - Not limited (global administrators, console commands, queue jobs, guests such as the login form):
 *   every row is visible, as before.
 * - Limited to a city: only rows of that city. Limited to no city: no rows at all.
 * - The policies are FORCEd, so they also apply to the role that owns the tables, which is the role the
 *   application connects with. Only superusers and roles with BYPASSRLS skip them (backups, restores).
 */
return new class extends Migration
{
    /** Tables whose rows carry a citta_id. */
    private const CON_CITTA = [
        'users',
        'bambini',
        'linee',
        'fermate',
        'linea_responsabile',
        'fermata_accompagnatore',
        'fermata_bambino',
        'presenze',
        'arrivi',
    ];

    /**
     * @return list<string> the SQL statements that switch row-level security on
     */
    public static function istruzioniSu(): array
    {
        $istruzioni = [
            // True when the row's city may be seen: no limit in force, or the row belongs to the limited city.
            // A row without a city (a global administrator) is never visible to a limited request.
            <<<'SQL'
            CREATE OR REPLACE FUNCTION rls_consente(riga_citta bigint) RETURNS boolean
            LANGUAGE sql STABLE PARALLEL SAFE AS $$
                SELECT current_setting('app.limitato', true) IS DISTINCT FROM 'on'
                    OR riga_citta = NULLIF(current_setting('app.citta_id', true), '')::bigint
            $$
            SQL,
        ];

        foreach (self::CON_CITTA as $tabella) {
            $istruzioni = [...$istruzioni, ...self::proteggi($tabella, 'rls_consente(citta_id)')];
        }

        // The cities themselves: a limited request sees its own city only.
        $istruzioni = [...$istruzioni, ...self::proteggi('citta', 'rls_consente(id)')];

        // Roles have no city of their own: they follow the person they belong to, and a person is visible
        // only when the policy of "users" lets the request see them.
        $condizione = 'EXISTS (SELECT 1 FROM users u WHERE u.id = utente_ruolo.user_id)';

        return [...$istruzioni, ...self::proteggi('utente_ruolo', $condizione)];
    }

    /**
     * @return list<string>
     */
    private static function proteggi(string $tabella, string $condizione): array
    {
        return [
            "ALTER TABLE {$tabella} ENABLE ROW LEVEL SECURITY",
            "ALTER TABLE {$tabella} FORCE ROW LEVEL SECURITY",
            "DROP POLICY IF EXISTS isolamento_citta ON {$tabella}",
            "CREATE POLICY isolamento_citta ON {$tabella} USING ({$condizione}) WITH CHECK ({$condizione})",
        ];
    }

    /**
     * @return list<string> the SQL statements that switch it off again
     */
    public static function istruzioniGiu(): array
    {
        $istruzioni = [];

        foreach ([...self::CON_CITTA, 'citta', 'utente_ruolo'] as $tabella) {
            $istruzioni[] = "DROP POLICY IF EXISTS isolamento_citta ON {$tabella}";
            $istruzioni[] = "ALTER TABLE {$tabella} NO FORCE ROW LEVEL SECURITY";
            $istruzioni[] = "ALTER TABLE {$tabella} DISABLE ROW LEVEL SECURITY";
        }

        $istruzioni[] = 'DROP FUNCTION IF EXISTS rls_consente(bigint)';

        return $istruzioni;
    }

    public function up(): void
    {
        foreach (self::istruzioniSu() as $sql) {
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        foreach (self::istruzioniGiu() as $sql) {
            DB::unprepared($sql);
        }
    }
};
