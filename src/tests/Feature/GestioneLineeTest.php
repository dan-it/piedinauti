<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GestioneLineeTest extends TestCase
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
        $this->admin = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();
    }

    private function linea(string $nome, ?Citta $citta = null): Linea
    {
        return Linea::factory()->create(['citta_id' => ($citta ?? $this->citta)->id, 'nome' => $nome]);
    }

    private function fermata(Linea $linea, int $ordine, string $orario, string $nome = 'Fermata'): Fermata
    {
        return Fermata::factory()->create([
            'linea_id' => $linea->id, 'citta_id' => $linea->citta_id, 'ordine' => $ordine, 'orario' => $orario, 'nome' => $nome,
        ]);
    }

    private function responsabile(?Citta $citta = null): User
    {
        return User::factory()->perCitta($citta ?? $this->citta)->conRuolo(Ruolo::Responsabile)->create();
    }

    public function test_solo_l_amministratore_di_citta_accede(): void
    {
        $linea = $this->linea('Verde');

        foreach ([$this->responsabile(), User::factory()->conRuolo(Ruolo::AdminGlobale)->create()] as $utente) {
            $this->actingAs($utente)->get('/linee')->assertForbidden();
            $this->actingAs($utente)->post('/linee', ['nome' => 'Blu'])->assertForbidden();
            $this->actingAs($utente)->get("/linee/{$linea->id}/edit")->assertForbidden();
        }
    }

    public function test_gli_ospiti_vanno_al_login(): void
    {
        $this->get('/linee')->assertRedirect('/login');
    }

    public function test_l_elenco_mostra_orari_e_responsabili_solo_della_propria_citta(): void
    {
        $linea = $this->linea('Verde - Andata');
        $this->fermata($linea, 1, '07:40:00');
        $this->fermata($linea, 2, '08:05:00');
        $linea->assegnaResponsabile($this->responsabile());
        $this->linea('Estranea', $this->altra);

        $this->actingAs($this->admin)->get('/linee')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('linee/Index')
                ->has('linee', 1)
                ->where('linee.0.nome', 'Verde - Andata')
                ->where('linee.0.fermate', 2)
                ->where('linee.0.primo_orario', '07:40')
                ->where('linee.0.ultimo_orario', '08:05')
                ->has('linee.0.responsabili', 1));
    }

    public function test_si_crea_una_linea_nella_propria_citta_e_si_va_alla_sua_pagina(): void
    {
        $risposta = $this->actingAs($this->admin)->post('/linee', ['nome' => '  Linea Verde - Andata ', 'citta_id' => $this->altra->id]);

        $linea = Linea::query()->firstOrFail();
        $risposta->assertRedirect("/linee/{$linea->id}/edit");
        $this->assertSame('Linea Verde - Andata', $linea->nome);
        $this->assertSame($this->citta->id, $linea->citta_id);
    }

    public function test_il_nome_e_obbligatorio_e_unico_nella_citta_ma_non_tra_citta(): void
    {
        $this->linea('Verde');
        $this->linea('Verde', $this->altra); // the same name in another city is fine

        $this->actingAs($this->admin)->post('/linee', ['nome' => ''])->assertSessionHasErrors('nome');
        $this->actingAs($this->admin)->post('/linee', ['nome' => 'Verde'])->assertSessionHasErrors('nome');
        $this->assertSame(2, Linea::query()->withoutGlobalScopes()->count());
    }

    public function test_si_rinomina_una_linea_anche_senza_cambiare_il_nome(): void
    {
        $linea = $this->linea('Verde');

        $this->actingAs($this->admin)->get("/linee/{$linea->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('linee/Edit')->where('linea.nome', 'Verde'));

        $this->actingAs($this->admin)->put("/linee/{$linea->id}", ['nome' => 'Verde'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put("/linee/{$linea->id}", ['nome' => 'Blu'])->assertSessionHasNoErrors();
        $this->assertSame('Blu', $linea->fresh()->nome);
    }

    public function test_la_pagina_della_linea_elenca_fermate_ordinate_e_responsabili_disponibili(): void
    {
        $linea = $this->linea('Verde');
        $this->fermata($linea, 1, '07:40:00', 'Parco');
        $this->fermata($linea, 2, '08:05:00', 'Scuola');
        $assegnato = $this->responsabile();
        $this->responsabile(); // available but not assigned
        $this->responsabile($this->altra); // other city: not offered
        User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Accompagnatore)->create(); // not a manager
        $linea->assegnaResponsabile($assegnato);

        $this->actingAs($this->admin)->get("/linee/{$linea->id}/edit")
            ->assertInertia(fn (Assert $p) => $p
                ->has('fermate', 2)
                ->where('fermate.0.orario', '07:40')
                ->where('fermate.1.nome', 'Scuola')
                ->has('responsabili', 2)
                ->where('assegnati', [$assegnato->id]));
    }

    public function test_si_scelgono_i_responsabili_e_si_sostituiscono(): void
    {
        $linea = $this->linea('Verde');
        $a = $this->responsabile();
        $b = $this->responsabile();

        $this->actingAs($this->admin)->put("/linee/{$linea->id}/responsabili", ['responsabili' => [$a->id, $b->id]])
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $linea->responsabili()->pluck('users.id')->all());

        $this->actingAs($this->admin)->put("/linee/{$linea->id}/responsabili", ['responsabili' => [$b->id]]);
        $this->assertSame([$b->id], $linea->responsabili()->pluck('users.id')->all());

        $this->actingAs($this->admin)->put("/linee/{$linea->id}/responsabili", ['responsabili' => []]);
        $this->assertSame(0, $linea->responsabili()->count());
    }

    public function test_i_responsabili_devono_essere_responsabili_della_stessa_citta(): void
    {
        $linea = $this->linea('Verde');
        $soloAccompagnatore = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Accompagnatore)->create();
        $altraCitta = $this->responsabile($this->altra);

        $this->actingAs($this->admin)->put("/linee/{$linea->id}/responsabili", ['responsabili' => [$soloAccompagnatore->id]])
            ->assertSessionHasErrors('responsabili');
        $this->actingAs($this->admin)->put("/linee/{$linea->id}/responsabili", ['responsabili' => [$altraCitta->id]])
            ->assertSessionHasErrors('responsabili');

        $this->assertSame(0, $linea->responsabili()->count());
    }

    public function test_si_elimina_una_linea_con_le_sue_fermate(): void
    {
        $linea = $this->linea('Verde');
        $fermata = $this->fermata($linea, 1, '07:40:00');

        $this->actingAs($this->admin)->delete("/linee/{$linea->id}")->assertRedirect('/linee');

        $this->assertNull(Linea::find($linea->id));
        $this->assertNull(Fermata::find($fermata->id));
    }

    public function test_una_linea_con_presenze_non_si_elimina(): void
    {
        $linea = $this->linea('Verde');
        $fermata = $this->fermata($linea, 1, '07:40:00');
        $bambino = Bambino::factory()->create(['citta_id' => $this->citta->id]);
        Presenza::registra($fermata, $bambino, today(), presente: true);

        $this->actingAs($this->admin)->delete("/linee/{$linea->id}")->assertSessionHas('errore');

        $this->assertNotNull($linea->fresh());
    }

    public function test_le_linee_di_altre_citta_non_si_vedono_ne_si_toccano(): void
    {
        $estranea = $this->linea('Estranea', $this->altra);

        $this->actingAs($this->admin)->get("/linee/{$estranea->id}/edit")->assertNotFound();
        $this->actingAs($this->admin)->put("/linee/{$estranea->id}", ['nome' => 'X'])->assertNotFound();
        $this->actingAs($this->admin)->put("/linee/{$estranea->id}/responsabili", ['responsabili' => []])->assertNotFound();
        $this->actingAs($this->admin)->delete("/linee/{$estranea->id}")->assertNotFound();

        $this->assertNotNull($estranea->fresh());
    }
}
