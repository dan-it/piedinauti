<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Children are plain records: first name and surname only, no login.
        Schema::create('bambini', function (Blueprint $table) {
            $table->id();
            $table->foreignId('citta_id')->constrained('citta')->cascadeOnDelete();
            $table->string('nome');
            $table->string('cognome');
            $table->timestamps();

            $table->unique(['id', 'citta_id']);
            $table->index(['citta_id', 'cognome', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bambini');
    }
};
