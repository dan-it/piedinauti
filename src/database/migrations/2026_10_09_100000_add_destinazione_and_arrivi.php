<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The destination (for example the school) is a special last stop: no child is picked up
        // there, the chaperones only mark "arrived". A line has at most one.
        Schema::table('fermate', function (Blueprint $table) {
            $table->boolean('destinazione')->default(false)->after('ordine');
        });

        DB::statement('CREATE UNIQUE INDEX fermate_una_destinazione_per_linea ON fermate (linea_id) WHERE destinazione');

        // The time a line actually arrived at its destination, one per line and day.
        Schema::create('arrivi', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('citta_id');
            $table->unsignedBigInteger('linea_id');
            $table->date('data');
            $table->timestamp('arrivata_alle');
            $table->unsignedBigInteger('registrata_da')->nullable();
            $table->timestamps();

            $table->unique(['data', 'linea_id']);
            $table->foreign(['linea_id', 'citta_id'])->references(['id', 'citta_id'])->on('linee')->cascadeOnDelete();
            $table->foreign('registrata_da')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arrivi');
        DB::statement('DROP INDEX IF EXISTS fermate_una_destinazione_per_linea');

        Schema::table('fermate', function (Blueprint $table) {
            $table->dropColumn('destinazione');
        });
    }
};
