<?php

namespace Tests\Feature;

use App\Actions\InvitaPersona;
use App\Enums\Ruolo;
use App\Models\Citta;
use App\Models\User;
use App\Notifications\InvitoNotification;
use DomainException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\TestCase;

class InvitiTest extends TestCase
{
    use RefreshDatabase;

    private function invita(array $ruoli = [Ruolo::Accompagnatore], ?Citta $citta = null): User
    {
        $citta ??= Citta::factory()->create();

        return app(InvitaPersona::class)('Luca', 'Bianchi', 'Luca@Example.com', $ruoli, $citta);
    }

    private function token(User $utente): string
    {
        return Notification::sent($utente, InvitoNotification::class)->last()->token;
    }

    private function impostaPassword(User $utente, string $token, string $password = 'una-password-lunga')
    {
        return $this->post('/reset-password', [
            'token' => $token,
            'email' => $utente->email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_l_invito_crea_la_persona_senza_password_e_invia_l_email(): void
    {
        Notification::fake();
        $citta = Citta::factory()->create();

        $utente = $this->invita([Ruolo::Responsabile, Ruolo::Accompagnatore], $citta);

        $this->assertNull($utente->password);
        $this->assertSame('luca@example.com', $utente->email);
        $this->assertSame($citta->id, $utente->citta_id);
        $this->assertTrue($utente->haRuolo(Ruolo::Responsabile));
        $this->assertTrue($utente->haRuolo(Ruolo::Accompagnatore));
        Notification::assertSentTo($utente, InvitoNotification::class);
    }

    public function test_ruoli_e_citta_incompatibili_non_lasciano_nulla(): void
    {
        Notification::fake();

        try {
            // A global administrator cannot belong to a city.
            $this->invita([Ruolo::AdminGlobale], Citta::factory()->create());
            $this->fail('Doveva essere rifiutato.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, User::count());
            Notification::assertNothingSent();
        }
    }

    public function test_l_email_contiene_il_link_per_impostare_la_password(): void
    {
        Notification::fake();
        $utente = $this->invita();

        Notification::assertSentTo($utente, InvitoNotification::class, function (InvitoNotification $notifica) use ($utente) {
            $mail = $notifica->toMail($utente);

            $this->assertSame('Imposta la password', $mail->actionText);
            $this->assertStringContainsString('/reset-password/'.$notifica->token, $mail->actionUrl);
            $this->assertStringContainsString(urlencode($utente->email), $mail->actionUrl);

            return true;
        });
    }

    public function test_chi_non_ha_scelto_la_password_non_puo_accedere(): void
    {
        Notification::fake();
        $utente = $this->invita();

        $this->post('/login', ['email' => $utente->email, 'password' => 'qualsiasi-password']);

        $this->assertGuest();
    }

    public function test_la_persona_sceglie_la_password_e_puo_accedere(): void
    {
        Notification::fake();
        $utente = $this->invita();
        $token = $this->token($utente);

        $this->get('/reset-password/'.$token.'?email='.urlencode($utente->email))->assertOk();

        $this->impostaPassword($utente, $token)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        $utente->refresh();
        $this->assertNotNull($utente->password);
        $this->assertNotNull($utente->email_verified_at);

        $this->post('/login', ['email' => $utente->email, 'password' => 'una-password-lunga']);
        $this->assertAuthenticatedAs($utente);
    }

    public function test_l_invito_vale_sei_giorni_ma_non_otto(): void
    {
        Notification::fake();
        $utente = $this->invita();
        $token = $this->token($utente);

        $this->travel(6)->days();
        $this->impostaPassword($utente, $token, 'password-per-prova')->assertSessionHasNoErrors();

        $altro = app(InvitaPersona::class)('Anna', 'Neri', 'anna@example.com', [Ruolo::Accompagnatore], $utente->citta);
        $this->travel(8)->days();
        $this->impostaPassword($altro, $this->token($altro))->assertSessionHasErrors('email');
    }

    public function test_un_account_attivo_usa_il_reset_normale_di_un_ora(): void
    {
        Notification::fake();
        $utente = User::factory()->create();

        $this->post('/forgot-password', ['email' => $utente->email]);
        $token = Notification::sent($utente, ResetPassword::class)->first()->token;

        $this->travel(2)->hours();

        $this->impostaPassword($utente, $token)->assertSessionHasErrors('email');
    }

    public function test_il_reinvio_annulla_il_link_precedente(): void
    {
        Notification::fake();
        $utente = $this->invita();
        $primo = $this->token($utente);

        app(InvitaPersona::class)->invia($utente);
        $secondo = $this->token($utente);

        $this->assertNotSame($primo, $secondo);
        $this->impostaPassword($utente, $primo)->assertSessionHasErrors('email');
        $this->impostaPassword($utente, $secondo)->assertSessionHasNoErrors();
    }

    public function test_non_si_puo_invitare_chi_ha_gia_una_password(): void
    {
        $utente = User::factory()->create();

        $this->expectException(DomainException::class);

        app(InvitaPersona::class)->invia($utente);
    }

    public function test_il_comando_invita_una_persona(): void
    {
        Notification::fake();
        Citta::factory()->create(['nome' => 'Milano']);

        $this->artisan('piedinauti:invita', [
            'email' => 'anna@example.com',
            'nome' => 'Anna',
            'cognome' => 'Neri',
            '--citta' => 'Milano',
            '--ruolo' => ['responsabile', 'accompagnatore'],
        ])->assertExitCode(0);

        $utente = User::query()->where('email', 'anna@example.com')->firstOrFail();
        $this->assertTrue($utente->haRuolo(Ruolo::Responsabile));
        $this->assertTrue($utente->haRuolo(Ruolo::Accompagnatore));
        Notification::assertSentTo($utente, InvitoNotification::class);
    }

    public function test_il_comando_rifiuta_richieste_non_valide(): void
    {
        Notification::fake();
        Citta::factory()->create(['nome' => 'Milano']);

        // Unknown role, no role, unknown city, global admin with a city.
        $base = ['email' => 'anna@example.com', 'nome' => 'Anna', 'cognome' => 'Neri'];
        $this->artisan('piedinauti:invita', $base + ['--ruolo' => ['capo']])->assertExitCode(1);
        $this->artisan('piedinauti:invita', $base)->assertExitCode(1);
        $this->artisan('piedinauti:invita', $base + ['--ruolo' => ['responsabile'], '--citta' => 'Roma'])->assertExitCode(1);
        $this->artisan('piedinauti:invita', $base + ['--ruolo' => ['admin_globale'], '--citta' => 'Milano'])->assertExitCode(1);

        $this->assertSame(0, User::count());
        Notification::assertNothingSent();
    }

    public function test_il_comando_reinvia_solo_a_chi_non_ha_la_password(): void
    {
        Notification::fake();
        $invitato = $this->invita();
        $attivo = User::factory()->create();

        $this->artisan('piedinauti:reinvia', ['email' => $invitato->email])->assertExitCode(0);
        $this->artisan('piedinauti:reinvia', ['email' => $attivo->email])->assertExitCode(1);
        $this->artisan('piedinauti:reinvia', ['email' => 'nessuno@example.com'])->assertExitCode(1);

        Notification::assertSentToTimes($invitato, InvitoNotification::class, 2);
    }
}
