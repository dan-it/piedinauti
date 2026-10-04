<?php

namespace App\Console\Commands;

use App\Actions\InvitaPersona;
use App\Enums\Ruolo;
use App\Models\Citta;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

class InvitaPersonaCommand extends Command
{
    protected $signature = 'piedinauti:invita
        {email : Email address of the person}
        {nome : First name}
        {cognome : Surname}
        {--citta= : Name of the city (leave out for a global administrator)}
        {--ruolo=* : Role to give: admin_globale, admin_citta, responsabile, accompagnatore (repeatable)}';

    protected $description = 'Crea una persona e le invia l\'email per scegliere la password';

    public function handle(InvitaPersona $invita): int
    {
        $ruoli = [];
        foreach ((array) $this->option('ruolo') as $valore) {
            $ruolo = Ruolo::tryFrom((string) $valore);

            if ($ruolo === null) {
                $this->error("Ruolo sconosciuto: {$valore}");

                return self::FAILURE;
            }

            $ruoli[] = $ruolo;
        }

        if ($ruoli === []) {
            $this->error('Indica almeno un ruolo con --ruolo=...');

            return self::FAILURE;
        }

        $citta = null;
        if ($this->option('citta') !== null) {
            $citta = Citta::query()->where('nome', $this->option('citta'))->first();

            if ($citta === null) {
                $this->error("Città non trovata: {$this->option('citta')}");

                return self::FAILURE;
            }
        }

        if (User::query()->where('email', strtolower($this->argument('email')))->exists()) {
            $this->error('Esiste già una persona con questa email.');

            return self::FAILURE;
        }

        try {
            $invita($this->argument('nome'), $this->argument('cognome'), $this->argument('email'), $ruoli, $citta);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Invito inviato a {$this->argument('email')}.");

        return self::SUCCESS;
    }
}
