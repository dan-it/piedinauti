<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Chaperones and children of each stop. Fixture: one city with two lines, each with a manager,
 * and an unrelated second city.
 */
class AssegnazioniTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Citta $altra;

    private User $admin;

    private User $resp1;

    private User $resp2;

    private User $acc;

    private Linea $linea1;

    private Linea $linea2;

    private Fermata $f1a;

    private Fermata $f1b;

    private Fermata $f2a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->citta = Citta::factory()->create();
        $this->altra = Citta::factory()->create();

        $this->admin = $this->persona(Ruolo::AdminCitta);
        $this->resp1 = $this->persona(Ruolo::Responsabile);
        $this->resp2 = $this->persona(Ruolo::Responsabile);
        $this->acc = $this->persona(Ruolo::Accompagnatore);

        $this->linea1 = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']);
        $this->linea2 = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);
        $this->f1a = $this->fermata($this->linea1, 1, '07:40:00', 'Parco');
        $this->f1b = $this->fermata($this->linea1, 2, '07:50:00', 'Via Roma');
        $this->f2a = $this->fermata($this->linea2, 1, '07:45:00', 'Piazza');
        $this->linea1->assegnaResponsabile($this->resp1);
        $this->linea2->assegnaResponsabile($this->resp2);
    }

    private function persona(Ruolo $ruolo, ?Citta $citta = null): User
    {
        return User::factory()->perCitta($citta ?? $this->citta)->conRuolo($ruolo)->create();
    }

    private function fermata(Linea $linea, int $ordine, string $orario, string $nome): Fermata
    {
        return Fermata::factory()->create([
            'linea_id' => $linea->id, 'citta_id' => $linea->citta_id, 'ordine' => $ordine, 'orario' => $orario, 'nome' => $nome,
        ]);
    }

    private function bambino(string $nome, string $cognome = 'Rossi', ?Citta $citta = null): Bambino
    {
        return Bambino::factory()->create(['citta_id' => ($citta ?? $this->citta)->id, 'nome' => $nome, 'cognome' => $cognome]);
    }

    // ------------------------------------------------------------ access

    public function test_chi_non_e_responsabile_o_amministratore_di_citta_non_accede(): void
    {
        $globale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();

        foreach ([$this->acc, $globale] as $utente) {
            $this->actingAs($utente)->get('/assegnazioni')->assertForbidden();
            $this->actingAs($utente)->get("/assegnazioni/fermate/{$this->f1a->id}")->assertForbidden();
        }
    }

    public function test_gli_ospiti_vanno_al_login(): void
    {
        $this->get('/assegnazioni')->assertRedirect('/login');
    }

    public function test_un_responsabile_lavora_solo_sulle_proprie_linee(): void
    {
        $this->actingAs($this->resp1)->get("/assegnazioni/linee/{$this->linea1->id}")->assertOk();
        $this->actingAs($this->resp1)->get("/assegnazioni/fermate/{$this->f1a->id}")->assertOk();

        // Another line of the same city: found, but not his.
        $this->actingAs($this->resp1)->get("/assegnazioni/linee/{$this->linea2->id}")->assertForbidden();
        $this->actingAs($this->resp1)->get("/assegnazioni/fermate/{$this->f2a->id}")->assertForbidden();
        $this->actingAs($this->resp1)->put("/assegnazioni/fermate/{$this->f2a->id}/accompagnatori", ['accompagnatori' => [$this->acc->id]])->assertForbidden();
        $this->actingAs($this->resp1)->post("/assegnazioni/fermate/{$this->f2a->id}/bambini", ['bambino_id' => $this->bambino('Luca')->id])->assertForbidden();
    }

    public function test_l_amministratore_di_citta_lavora_su_tutte_le_linee(): void
    {
        $this->actingAs($this->admin)->get("/assegnazioni/linee/{$this->linea1->id}")->assertOk();
        $this->actingAs($this->admin)->get("/assegnazioni/linee/{$this->linea2->id}")->assertOk();
    }

    public function test_le_linee_e_le_fermate_di_altre_citta_non_si_trovano(): void
    {
        $adminAltra = $this->persona(Ruolo::AdminCitta, $this->altra);

        $this->actingAs($adminAltra)->get("/assegnazioni/linee/{$this->linea1->id}")->assertNotFound();
        $this->actingAs($adminAltra)->get("/assegnazioni/fermate/{$this->f1a->id}")->assertNotFound();
        $this->actingAs($adminAltra)->post("/assegnazioni/fermate/{$this->f1a->id}/bambini", ['bambino_id' => 1])->assertNotFound();
    }

    // ----------------------------------------------------------- lists

    public function test_l_elenco_mostra_le_linee_di_ciascuno(): void
    {
        $this->f1a->assegnaAccompagnatore($this->acc);
        $this->f1a->assegnaBambino($this->bambino('Luca'));

        $this->actingAs($this->resp1)->get('/assegnazioni')
            ->assertInertia(fn (Assert $p) => $p
                ->component('assegnazioni/Index')
                ->has('linee', 1)
                ->where('linee.0.nome', 'Verde')
                ->where('linee.0.fermate', 2)
                ->where('linee.0.bambini', 1)
                // The chaperone starts at Parco and goes on to Via Roma: every stop has someone.
                ->where('linee.0.senza_accompagnatore', 0));

        $this->actingAs($this->admin)->get('/assegnazioni')->assertInertia(fn (Assert $p) => $p->has('linee', 2));
    }

    public function test_una_linea_archiviata_non_compare(): void
    {
        $this->linea1->update(['archiviata_il' => now()]);

        $this->actingAs($this->admin)->get('/assegnazioni')->assertInertia(fn (Assert $p) => $p->has('linee', 1)->where('linee.0.nome', 'Blu'));
        $this->actingAs($this->admin)->get("/assegnazioni/linee/{$this->linea1->id}")->assertNotFound();
    }

    public function test_la_pagina_della_linea_funziona_con_fermate_senza_bambini(): void
    {
        $this->f1b->assegnaBambino($this->bambino('Anna', 'Bianchi'));
        $this->f1b->assegnaBambino($this->bambino('Marco', 'Verdi'));

        $this->actingAs($this->resp1)->get("/assegnazioni/linee/{$this->linea1->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('assegnazioni/Linea')
                ->has('fermate', 2)
                ->where('fermate.0.nome', 'Parco')
                ->where('fermate.0.bambini', []) // a stop with no children is normal
                ->where('fermate.0.accompagnatori', [])
                ->where('fermate.1.bambini', ['Anna Bianchi', 'Marco Verdi']));
    }

    // -------------------------------------------------------- one stop

    public function test_la_pagina_della_fermata_offre_solo_gli_accompagnatori_della_citta(): void
    {
        $this->persona(Ruolo::Accompagnatore, $this->altra);
        $this->persona(Ruolo::Responsabile); // not a chaperone
        $this->f1a->assegnaAccompagnatore($this->acc);

        $this->actingAs($this->resp1)->get("/assegnazioni/fermate/{$this->f1a->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('assegnazioni/Fermata')
                ->has('accompagnatori', 1)
                ->where('assegnati', [$this->acc->id])
                ->where('bambini', [])
                ->where('risultati', []));
    }

    public function test_si_scelgono_gli_accompagnatori_anche_nessuno(): void
    {
        $altro = $this->persona(Ruolo::Accompagnatore);

        $this->actingAs($this->resp1)->put("/assegnazioni/fermate/{$this->f1a->id}/accompagnatori", ['accompagnatori' => [$this->acc->id, $altro->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $this->f1a->accompagnatori()->count());

        $this->actingAs($this->resp1)->put("/assegnazioni/fermate/{$this->f1a->id}/accompagnatori", ['accompagnatori' => [$altro->id]]);
        $this->assertSame([$altro->id], $this->f1a->accompagnatori()->pluck('users.id')->all());

        $this->actingAs($this->resp1)->put("/assegnazioni/fermate/{$this->f1a->id}/accompagnatori", ['accompagnatori' => []]);
        $this->assertSame(0, $this->f1a->accompagnatori()->count());
    }

    public function test_gli_accompagnatori_devono_esserlo_e_della_stessa_citta(): void
    {
        $soloResponsabile = $this->resp2;
        $altraCitta = $this->persona(Ruolo::Accompagnatore, $this->altra);

        $this->actingAs($this->resp1)->put("/assegnazioni/fermate/{$this->f1a->id}/accompagnatori", ['accompagnatori' => [$soloResponsabile->id]])
            ->assertSessionHasErrors('accompagnatori');
        $this->actingAs($this->resp1)->put("/assegnazioni/fermate/{$this->f1a->id}/accompagnatori", ['accompagnatori' => [$altraCitta->id]])
            ->assertSessionHasErrors('accompagnatori');

        $this->assertSame(0, $this->f1a->accompagnatori()->count());
    }

    // -------------------------------------------------------- children

    public function test_si_aggiunge_un_bambino_a_una_fermata(): void
    {
        $luca = $this->bambino('Luca');

        $this->actingAs($this->resp1)->post("/assegnazioni/fermate/{$this->f1a->id}/bambini", ['bambino_id' => $luca->id])
            ->assertSessionHas('status');

        $this->assertSame([$luca->id], $this->f1a->bambini()->pluck('bambini.id')->all());
    }

    public function test_su_una_linea_un_bambino_ha_una_sola_fermata_e_viene_spostato(): void
    {
        $luca = $this->bambino('Luca');
        $this->f1a->assegnaBambino($luca);

        $this->actingAs($this->resp1)->post("/assegnazioni/fermate/{$this->f1b->id}/bambini", ['bambino_id' => $luca->id])
            ->assertSessionHas('status', fn ($messaggio) => str_contains($messaggio, 'spostato dalla fermata «Parco»'));

        $this->assertSame(0, $this->f1a->bambini()->count());
        $this->assertSame(1, $this->f1b->bambini()->count());
    }

    public function test_un_bambino_puo_stare_su_linee_diverse(): void
    {
        $luca = $this->bambino('Luca');
        $this->f1a->assegnaBambino($luca);

        $this->actingAs($this->admin)->post("/assegnazioni/fermate/{$this->f2a->id}/bambini", ['bambino_id' => $luca->id])
            ->assertSessionHas('status');

        $this->assertSame(1, $this->f1a->bambini()->count(), 'the other line is untouched');
        $this->assertSame(1, $this->f2a->bambini()->count());
    }

    public function test_un_bambino_di_un_altra_citta_non_si_puo_aggiungere(): void
    {
        $estraneo = $this->bambino('Paolo', 'Estraneo', $this->altra);

        $this->actingAs($this->resp1)->post("/assegnazioni/fermate/{$this->f1a->id}/bambini", ['bambino_id' => $estraneo->id])
            ->assertSessionHasErrors('bambino_id');

        $this->assertSame(0, $this->f1a->bambini()->count());
    }

    public function test_si_toglie_un_bambino_e_la_fermata_puo_restare_senza_bambini(): void
    {
        $luca = $this->bambino('Luca');
        $this->f1a->assegnaBambino($luca);

        $this->actingAs($this->resp1)->delete("/assegnazioni/fermate/{$this->f1a->id}/bambini/{$luca->id}")
            ->assertSessionHas('status');

        $this->assertSame(0, $this->f1a->bambini()->count());
        $this->assertNotNull($luca->fresh(), 'the child itself is not deleted');

        $this->actingAs($this->resp1)->get("/assegnazioni/fermate/{$this->f1a->id}")
            ->assertInertia(fn (Assert $p) => $p->where('bambini', []));
    }

    public function test_togliere_un_bambino_non_assegnato_non_da_errori(): void
    {
        $luca = $this->bambino('Luca');

        $this->actingAs($this->resp1)->delete("/assegnazioni/fermate/{$this->f1a->id}/bambini/{$luca->id}")->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------------- search

    public function test_la_ricerca_trova_bambini_non_ancora_sulla_fermata(): void
    {
        $this->f1a->assegnaBambino($this->bambino('Luca', 'Rossi'));
        $this->bambino('Lucia', 'Verdi');
        $this->bambino('Anna', 'Bianchi');
        $this->bambino('Luigi', 'Estraneo', $this->altra);

        $this->actingAs($this->resp1)->get("/assegnazioni/fermate/{$this->f1a->id}?q=lu")
            ->assertInertia(fn (Assert $p) => $p
                ->where('ricerca', 'lu')
                ->has('risultati', 1)               // Luca is already here, Luigi is in another city
                ->where('risultati.0.nome', 'Lucia Verdi')
                ->where('risultati.0.altra_fermata', null));
    }

    public function test_la_ricerca_segnala_chi_e_gia_su_un_altra_fermata_della_linea(): void
    {
        $luca = $this->bambino('Luca', 'Rossi');
        $this->f1b->assegnaBambino($luca);

        $this->actingAs($this->resp1)->get("/assegnazioni/fermate/{$this->f1a->id}?q=luca")
            ->assertInertia(fn (Assert $p) => $p->has('risultati', 1)->where('risultati.0.altra_fermata', 'Via Roma'));
    }

    public function test_la_ricerca_parte_da_due_lettere_e_ha_un_limite(): void
    {
        Bambino::factory()->count(15)->create(['citta_id' => $this->citta->id, 'nome' => 'Marco']);

        $this->actingAs($this->resp1)->get("/assegnazioni/fermate/{$this->f1a->id}?q=m")->assertInertia(fn (Assert $p) => $p->where('risultati', []));
        $this->actingAs($this->resp1)->get("/assegnazioni/fermate/{$this->f1a->id}?q=marco")->assertInertia(fn (Assert $p) => $p->has('risultati', 10));
    }

    public function test_un_bambino_senza_cognome_si_trova_e_si_mostra_per_nome(): void
    {
        $this->bambino('Luca', '');

        $this->actingAs($this->resp1)->get("/assegnazioni/fermate/{$this->f1a->id}?q=luca")
            ->assertInertia(fn (Assert $p) => $p->where('risultati.0.nome', 'Luca'));
    }

    // ------------------------------------------- one starting stop per line

    public function test_le_fermate_successive_mostrano_l_accompagnatore_senza_ripeterlo(): void
    {
        $this->f1a->assegnaAccompagnatore($this->acc);

        $this->actingAs($this->resp1)->get("/assegnazioni/linee/{$this->linea1->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->has('fermate.0.accompagnatori', 1)
                ->where('fermate.0.accompagnatori.0.da', null)      // starts here
                ->has('fermate.1.accompagnatori', 1)
                ->where('fermate.1.accompagnatori.0.da', 'Parco'));  // with the group since Parco

        // On the later stop's page: not among those who start there, but listed as already with the group.
        $this->actingAs($this->resp1)->get("/assegnazioni/fermate/{$this->f1b->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('assegnati', [])
                ->has('precedenti', 1)
                ->where('precedenti.0.da', 'Parco')
                ->where('accompagnatori.0.inizia_a', 'Parco'));

        $this->assertSame(0, $this->f1b->accompagnatori()->count(), 'nothing is stored on the later stop');
    }

    public function test_un_accompagnatore_ha_una_sola_fermata_di_inizio_per_linea_e_viene_spostato(): void
    {
        $this->f1a->assegnaAccompagnatore($this->acc);

        $this->actingAs($this->resp1)->put("/assegnazioni/fermate/{$this->f1b->id}/accompagnatori", ['accompagnatori' => [$this->acc->id]])
            ->assertSessionHas('status', fn ($messaggio) => str_contains($messaggio, 'una persona è stata spostata'));

        $this->assertSame(0, $this->f1a->accompagnatori()->count());
        $this->assertSame([$this->acc->id], $this->f1b->accompagnatori()->pluck('users.id')->all());
    }

    public function test_la_stessa_persona_puo_iniziare_su_linee_diverse(): void
    {
        $this->f1a->assegnaAccompagnatore($this->acc);

        $this->actingAs($this->admin)->put("/assegnazioni/fermate/{$this->f2a->id}/accompagnatori", ['accompagnatori' => [$this->acc->id]])
            ->assertSessionHas('status', 'Accompagnatori aggiornati.');

        $this->assertSame(1, $this->f1a->accompagnatori()->count());
        $this->assertSame(1, $this->f2a->accompagnatori()->count());
    }

    public function test_scegliere_chi_gia_inizia_qui_non_sposta_nessuno(): void
    {
        $this->f1a->assegnaAccompagnatore($this->acc);

        $this->actingAs($this->resp1)->put("/assegnazioni/fermate/{$this->f1a->id}/accompagnatori", ['accompagnatori' => [$this->acc->id]])
            ->assertSessionHas('status', 'Accompagnatori aggiornati.');

        $this->assertSame(1, $this->f1a->accompagnatori()->count());
    }

    public function test_le_fermate_prima_della_partenza_sono_senza_accompagnatore(): void
    {
        $this->f1b->assegnaAccompagnatore($this->acc); // starts at Via Roma: Parco, before it, has nobody

        $this->actingAs($this->resp1)->get('/assegnazioni')
            ->assertInertia(fn (Assert $p) => $p->where('linee.0.senza_accompagnatore', 1));

        $this->actingAs($this->resp1)->get("/assegnazioni/linee/{$this->linea1->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('fermate.0.accompagnatori', [])
                ->has('fermate.1.accompagnatori', 1));
    }
}
