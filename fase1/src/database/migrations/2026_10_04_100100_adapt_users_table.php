<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Every person has a first name and a surname.
            $table->renameColumn('name', 'nome');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('cognome')->default('')->after('nome');

            // Global administrators belong to no city; everybody else to exactly one.
            $table->foreignId('citta_id')->nullable()->after('cognome')
                ->constrained('citta')->restrictOnDelete();

            // People are invited by email and choose their password later.
            $table->string('password')->nullable()->change();

            // Lets child tables reference (user, city) pairs to guarantee same-city links.
            $table->unique(['id', 'citta_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['id', 'citta_id']);
            $table->dropConstrainedForeignId('citta_id');
            $table->dropColumn('cognome');
            $table->string('password')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('nome', 'name');
        });
    }
};
