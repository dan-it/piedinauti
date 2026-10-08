<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Sends one test email right now (not through the queue), so a wrong mail setting shows up immediately,
 * with the error, instead of invitations silently never arriving.
 */
class ProvaPosta extends Command
{
    protected $signature = 'piedinauti:prova-posta {email : Where to send the test email}';

    protected $description = 'Invia subito un\'email di prova per controllare le impostazioni della posta';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error("«{$email}» non è un indirizzo email valido.");

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Posta: %s, server %s:%s, mittente %s',
            config('mail.default'),
            config('mail.mailers.smtp.host'),
            config('mail.mailers.smtp.port'),
            config('mail.from.address'),
        ));

        try {
            Mail::raw(
                "Questa è un'email di prova inviata da Piedinauti il ".now()->format('d/m/Y H:i').".\nSe la leggi, la posta funziona.",
                fn ($messaggio) => $messaggio->to($email)->subject('Prova della posta di Piedinauti'),
            );
        } catch (Throwable $errore) {
            $this->error('Invio non riuscito: '.$errore->getMessage());
            $this->line('Controlla MAIL_HOST, MAIL_PORT, MAIL_USERNAME e MAIL_PASSWORD in src/.env, poi riprova.');

            return self::FAILURE;
        }

        $this->info("Email inviata a {$email}: controlla la casella (e la cartella spam).");

        return self::SUCCESS;
    }
}
