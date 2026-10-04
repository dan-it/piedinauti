<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GestioneCittaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();
    }

    public function test_gli_ospiti_vanno_al_login(): void
    {
        $this->get('/citta')->assertRedirect('/login');
    }

    public function test_chi_non_e_amministratore_globale_riceve_un_rifiuto(): void
    {
        $citta = Citta::factory()->create();
        $adminCitta = User::factory()->perCitta($citta)->conRuolo(Ruolo::AdminCitta)->create();

        $this->actingAs($adminCitta)->get('/citta')->assertForbidden();
        $this->actingAs($adminCitta)->get('/citta/create')->assertForbidden();
        $this->actingAs($adminCitta)->post('/citta', ['nome' => 'Roma'])->assertForbidden();
        $this->actingAs($adminCitta)->get("/citta/{$citta->id}/edit")->assertForbidden();
        $this->actingAs($adminCitta)->put("/citta/{$citta->id}", ['nome' => 'Roma'])->assertForbidden();

        $this->assertSame(1, Citta::count());
    }

    public function test_l_elenco_mostra_le_citta_con_i_conteggi(): void
    {
        $milano = Citta::factory()->create(['nome' => 'Milano']);
        Citta::factory()->create(['nome' => 'Bologna']);
        Bambino::factory()->count(3)->create(['citta_id' => $milano->id]);
        User::factory()->count(2)->perCitta($milano)->conRuolo(Ruolo::Accompagnatore)->create();

        $this->actingAs($this->admin)->get('/citta')
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->component('citta/Index')
                ->has('citta', 2)
                ->where('citta.0.nome', 'Bologna')
                ->where('citta.1.nome', 'Milano')
                ->where('citta.1.bambini', 3)
                ->where('citta.1.persone', 2));
    }

    public function test_si_crea_una_citta(): void
    {
        $this->actingAs($this->admin)->post('/citta', ['nome' => '  Torino '])
            ->assertRedirect('/citta')
            ->assertSessionHas('status');

        $this->assertTrue(Citta::query()->where('nome', 'Torino')->exists());
    }

    public function test_il_nome_e_obbligatorio_e_unico(): void
    {
        Citta::factory()->create(['nome' => 'Milano']);

        $this->actingAs($this->admin)->post('/citta', ['nome' => ''])->assertSessionHasErrors('nome');
        $this->actingAs($this->admin)->post('/citta', ['nome' => 'Milano'])->assertSessionHasErrors('nome');

        $this->assertSame(1, Citta::count());
    }

    public function test_si_rinomina_una_citta_anche_senza_cambiare_il_nome(): void
    {
        $citta = Citta::factory()->create(['nome' => 'Milano']);

        $this->actingAs($this->admin)->get("/citta/{$citta->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina->component('citta/Form')->where('citta.nome', 'Milano'));

        // Saving with the same name must not trip the uniqueness rule.
        $this->actingAs($this->admin)->put("/citta/{$citta->id}", ['nome' => 'Milano'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put("/citta/{$citta->id}", ['nome' => 'Milano 2'])->assertRedirect('/citta');

        $this->assertSame('Milano 2', $citta->fresh()->nome);
    }
}
