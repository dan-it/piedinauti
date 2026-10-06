<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How many stops before their own a chaperone can look at, with the children and their state
        // (read only). 0, the default, means none: stops before the chaperone's start are only landmarks.
        Schema::table('linee', function (Blueprint $table) {
            $table->unsignedSmallInteger('fermate_precedenti_visibili')->default(0)->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('linee', function (Blueprint $table) {
            $table->dropColumn('fermate_precedenti_visibili');
        });
    }
};
