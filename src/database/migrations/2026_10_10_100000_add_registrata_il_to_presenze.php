<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the attendance was actually marked, as stated by the device that marked it.
        // With no signal a chaperone's taps are sent later: this moment (not the time they
        // arrive) decides whether the modification window was still open, and which of two
        // conflicting marks is the more recent one.
        Schema::table('presenze', function (Blueprint $table) {
            $table->timestamp('registrata_il')->nullable()->after('registrata_da');
        });
    }

    public function down(): void
    {
        Schema::table('presenze', function (Blueprint $table) {
            $table->dropColumn('registrata_il');
        });
    }
};
