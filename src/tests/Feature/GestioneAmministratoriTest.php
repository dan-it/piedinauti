<?php

namespace Tests\Feature;

use App\Actions\InvitaPersona;
use App\Enums\Ruolo;
use App\Models\Citta;
use App\Models\User;
use App\Notifications\InvitoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GestioneAmministratoriTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Citta $citta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = User::factory()->conRuolo(Ruolo::AdminGlobale)->create(['cognome' => 'Aaa']);
        $this->citta = Citta::factory()->create();
    }

    private function dati(array $extra = []): array
    {
        return $extra + [
            'nome' => 'Anna',
            'cognome' => 'Neri',
            'email' => 'Anna@Example.com',
            'tipo' => 'citta',
            'citta_id' => $this->citta->id,
        ];
    }

    public function test_chi_non_e_amministratore_globale_riceve_un_rifiuto(): void
    {
        $adminCitta = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();
        $collega = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();

        $this->actingAs($adminCitta)->get('/amministratori')->assertForbidden();
        $this->actingAs($adminCitta)->get('/amministratori/create')->assertForbidden();
        $this->actingAs($adminCitta)->post('/amministratori', $this->dati())->assertForbidden();
        // Even people of their own city are managed from other screens, not from this area.
        $this->actingAs($adminCitta)->put("/amministratori/{$collega->id}", ['nome' => 'X', 'cognome' => 'Y', 'email' => 'x@example.com'])->assertForbidden();
        $this->actingAs($adminCitta)->delete("/amministratori/{$collega->id}")->assertForbidden();
        $this->assertNotNull($collega->fresh());
    }

    public function test_l_elenco_mostra_solo_gli_amministratori(): void
    {
        $adminCitta = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create(['nome' => 'Carlo', 'cognome' => 'Zzz']);
        User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Responsabile)->create();

        $this->actingAs($this->admin)->get('/amministratori')
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->component('amministratori/Index')
                ->has('amministratori', 2) // the global administrator and the city administrator
                ->where('amministratori.1.nome', 'Carlo')
                ->where('amministratori.1.citta', $this->citta->nome)
                ->where('amministratori.1.attivo', true));
    }

    public function test_si_invita_un_amministratore_di_citta(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post('/amministratori', $this->dati())
            ->assertRedirect('/amministratori')
            ->assertSessionHas('status');

        $persona = User::query()->where('email', 'anna@example.com')->firstOrFail();
        $this->assertSame($this->citta->id, $persona->citta_id);
        $this->assertTrue($persona->haRuolo(Ruolo::AdminCitta));
        $this->assertNull($persona->password);
        Notification::assertSentTo($persona, InvitoNotification::class);
    }

    public function test_si_invita_un_amministratore_globale_senza_citta(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post('/amministratori', $this->dati(['tipo' => 'globale', 'citta_id' => null]))
            ->assertRedirect('/amministratori');

        $persona = User::query()->where('email', 'anna@example.com')->firstOrFail();
        $this->assertNull($persona->citta_id);
        $this->assertTrue($persona->haRuolo(Ruolo::AdminGlobale));
    }

    public function test_dati_non_validi_vengono_rifiutati(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'anna@example.com']);

        $this->actingAs($this->admin)->post('/amministratori', $this->dati())->assertSessionHasErrors('email');
        $this->actingAs($this->admin)->post('/amministratori', $this->dati(['email' => 'altra@example.com', 'citta_id' => null]))->assertSessionHasErrors('citta_id');
        $this->actingAs($this->admin)->post('/amministratori', $this->dati(['email' => 'altra@example.com', 'tipo' => 'responsabile']))->assertSessionHasErrors('tipo');

        Notification::assertNothingSent();
    }

    public function test_si_modificano_i_dati(): void
    {
        $persona = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();

        $this->actingAs($this->admin)->put("/amministratori/{$persona->id}", [
            'nome' => 'Luisa', 'cognome' => 'Verdi', 'email' => $persona->email,
        ])->assertRedirect('/amministratori');

        $this->assertSame('Luisa', $persona->fresh()->nome);
    }

    public function test_cambiando_l_email_di_chi_non_ha_attivato_l_invito_si_rinvia(): void
    {
        Notification::fake();
        $persona = app(InvitaPersona::class)('Anna', 'Neri', 'anna@example.com', [Ruolo::AdminCitta], $this->citta);

        $this->actingAs($this->admin)->put("/amministratori/{$persona->id}", [
            'nome' => 'Anna', 'cognome' => 'Neri', 'email' => 'nuova@example.com',
        ])->assertRedirect('/amministratori');

        $this->assertSame('nuova@example.com', $persona->fresh()->email);
        Notification::assertSentToTimes($persona->fresh(), InvitoNotification::class, 2);
    }

    public function test_il_reinvio_funziona_solo_per_chi_non_ha_la_password(): void
    {
        Notification::fake();
        $invitata = app(InvitaPersona::class)('Anna', 'Neri', 'anna@example.com', [Ruolo::AdminCitta], $this->citta);
        $attivo = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();

        $this->actingAs($this->admin)->post("/amministratori/{$invitata->id}/reinvia")->assertSessionHas('status');
        $this->actingAs($this->admin)->post("/amministratori/{$attivo->id}/reinvia")->assertSessionHas('errore');

        Notification::assertSentToTimes($invitata, InvitoNotification::class, 2);
    }

    public function test_si_elimina_un_amministratore_di_citta(): void
    {
        $persona = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();

        $this->actingAs($this->admin)->delete("/amministratori/{$persona->id}")
            ->assertRedirect('/amministratori');

        $this->assertNull(User::find($persona->id));
    }

    public function test_non_ci_si_elimina_da_soli_e_ne_resta_sempre_uno(): void
    {
        $altro = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();

        // Nobody deletes themselves.
        $this->actingAs($this->admin)->delete("/amministratori/{$this->admin->id}")->assertForbidden();

        // With two global administrators one can remove the other; the one left cannot be removed.
        $this->actingAs($this->admin)->delete("/amministratori/{$altro->id}")->assertRedirect('/amministratori');
        $this->assertSame(1, User::query()->whereHas('ruoliAssegnati', fn ($q) => $q->where('ruolo', 'admin_globale'))->count());
    }

    public function test_non_si_gestiscono_le_persone_degli_altri_ruoli(): void
    {
        $responsabile = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Responsabile)->create();

        $this->actingAs($this->admin)->get("/amministratori/{$responsabile->id}/edit")->assertForbidden();
        $this->actingAs($this->admin)->delete("/amministratori/{$responsabile->id}")->assertForbidden();
    }
}
