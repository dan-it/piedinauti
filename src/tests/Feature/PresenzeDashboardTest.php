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

/**
 * The attendance dashboard (read-only). Fixture: line "Verde" (Parco 07:40, Via Roma 07:50, Scuola 08:05)
 * managed by resp1, line "Blu" (Piazza 07:45) managed by resp2, and a line of another city.
 */
class PresenzeDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Citta $altra;

    private User $admin;

    private User $resp1;

    private User $resp2;

    private User $acc;

    private Linea $verde;

    private Linea $blu;

    private Fermata $parco;

    private Fermata $viaRoma;

    private Fermata $scuola;

    private Fermata $piazza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(today()->setTime(7, 30)); // before the line's arrival (08:05)

        $this->citta = Citta::factory()->create();
        $this->altra = Citta::factory()->create();
        $this->admin = $this->persona(Ruolo::AdminCitta);
        $this->resp1 = $this->persona(Ruolo::Responsabile);
        $this->resp2 = $this->persona(Ruolo::Responsabile);
        $this->acc = $this->persona(Ruolo::Accompagnatore, null, ['nome' => 'Aldo', 'cognome' => 'Verdi']);

        $this->verde = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']);
        $this->blu = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);
        $this->parco = $this->fermata($this->verde, 1, '07:40:00', 'Parco');
        $this->viaRoma = $this->fermata($this->verde, 2, '07:50:00', 'Via Roma');
        $this->scuola = $this->fermata($this->verde, 3, '08:05:00', 'Scuola');
        $this->piazza = $this->fermata($this->blu, 1, '07:45:00', 'Piazza');

        $this->verde->assegnaResponsabile($this->resp1);
        $this->blu->assegnaResponsabile($this->resp2);
        $this->parco->assegnaAccompagnatore($this->acc); // starts at Parco: with the group on the whole line
    }

    private function persona(Ruolo $ruolo, ?Citta $citta = null, array $attributi = []): User
    {
        return User::factory()->perCitta($citta ?? $this->citta)->conRuolo($ruolo)->create($attributi);
    }

    private function fermata(Linea $linea, int $ordine, string $orario, string $nome): Fermata
    {
        return Fermata::factory()->create([
            'linea_id' => $linea->id, 'citta_id' => $linea->citta_id, 'ordine' => $ordine, 'orario' => $orario, 'nome' => $nome,
        ]);
    }

    private function bambino(string $nome, string $cognome = 'Rossi'): Bambino
    {
        return Bambino::factory()->create(['citta_id' => $this->citta->id, 'nome' => $nome, 'cognome' => $cognome]);
    }

    /**
     * A morning on line Verde: Luca present, Anna absent, Marco not marked, Zoe added for the day
     * at Via Roma and present, Paolo (assigned to Parco) marked present at Scuola.
     */
    private function mattinaSullaVerde(): void
    {
        $luca = $this->bambino('Luca');
        $anna = $this->bambino('Anna');
        $marco = $this->bambino('Marco');
        $zoe = $this->bambino('Zoe', 'Verdi');
        $paolo = $this->bambino('Paolo', 'Neri');

        $this->parco->assegnaBambino($luca);
        $this->parco->assegnaBambino($anna);
        $this->parco->assegnaBambino($paolo);
        $this->viaRoma->assegnaBambino($marco);

        Presenza::registra($this->parco, $luca, today(), presente: true, registrataDa: $this->acc);
        Presenza::registra($this->parco, $anna, today(), presente: false, registrataDa: $this->acc);
        Presenza::registra($this->viaRoma, $zoe, today(), presente: true, temporaneo: true, registrataDa: $this->acc);
        Presenza::registra($this->scuola, $paolo, today(), presente: true, temporaneo: true, registrataDa: $this->acc);
    }

    // ------------------------------------------------------------ access

    public function test_gli_accompagnatori_non_accedono(): void
    {
        $this->actingAs($this->acc)->get('/presenze')->assertForbidden();
    }

    public function test_i_responsabili_guardano_senza_poter_modificare(): void
    {
        $this->actingAs($this->resp1)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p->where('puo_modificare', false)->where('citte', []));
    }

    public function test_gli_ospiti_vanno_al_login(): void
    {
        $this->get('/presenze')->assertRedirect('/login');
    }

    public function test_l_amministratore_vede_tutte_le_linee_della_citta_e_il_responsabile_solo_le_sue(): void
    {
        Linea::factory()->create(['citta_id' => $this->altra->id, 'nome' => 'Estranea']);

        $this->actingAs($this->admin)->get('/presenze')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('presenze/Index')
                ->has('linee', 2)
                ->where('linee.0.nome', 'Blu')
                ->where('linee.1.nome', 'Verde'));

        $this->actingAs($this->resp1)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p->has('linee', 1)->where('linee.0.nome', 'Verde'));
    }

    public function test_una_linea_archiviata_non_compare(): void
    {
        $this->blu->update(['archiviata_il' => now()]);

        $this->actingAs($this->admin)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p->has('linee', 1)->where('linee.0.nome', 'Verde'));
    }

    // ------------------------------------------------- one line, one day

    public function test_le_fermate_mostrano_i_bambini_con_lo_stato_di_oggi(): void
    {
        $this->mattinaSullaVerde();

        $this->actingAs($this->resp1)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p
                ->where('oggi', true)
                ->has('linee.0.fermate', 3)
                // Parco: Anna absent, Luca present; Paolo is marked at Scuola so he is not listed here.
                ->has('linee.0.fermate.0.bambini', 2)
                ->where('linee.0.fermate.0.bambini.0.nome', 'Anna Rossi')
                ->where('linee.0.fermate.0.bambini.0.stato', false)
                ->where('linee.0.fermate.0.bambini.1.nome', 'Luca Rossi')
                ->where('linee.0.fermate.0.bambini.1.stato', true)
                ->where('linee.0.fermate.0.bambini.1.temporaneo', false)
                // Via Roma: Marco (not marked) and Zoe, added for the day.
                ->has('linee.0.fermate.1.bambini', 2)
                ->where('linee.0.fermate.1.bambini.0.nome', 'Marco Rossi')
                ->where('linee.0.fermate.1.bambini.0.stato', null)
                ->where('linee.0.fermate.1.bambini.1.nome', 'Zoe Verdi')
                ->where('linee.0.fermate.1.bambini.1.stato', true)
                ->where('linee.0.fermate.1.bambini.1.temporaneo', true)
                // Scuola: Paolo, marked here.
                ->has('linee.0.fermate.2.bambini', 1)
                ->where('linee.0.fermate.2.bambini.0.nome', 'Paolo Neri'));
    }

    public function test_i_conteggi_della_linea_e_i_totali(): void
    {
        $this->mattinaSullaVerde();

        $this->actingAs($this->resp1)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p
                ->where('linee.0.presenti', 3)      // Luca, Zoe, Paolo
                ->where('linee.0.assenti', 1)       // Anna
                ->where('linee.0.non_segnati', 1)   // Marco
                ->where('totali.presenti', 3)
                ->where('totali.assenti', 1)
                ->where('totali.non_segnati', 1));
    }

    public function test_i_totali_sommano_tutte_le_linee_visibili(): void
    {
        $this->mattinaSullaVerde();
        $this->piazza->assegnaBambino($this->bambino('Sara'));

        $this->actingAs($this->admin)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p->where('totali.non_segnati', 2));  // Marco and Sara

        $this->actingAs($this->resp1)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p->where('totali.non_segnati', 1));  // only Marco: Sara is on Blu
    }

    public function test_si_vede_chi_ha_segnato(): void
    {
        $this->mattinaSullaVerde();

        $this->actingAs($this->resp1)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p
                ->where('linee.0.fermate.0.bambini.1.registrata_da', 'Aldo Verdi')
                ->where('linee.0.fermate.1.bambini.0.registrata_da', null)); // Marco: not marked
    }

    public function test_gli_accompagnatori_sono_con_il_gruppo_dalla_fermata_di_inizio(): void
    {
        $this->actingAs($this->resp1)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p
                ->where('linee.0.fermate.0.accompagnatori.0.nome', 'Aldo Verdi')
                ->where('linee.0.fermate.0.accompagnatori.0.da', null)
                ->where('linee.0.fermate.2.accompagnatori.0.nome', 'Aldo Verdi')
                ->where('linee.0.fermate.2.accompagnatori.0.da', 'Parco'));
    }

    public function test_una_fermata_senza_bambini_si_mostra_vuota(): void
    {
        $this->actingAs($this->resp1)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p->where('linee.0.fermate.1.bambini', [])->where('linee.0.non_segnati', 0));
    }

    public function test_un_bambino_senza_cognome_si_mostra_per_nome(): void
    {
        $this->parco->assegnaBambino($this->bambino('Luca', ''));

        $this->actingAs($this->resp1)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p->where('linee.0.fermate.0.bambini.0.nome', 'Luca'));
    }

    // --------------------------------------------------------- the day

    public function test_si_sceglie_il_giorno_e_si_vedono_solo_le_sue_presenze(): void
    {
        $luca = $this->bambino('Luca');
        $this->parco->assegnaBambino($luca);
        Presenza::registra($this->parco, $luca, today()->subDay(), presente: true, registrataDa: $this->acc);

        $ieri = today()->subDay()->toDateString();

        $this->actingAs($this->resp1)->get("/presenze?data={$ieri}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('data', $ieri)
                ->where('oggi', false)
                ->where('precedente', today()->subDays(2)->toDateString())
                ->where('successiva', today()->toDateString())
                ->where('totali.presenti', 1)
                ->where('linee.0.fermate.0.bambini.0.stato', true));

        // Today the same child has not been marked yet.
        $this->actingAs($this->resp1)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p
                ->where('oggi', true)
                ->where('successiva', null)
                ->where('precedente', $ieri)
                ->where('totali.presenti', 0)
                ->where('totali.non_segnati', 1)
                ->where('linee.0.fermate.0.bambini.0.stato', null));
    }

    public function test_un_giorno_senza_presenze_mostra_tutti_come_non_segnati(): void
    {
        $this->parco->assegnaBambino($this->bambino('Luca'));
        $this->parco->assegnaBambino($this->bambino('Anna'));

        $giorno = today()->subDays(5)->toDateString();

        $this->actingAs($this->resp1)->get("/presenze?data={$giorno}")
            ->assertInertia(fn (Assert $p) => $p->where('totali.presenti', 0)->where('totali.non_segnati', 2));
    }

    public function test_date_future_o_non_valide_sono_rifiutate(): void
    {
        $domani = today()->addDay()->toDateString();

        $this->actingAs($this->resp1)->get("/presenze?data={$domani}")->assertSessionHasErrors('data');
        $this->actingAs($this->resp1)->get('/presenze?data=ieri')->assertSessionHasErrors('data');
        $this->actingAs($this->resp1)->get('/presenze?data=2026-13-45')->assertSessionHasErrors('data');
    }

    public function test_la_pagina_non_modifica_nulla(): void
    {
        $this->mattinaSullaVerde();
        $prima = Presenza::count();

        $this->actingAs($this->admin)->get('/presenze');
        $this->actingAs($this->admin)->post('/presenze', ['data' => today()->toDateString()])->assertStatus(405);

        $this->assertSame($prima, Presenza::count());
    }

    // ------------------------------------------------------ open or closed

    public function test_oggi_la_linea_e_aperta_fino_a_trenta_minuti_dopo_l_arrivo(): void
    {
        $this->actingAs($this->resp1)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p
                ->where('linee.0.arrivo', '08:05')
                ->where('linee.0.chiusa', false)
                ->where('linee.0.modificabile_fino', '08:35'));

        $this->travelTo(today()->setTime(8, 36));

        $this->actingAs($this->resp1)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p->where('linee.0.chiusa', true));
    }

    public function test_i_giorni_passati_sono_chiusi(): void
    {
        $ieri = today()->subDay()->toDateString();

        $this->actingAs($this->resp1)->get("/presenze?data={$ieri}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('linee.0.chiusa', true)
                ->where('linee.0.modificabile_fino', null));
    }

    public function test_una_linea_senza_fermate_si_mostra_senza_errori(): void
    {
        $vuota = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Vuota']);

        $this->actingAs($this->admin)->get('/presenze')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('linee.2.nome', 'Vuota')
                ->where('linee.2.arrivo', null)
                ->where('linee.2.chiusa', true)
                ->where('linee.2.fermate', []));
    }
}
