<?php

namespace App\Console\Commands;

use App\Enums\Ruolo;
use App\Models\User;
use Illuminate\Console\Command;

class CreaAdminGlobale extends Command
{
    protected $signature = 'piedinauti:admin-globale
        {email : Email address used to sign in}
        {nome : First name}
        {cognome : Surname}';

    protected $description = 'Crea un amministratore globale (nessuna città associata)';

    public function handle(): int
    {
        $password = $this->secret('Password (minimo 8 caratteri)');

        if (! is_string($password) || strlen($password) < 8) {
            $this->error('La password deve avere almeno 8 caratteri.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $this->argument('email'))->exists()) {
            $this->error('Esiste già una persona con questa email.');

            return self::FAILURE;
        }

        $utente = User::query()->create([
            'nome' => $this->argument('nome'),
            'cognome' => $this->argument('cognome'),
            'email' => $this->argument('email'),
            'password' => $password,
        ]);
        $utente->forceFill(['email_verified_at' => now()])->save();
        $utente->assegnaRuolo(Ruolo::AdminGlobale);

        $this->info('Amministratore globale creato.');

        return self::SUCCESS;
    }
}
