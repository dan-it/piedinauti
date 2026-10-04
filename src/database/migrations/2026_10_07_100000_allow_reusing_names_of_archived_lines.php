<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Line names were unique within a city, archived lines included. Now only active
        // lines compete for a name, so an archived line's name can be used again.
        Schema::table('linee', function (Blueprint $table) {
            $table->dropUnique(['citta_id', 'nome']);
        });

        DB::statement('CREATE UNIQUE INDEX linee_citta_id_nome_attive_unique ON linee (citta_id, nome) WHERE archiviata_il IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS linee_citta_id_nome_attive_unique');

        // Restoring the old rule fails if two lines of a city now share a name: rename them first.
        Schema::table('linee', function (Blueprint $table) {
            $table->unique(['citta_id', 'nome']);
        });
    }
};
