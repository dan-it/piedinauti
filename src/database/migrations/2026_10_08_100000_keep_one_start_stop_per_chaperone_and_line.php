<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A chaperone is assigned to the stop where they start and goes on with the group to the
        // end of the line, so each chaperone has one starting stop per line. Earlier data may list
        // the same person on several stops of a line: keep only the earliest one.
        DB::statement(<<<'SQL'
            DELETE FROM fermata_accompagnatore AS fa
            USING fermate AS f
            WHERE f.id = fa.fermata_id
              AND EXISTS (
                SELECT 1
                FROM fermata_accompagnatore AS fa2
                JOIN fermate AS f2 ON f2.id = fa2.fermata_id
                WHERE fa2.user_id = fa.user_id
                  AND f2.linea_id = f.linea_id
                  AND f2.ordine < f.ordine
              )
        SQL);
    }

    public function down(): void
    {
        // The removed duplicates cannot be reconstructed.
    }
};
