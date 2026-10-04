<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GestioneBambiniTest extends TestCase
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

    private function bambino(string $nome, string $cognome, ?Citta $citta = null): Bambino
    {
        return Bambino::factory()->create(['citta_id' => ($citta ?? $this->citta)->id, 'nome' => $nome, 'cognome' => $cognome]);
    }

    public function test_solo_l_amministratore_di_citta_accede(): void
    {
        $responsabile = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Responsabile)->create();

        $this->actingAs($responsabile)->get('/bambini')->assertForbidden();
        $this->actingAs($responsabile)->post('/bambini', ['nome' => 'Luca', 'cognome' => 'Bianchi'])->assertForbidden();
    }

    public function test_gli_ospiti_vanno_al_login(): void
    {
        $this->get('/bambini')->assertRedirect('/login');
    }

    public function test_l_elenco_mostra_solo_i_bambini_della_propria_citta_in_ordine(): void
    {
        $this->bambino('Luca', 'Rossi');
        $this->bambino('Anna', 'Bianchi');
        $this->bambino('Paolo', 'Estraneo', $this->altra);

        $this->actingAs($this->admin)->get('/bambini')
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->component('bambini/Index')
                ->has('bambini.data', 2)
                ->where('bambini.data.0.cognome', 'Bianchi')
                ->where('bambini.data.1.cognome', 'Rossi'));
    }

    public function test_la_ricerca_cerca_in_nome_e_cognome_senza_distinguere_le_maiuscole(): void
    {
        $this->bambino('Luca', 'Rossi');
        $this->bambino('Anna', 'Bianchi');
        $this->bambino('Lucia', 'Verdi');

        $cerca = fn (string $q) => $this->actingAs($this->admin)->get('/bambini?q='.urlencode($q));

        $cerca('luc')->assertInertia(fn (Assert $p) => $p->has('bambini.data', 2)->where('ricerca', 'luc'));
        $cerca('ROSSI')->assertInertia(fn (Assert $p) => $p->has('bambini.data', 1));
        $cerca('rossi luca')->assertInertia(fn (Assert $p) => $p->has('bambini.data', 1));
        $cerca('luca rossi')->assertInertia(fn (Assert $p) => $p->has('bambini.data', 1));
        $cerca('xyz')->assertInertia(fn (Assert $p) => $p->has('bambini.data', 0));
        // % and _ are plain characters, not wildcards.
        $cerca('%')->assertInertia(fn (Assert $p) => $p->has('bambini.data', 0));
    }

    public function test_l_elenco_e_diviso_in_pagine(): void
    {
        Bambino::factory()->count(30)->create(['citta_id' => $this->citta->id]);

        $this->actingAs($this->admin)->get('/bambini')
            ->assertInertia(fn (Assert $p) => $p->has('bambini.data', 25)->where('bambini.last_page', 2)->where('bambini.total', 30));

        $this->actingAs($this->admin)->get('/bambini?page=2')
            ->assertInertia(fn (Assert $p) => $p->has('bambini.data', 5));
    }

    public function test_si_aggiunge_un_bambino_nella_propria_citta(): void
    {
        // A city in the request is ignored: the child always joins the administrator's city.
        $this->actingAs($this->admin)->post('/bambini', ['nome' => ' Luca ', 'cognome' => 'Bianchi', 'citta_id' => $this->altra->id])
            ->assertRedirect('/bambini')
            ->assertSessionHas('status');

        $bambino = Bambino::query()->firstOrFail();
        $this->assertSame('Luca', $bambino->nome);
        $this->assertSame($this->citta->id, $bambino->citta_id);
    }

    public function test_il_nome_e_obbligatorio_il_cognome_no(): void
    {
        $this->actingAs($this->admin)->post('/bambini', ['nome' => ' ', 'cognome' => 'Rossi'])
            ->assertSessionHasErrors('nome');
        $this->assertSame(0, Bambino::count());

        $this->actingAs($this->admin)->post('/bambini', ['nome' => 'Luca'])->assertRedirect('/bambini');
        $this->actingAs($this->admin)->post('/bambini', ['nome' => 'Anna', 'cognome' => ''])->assertRedirect('/bambini');

        $this->assertSame(2, Bambino::count());
        $this->assertSame('', Bambino::query()->where('nome', 'Luca')->firstOrFail()->cognome);
    }

    public function test_un_bambino_senza_cognome_si_cerca_e_si_ordina_per_nome(): void
    {
        $this->bambino('Marco', 'Verdi');
        $this->bambino('Luca', '');
        $this->bambino('Zoe', 'Bianchi');

        $this->actingAs($this->admin)->get('/bambini')
            ->assertInertia(fn (Assert $p) => $p
                ->has('bambini.data', 3)
                ->where('bambini.data.0.cognome', 'Bianchi')
                ->where('bambini.data.1.nome', 'Luca') // sorted as "Luca", between Bianchi and Verdi
                ->where('bambini.data.2.cognome', 'Verdi'));

        $this->actingAs($this->admin)->get('/bambini?q=luca')->assertInertia(fn (Assert $p) => $p->has('bambini.data', 1));
        $this->assertSame('Luca', Bambino::query()->where('nome', 'Luca')->firstOrFail()->nomeCompleto);
    }

    public function test_si_modifica_e_si_elimina_un_bambino(): void
    {
        $bambino = $this->bambino('Luca', 'Rossi');

        $this->actingAs($this->admin)->get("/bambini/{$bambino->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('bambini/Form')->where('bambino.nome', 'Luca'));

        $this->actingAs($this->admin)->put("/bambini/{$bambino->id}", ['nome' => 'Luigi', 'cognome' => 'Rossi'])
            ->assertRedirect('/bambini');
        $this->assertSame('Luigi', $bambino->fresh()->nome);

        $this->actingAs($this->admin)->delete("/bambini/{$bambino->id}")->assertRedirect('/bambini');
        $this->assertNull(Bambino::find($bambino->id));
    }

    public function test_i_bambini_di_altre_citta_non_si_vedono_ne_si_toccano(): void
    {
        $estraneo = $this->bambino('Paolo', 'Estraneo', $this->altra);

        $this->actingAs($this->admin)->get("/bambini/{$estraneo->id}/edit")->assertNotFound();
        $this->actingAs($this->admin)->put("/bambini/{$estraneo->id}", ['nome' => 'X', 'cognome' => 'Y'])->assertNotFound();
        $this->actingAs($this->admin)->delete("/bambini/{$estraneo->id}")->assertNotFound();

        $this->assertNotNull($estraneo->fresh());
    }
}
