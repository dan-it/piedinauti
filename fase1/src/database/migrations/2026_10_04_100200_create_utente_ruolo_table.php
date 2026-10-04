<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A person can hold several roles; see App\Enums\Ruolo for the values.
        Schema::create('utente_ruolo', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('ruolo', 30);
            $table->primary(['user_id', 'ruolo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utente_ruolo');
    }
};
