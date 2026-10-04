<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An archived line keeps all its stops and attendance history but is hidden
        // from everybody except global administrators.
        Schema::table('linee', function (Blueprint $table) {
            $table->timestamp('archiviata_il')->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('linee', function (Blueprint $table) {
            $table->dropColumn('archiviata_il');
        });
    }
};
