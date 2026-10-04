<?php

namespace App\Console\Commands;

use App\Actions\InvitaPersona;
use App\Models\User;
use DomainException;
use Illuminate\Console\Command;

class ReinviaInvito extends Command
{
    protected $signature = 'piedinauti:reinvia {email : Email address of the person}';

    protected $description = 'Invia di nuovo l\'invito a chi non ha ancora scelto la password';

    public function handle(InvitaPersona $invita): int
    {
        $utente = User::query()->where('email', strtolower($this->argument('email')))->first();

        if ($utente === null) {
            $this->error('Nessuna persona con questa email.');

            return self::FAILURE;
        }

        try {
            $invita->invia($utente);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Invito inviato di nuovo a {$utente->email}.");

        return self::SUCCESS;
    }
}
