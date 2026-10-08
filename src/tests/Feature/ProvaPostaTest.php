<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProvaPostaTest extends TestCase
{
    public function test_invia_un_email_di_prova(): void
    {
        config(['mail.default' => 'array']);

        $this->artisan('piedinauti:prova-posta', ['email' => 'prova@example.com'])
            ->expectsOutputToContain('Email inviata a prova@example.com')
            ->assertExitCode(0);

        $messaggi = Mail::mailer('array')->getSymfonyTransport()->messages();

        $this->assertCount(1, $messaggi);
        $this->assertSame('Prova della posta di Piedinauti', $messaggi[0]->getOriginalMessage()->getSubject());
        $this->assertSame('prova@example.com', $messaggi[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_rifiuta_un_indirizzo_non_valido(): void
    {
        $this->artisan('piedinauti:prova-posta', ['email' => 'non-una-email'])
            ->expectsOutputToContain('non è un indirizzo email valido')
            ->assertExitCode(1);
    }

    public function test_mostra_l_errore_se_la_posta_e_configurata_male(): void
    {
        // A mailer that does not exist makes the send fail, as a wrong SMTP setting would.
        config(['mail.default' => 'non-esiste']);

        $this->artisan('piedinauti:prova-posta', ['email' => 'prova@example.com'])
            ->expectsOutputToContain('Invio non riuscito')
            ->assertExitCode(1);
    }
}
