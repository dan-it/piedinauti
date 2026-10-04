<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Outward and return trips are separate lines.
        Schema::create('linee', function (Blueprint $table) {
            $table->id();
            $table->foreignId('citta_id')->constrained('citta')->cascadeOnDelete();
            $table->string('nome');
            $table->timestamps();

            $table->unique(['citta_id', 'nome']);
            $table->unique(['id', 'citta_id']);
        });

        // citta_id is repeated on every child table; composite foreign keys make
        // the database refuse links between records of different cities.
        Schema::create('fermate', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('citta_id');
            $table->unsignedBigInteger('linea_id');
            $table->string('nome');
            $table->time('orario');
            $table->unsignedSmallInteger('ordine');
            $table->timestamps();

            $table->unique(['linea_id', 'ordine']);
            $table->unique(['id', 'citta_id']);
            $table->unique(['id', 'linea_id']);
            $table->foreign('citta_id')->references('id')->on('citta')->cascadeOnDelete();
            $table->foreign(['linea_id', 'citta_id'])->references(['id', 'citta_id'])->on('linee')->cascadeOnDelete();
        });

        Schema::create('linea_responsabile', function (Blueprint $table) {
            $table->unsignedBigInteger('citta_id');
            $table->unsignedBigInteger('linea_id');
            $table->unsignedBigInteger('user_id');

            $table->primary(['linea_id', 'user_id']);
            $table->foreign(['linea_id', 'citta_id'])->references(['id', 'citta_id'])->on('linee')->cascadeOnDelete();
            $table->foreign(['user_id', 'citta_id'])->references(['id', 'citta_id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('fermata_accompagnatore', function (Blueprint $table) {
            $table->unsignedBigInteger('citta_id');
            $table->unsignedBigInteger('fermata_id');
            $table->unsignedBigInteger('user_id');

            $table->primary(['fermata_id', 'user_id']);
            $table->foreign(['fermata_id', 'citta_id'])->references(['id', 'citta_id'])->on('fermate')->cascadeOnDelete();
            $table->foreign(['user_id', 'citta_id'])->references(['id', 'citta_id'])->on('users')->cascadeOnDelete();
        });

        // Usual (recurring) assignment of a child to a stop.
        Schema::create('fermata_bambino', function (Blueprint $table) {
            $table->unsignedBigInteger('citta_id');
            $table->unsignedBigInteger('fermata_id');
            $table->unsignedBigInteger('bambino_id');

            $table->primary(['fermata_id', 'bambino_id']);
            $table->foreign(['fermata_id', 'citta_id'])->references(['id', 'citta_id'])->on('fermate')->cascadeOnDelete();
            $table->foreign(['bambino_id', 'citta_id'])->references(['id', 'citta_id'])->on('bambini')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fermata_bambino');
        Schema::dropIfExists('fermata_accompagnatore');
        Schema::dropIfExists('linea_responsabile');
        Schema::dropIfExists('fermate');
        Schema::dropIfExists('linee');
    }
};
