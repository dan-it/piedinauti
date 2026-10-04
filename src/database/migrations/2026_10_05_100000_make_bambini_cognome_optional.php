<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A child may have no surname on record. An empty string (not NULL) keeps
        // searching and sorting simple: "first name || ' ' || surname" never becomes NULL.
        Schema::table('bambini', function (Blueprint $table) {
            $table->string('cognome')->default('')->change();
        });
    }

    public function down(): void
    {
        Schema::table('bambini', function (Blueprint $table) {
            $table->string('cognome')->change();
        });
    }
};
