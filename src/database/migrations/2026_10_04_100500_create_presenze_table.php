<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presenze', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('citta_id');
            // A local calendar date (Europe/Rome), not a UTC timestamp.
            $table->date('data');
            $table->unsignedBigInteger('linea_id');
            $table->unsignedBigInteger('fermata_id');
            $table->unsignedBigInteger('bambino_id');
            $table->boolean('presente');
            // True when the child was added to the stop for this day only.
            $table->boolean('temporaneo')->default(false);
            $table->unsignedBigInteger('registrata_da')->nullable();
            $table->timestamps();

            // One record per child, line and day: a child may appear on several
            // lines on the same day (outward, return, extra trips).
            $table->unique(['data', 'linea_id', 'bambino_id']);
            $table->index(['linea_id', 'data']);

            $table->foreign(['linea_id', 'citta_id'])->references(['id', 'citta_id'])->on('linee')->cascadeOnDelete();
            // The stop must belong to the line recorded on the same row.
            $table->foreign(['fermata_id', 'linea_id'])->references(['id', 'linea_id'])->on('fermate')->cascadeOnDelete();
            $table->foreign(['fermata_id', 'citta_id'])->references(['id', 'citta_id'])->on('fermate')->cascadeOnDelete();
            $table->foreign(['bambino_id', 'citta_id'])->references(['id', 'citta_id'])->on('bambini')->cascadeOnDelete();
            $table->foreign('registrata_da')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presenze');
    }
};
