<?php

namespace Tests\Feature;

use App\Actions\InvitaPersona;
use App\Enums\Ruolo;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\User;
use App\Notifications\InvitoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GestionePersoneTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Citta $altra;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->citta = Citta::factory()->create();
        $this->altra = Citta::factory()->create();
        $this->admin = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create(['cognome' => 'Aaa']);
    }

    private function persona(Ruolo $ruolo, ?Citta $citta = null, array $attributi = []): User
    {
        return User::factory()->perCitta($citta ?? $this->citta)->conRuolo($ruolo)->create($attributi);
    }

    private function dati(array $extra = []): array
    {
        return $extra + [
            'nome' => 'Anna',
            'cognome' => 'Neri',
            'email' => 'Anna@Example.com',
            'ruoli' => ['responsabile', 'accompagnatore'],
        ];
    }

    public function test_solo_l_amministratore_di_citta_accede(): void
    {
        $responsabile = $this->persona(Ruolo::Responsabile);
        $globale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();

        foreach ([$responsabile, $globale] as $utente) {
            $this->actingAs($utente)->get('/persone')->assertForbidden();
            $this->actingAs($utente)->get('/persone/create')->assertForbidden();
            $this->actingAs($utente)->post('/persone', $this->dati())->assertForbidden();
        }
    }

    public function test_gli_ospiti_vanno_al_login(): void
    {
        $this->get('/persone')->assertRedirect('/login');
    }

    public function test_l_elenco_mostra_solo_le_persone_della_propria_citta(): void
    {
        $this->persona(Ruolo::Accompagnatore, null, ['cognome' => 'Zzz']);
        $this->persona(Ruolo::Accompagnatore, $this->altra);
        User::factory()->conRuolo(Ruolo::AdminGlobale)->create();

        $this->actingAs($this->admin)->get('/persone')
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->component('persone/Index')
                ->has('persone', 2)
                ->where('persone.0.sei_tu', true)
                ->where('persone.1.cognome', 'Zzz'));
    }

    public function test_si_invita_una_persona_con_piu_ruoli_nella_propria_citta(): void
    {
        Notification::fake();

        // A city in the request is ignored: the city is always the administrator's own.
        $this->actingAs($this->admin)->post('/persone', $this->dati(['citta_id' => $this->altra->id]))
            ->assertRedirect('/persone')
            ->assertSessionHas('status');

        $persona = User::query()->where('email', 'anna@example.com')->firstOrFail();
        $this->assertSame($this->citta->id, $persona->citta_id);
        $this->assertTrue($persona->haRuolo(Ruolo::Responsabile));
        $this->assertTrue($persona->haRuolo(Ruolo::Accompagnatore));
        $this->assertFalse($persona->haRuolo(Ruolo::AdminCitta));
        Notification::assertSentTo($persona, InvitoNotification::class);
    }

    public function test_dati_e_ruoli_non_validi_vengono_rifiutati(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post('/persone', $this->dati(['ruoli' => []]))->assertSessionHasErrors('ruoli');
        $this->actingAs($this->admin)->post('/persone', $this->dati(['ruoli' => ['admin_globale']]))->assertSessionHasErrors('ruoli.0');
        $this->actingAs($this->admin)->post('/persone', $this->dati(['email' => 'non-una-email']))->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
        Notification::assertNothingSent();
    }

    public function test_si_modificano_dati_e_ruoli(): void
    {
        $persona = $this->persona(Ruolo::Responsabile);
        $persona->assegnaRuolo(Ruolo::Accompagnatore);

        $this->actingAs($this->admin)->get("/persone/{$persona->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina->component('persone/Form')->has('persona.ruoli', 2));

        $this->actingAs($this->admin)->put("/persone/{$persona->id}", [
            'nome' => 'Luisa', 'cognome' => 'Verdi', 'email' => $persona->email, 'ruoli' => ['accompagnatore', 'admin_citta'],
        ])->assertRedirect('/persone');

        $persona = $persona->fresh();
        $this->assertSame('Luisa', $persona->nome);
        $this->assertFalse($persona->haRuolo(Ruolo::Responsabile));
        $this->assertTrue($persona->haRuolo(Ruolo::Accompagnatore));
        $this->assertTrue($persona->haRuolo(Ruolo::AdminCitta));
    }

    public function test_togliere_un_ruolo_toglie_le_assegnazioni_collegate(): void
    {
        $linea = Linea::factory()->create(['citta_id' => $this->citta->id]);
        $fermata = Fermata::factory()->create(['linea_id' => $linea->id, 'citta_id' => $this->citta->id, 'ordine' => 1]);
        $persona = $this->persona(Ruolo::Responsabile);
        $persona->assegnaRuolo(Ruolo::Accompagnatore);
        $linea->assegnaResponsabile($persona);
        $fermata->assegnaAccompagnatore($persona);

        $this->actingAs($this->admin)->put("/persone/{$persona->id}", [
            'nome' => $persona->nome, 'cognome' => $persona->cognome, 'email' => $persona->email, 'ruoli' => ['accompagnatore'],
        ])->assertRedirect('/persone');

        $this->assertSame(0, $linea->responsabili()->count(), 'no longer a manager: leaves the line');
        $this->assertSame(1, $fermata->accompagnatori()->count(), 'still a chaperone: keeps the stop');
    }

    public function test_un_amministratore_non_puo_togliersi_il_proprio_ruolo(): void
    {
        $this->actingAs($this->admin)->put("/persone/{$this->admin->id}", [
            'nome' => $this->admin->nome, 'cognome' => $this->admin->cognome, 'email' => $this->admin->email, 'ruoli' => ['responsabile'],
        ])->assertSessionHasErrors('ruoli');

        $this->assertTrue($this->admin->fresh()->haRuolo(Ruolo::AdminCitta));
    }

    public function test_le_persone_di_altre_citta_non_si_vedono_ne_si_toccano(): void
    {
        $estranea = $this->persona(Ruolo::Accompagnatore, $this->altra);

        $this->actingAs($this->admin)->get("/persone/{$estranea->id}/edit")->assertNotFound();
        $this->actingAs($this->admin)->put("/persone/{$estranea->id}", $this->dati(['email' => $estranea->email]))->assertNotFound();
        $this->actingAs($this->admin)->delete("/persone/{$estranea->id}")->assertNotFound();

        $this->assertNotNull($estranea->fresh());
    }

    public function test_cambiando_l_email_di_chi_non_ha_attivato_l_invito_si_rinvia(): void
    {
        Notification::fake();
        $persona = app(InvitaPersona::class)('Anna', 'Neri', 'anna@example.com', [Ruolo::Accompagnatore], $this->citta);

        $this->actingAs($this->admin)->put("/persone/{$persona->id}", $this->dati(['email' => 'nuova@example.com', 'ruoli' => ['accompagnatore']]))
            ->assertRedirect('/persone');

        $this->assertSame('nuova@example.com', $persona->fresh()->email);
        Notification::assertSentToTimes($persona->fresh(), InvitoNotification::class, 2);
    }

    public function test_il_reinvio_funziona_solo_per_chi_non_ha_la_password(): void
    {
        Notification::fake();
        $invitata = app(InvitaPersona::class)('Anna', 'Neri', 'anna@example.com', [Ruolo::Accompagnatore], $this->citta);
        $attiva = $this->persona(Ruolo::Accompagnatore);

        $this->actingAs($this->admin)->post("/persone/{$invitata->id}/reinvia")->assertSessionHas('status');
        $this->actingAs($this->admin)->post("/persone/{$attiva->id}/reinvia")->assertSessionHas('errore');
    }

    public function test_si_elimina_una_persona_ma_non_se_stessi(): void
    {
        $persona = $this->persona(Ruolo::Accompagnatore);

        $this->actingAs($this->admin)->delete("/persone/{$persona->id}")->assertRedirect('/persone');
        $this->assertNull(User::find($persona->id));

        $this->actingAs($this->admin)->delete("/persone/{$this->admin->id}")->assertForbidden();
        $this->assertNotNull($this->admin->fresh());
    }
}
