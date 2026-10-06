<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Arrivo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The destination: a special last stop (for example the school) where nobody boards and the
 * chaperones only mark "arrived". Fixture: line "Verde" with Parco 07:40, Via Roma 07:50 and the
 * destination Scuola 08:05; the chaperone starts at Parco, so is with the group at the destination too.
 */
class DestinazioneTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Citta $altra;

    private User $admin;

    private User $globale;

    private User $resp;

    private User $acc;

    private Linea $verde;

    private Fermata $parco;

    private Fermata $viaRoma;

    private Fermata $scuola;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(today()->setTime(7, 30));

        $this->citta = Citta::factory()->create();
        $this->altra = Citta::factory()->create();
        $this->admin = $this->persona(Ruolo::AdminCitta);
        $this->globale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();
        $this->resp = $this->persona(Ruolo::Responsabile);
        $this->acc = $this->persona(Ruolo::Accompagnatore, ['nome' => 'Aldo', 'cognome' => 'Verdi']);

        $this->verde = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']);
        $this->parco = $this->fermata($this->verde, 1, '07:40:00', 'Parco');
        $this->viaRoma = $this->fermata($this->verde, 2, '07:50:00', 'Via Roma');
        $this->scuola = $this->fermata($this->verde, 3, '08:05:00', 'Scuola', true);

        $this->verde->assegnaResponsabile($this->resp);
        $this->parco->assegnaAccompagnatore($this->acc);
    }

    private function persona(Ruolo $ruolo, array $attributi = []): User
    {
        return User::factory()->perCitta($this->citta)->conRuolo($ruolo)->create($attributi);
    }

    private function fermata(Linea $linea, int $ordine, string $orario, string $nome, bool $destinazione = false): Fermata
    {
        return Fermata::factory()->create([
            'linea_id' => $linea->id, 'citta_id' => $linea->citta_id, 'ordine' => $ordine, 'orario' => $orario, 'nome' => $nome, 'destinazione' => $destinazione,
        ]);
    }

    /** Names of the line's stops in stored order. */
    private function ordine(Linea $linea): array
    {
        return Fermata::query()->where('linea_id', $linea->id)->orderBy('ordine')->pluck('nome')->all();
    }

    private function aggiungi(Linea $linea, string $nome, string $orario, bool $destinazione = false)
    {
        return $this->actingAs($this->admin)->post("/linee/{$linea->id}/fermate", ['nome' => $nome, 'orario' => $orario, 'destinazione' => $destinazione]);
    }

    // ------------------------------------------------ managing the stops

    public function test_si_crea_la_destinazione_ed_e_sempre_l_ultima(): void
    {
        $linea = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);

        $this->aggiungi($linea, 'Scuola', '08:05', true)->assertSessionHasNoErrors();
        $this->aggiungi($linea, 'Piazza', '07:45')->assertSessionHasNoErrors();
        $this->aggiungi($linea, 'Via Po', '07:55')->assertSessionHasNoErrors();

        $this->assertSame(['Piazza', 'Via Po', 'Scuola'], $this->ordine($linea));
        $this->assertTrue(Fermata::query()->where('nome', 'Scuola')->firstOrFail()->destinazione);
        $this->assertFalse(Fermata::query()->where('nome', 'Piazza')->firstOrFail()->destinazione);
    }

    public function test_una_linea_ha_una_sola_destinazione(): void
    {
        $this->aggiungi($this->verde, 'Altra scuola', '08:10', true)
            ->assertSessionHasErrors(['destinazione' => 'Questa linea ha già una destinazione («Scuola»): ce ne può essere una sola.']);

        $this->assertSame(['Parco', 'Via Roma', 'Scuola'], $this->ordine($this->verde));
    }

    public function test_il_database_stesso_rifiuta_due_destinazioni_sulla_stessa_linea(): void
    {
        $this->expectException(QueryException::class);

        $this->fermata($this->verde, 9, '08:10:00', 'Altra', true);
    }

    public function test_la_destinazione_non_puo_essere_prima_delle_altre_fermate(): void
    {
        $linea = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);
        $this->aggiungi($linea, 'Piazza', '07:45');

        $this->aggiungi($linea, 'Scuola', '07:30', true)
            ->assertSessionHasErrors(['orario' => 'La destinazione è l\'ultima fermata: scegli un orario uguale o successivo a quello delle altre (ore 07:45).']);
        $this->assertSame(['Piazza'], $this->ordine($linea));

        // The same time is fine: the destination still goes last.
        $this->aggiungi($linea, 'Scuola', '07:45', true)->assertSessionHasNoErrors();
        $this->assertSame(['Piazza', 'Scuola'], $this->ordine($linea));
    }

    public function test_una_fermata_normale_non_puo_essere_dopo_la_destinazione(): void
    {
        $this->aggiungi($this->verde, 'Tardi', '08:30')
            ->assertSessionHasErrors(['orario' => 'Dopo la destinazione (ore 08:05) non ci sono altre fermate: scegli un orario precedente.']);

        // At the same time as the destination is allowed; it stays before it.
        $this->aggiungi($this->verde, 'Insieme', '08:05')->assertSessionHasNoErrors();
        $this->assertSame(['Parco', 'Via Roma', 'Insieme', 'Scuola'], $this->ordine($this->verde));
    }

    public function test_si_modifica_una_fermata_in_destinazione_e_viceversa(): void
    {
        $linea = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);
        $this->aggiungi($linea, 'Piazza', '07:45');
        $this->aggiungi($linea, 'Scuola', '08:05');
        $scuola = Fermata::query()->where('nome', 'Scuola')->firstOrFail();

        $this->actingAs($this->admin)->put("/fermate/{$scuola->id}", ['nome' => 'Scuola', 'orario' => '08:05', 'destinazione' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($scuola->fresh()->destinazione);

        $this->actingAs($this->admin)->put("/fermate/{$scuola->id}", ['nome' => 'Scuola', 'orario' => '08:05', 'destinazione' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($scuola->fresh()->destinazione);
    }

    public function test_non_si_trasforma_in_destinazione_una_fermata_con_bambini_o_accompagnatori(): void
    {
        $this->actingAs($this->admin)->put("/fermate/{$this->viaRoma->id}", ['nome' => 'Via Roma', 'orario' => '07:50', 'destinazione' => true])
            ->assertSessionHasErrors('destinazione'); // already has a destination

        $linea = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);
        $piazza = $this->fermata($linea, 1, '07:45:00', 'Piazza');
        $piazza->assegnaBambino(Bambino::factory()->create(['citta_id' => $this->citta->id]));

        $this->actingAs($this->admin)->put("/fermate/{$piazza->id}", ['nome' => 'Piazza', 'orario' => '07:45', 'destinazione' => true])
            ->assertSessionHasErrors(['destinazione' => 'Alla destinazione non si assegnano bambini né accompagnatori: toglili prima da questa fermata.']);
        $this->assertFalse($piazza->fresh()->destinazione);
    }

    public function test_le_pagine_di_gestione_conoscono_la_destinazione(): void
    {
        $this->actingAs($this->admin)->get("/linee/{$this->verde->id}/edit")
            ->assertInertia(fn (Assert $p) => $p
                ->where('fermate.0.destinazione', false)
                ->where('fermate.2.nome', 'Scuola')
                ->where('fermate.2.destinazione', true));

        $this->actingAs($this->admin)->get("/linee/{$this->verde->id}/fermate/create")
            ->assertInertia(fn (Assert $p) => $p->where('destinazione_esistente', 'Scuola'));

        $this->actingAs($this->admin)->get("/fermate/{$this->scuola->id}/edit")
            ->assertInertia(fn (Assert $p) => $p->where('fermata.destinazione', true)->where('destinazione_esistente', null));

        $this->actingAs($this->admin)->get("/fermate/{$this->viaRoma->id}/edit")
            ->assertInertia(fn (Assert $p) => $p->where('fermata.destinazione', false)->where('destinazione_esistente', 'Scuola'));
    }

    public function test_duplicare_la_linea_copia_anche_la_destinazione(): void
    {
        $this->actingAs($this->admin)->post("/linee/{$this->verde->id}/duplica", ['nome' => 'Verde - Ritorno'])->assertSessionHasNoErrors();

        $copia = Linea::query()->where('nome', 'Verde - Ritorno')->firstOrFail();
        $this->assertSame(['Parco', 'Via Roma', 'Scuola'], $this->ordine($copia));
        $this->assertSame('Scuola', $copia->destinazione()?->nome);
    }

    // ------------------------------------------------------ assignments

    public function test_non_si_assegnano_bambini_ne_accompagnatori_alla_destinazione(): void
    {
        $bambino = Bambino::factory()->create(['citta_id' => $this->citta->id]);

        $this->actingAs($this->resp)->post("/assegnazioni/fermate/{$this->scuola->id}/bambini", ['bambino_id' => $bambino->id])
            ->assertSessionHasErrors(['bambino_id' => 'Alla destinazione non si assegnano bambini né accompagnatori.']);
        $this->actingAs($this->resp)->put("/assegnazioni/fermate/{$this->scuola->id}/accompagnatori", ['accompagnatori' => [$this->acc->id]])
            ->assertSessionHasErrors('accompagnatori');

        $this->assertSame(0, $this->scuola->bambini()->count());
        $this->assertSame(0, $this->scuola->accompagnatori()->count());
    }

    public function test_la_pagina_della_destinazione_rimanda_alla_linea(): void
    {
        $this->actingAs($this->resp)->get("/assegnazioni/fermate/{$this->scuola->id}")
            ->assertRedirect("/assegnazioni/linee/{$this->verde->id}")
            ->assertSessionHas('errore');
    }

    public function test_il_riepilogo_indica_la_destinazione_e_l_accompagnatore_la_raggiunge(): void
    {
        $this->actingAs($this->resp)->get("/assegnazioni/linee/{$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('fermate.2.destinazione', true)
                ->where('fermate.2.accompagnatori.0.nome', 'Aldo Verdi')
                ->where('fermate.2.accompagnatori.0.da', 'Parco'));
    }

    // ------------------------------------------------- the chaperone: arrived

    public function test_la_schermata_mostra_la_destinazione_senza_bambini(): void
    {
        $this->actingAs($this->acc)->get("/oggi/linee/{$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('fermate.2.nome', 'Scuola')
                ->where('fermate.2.destinazione', true)
                ->where('fermate.2.mia', true)
                ->where('fermate.2.bambini', [])
                ->where('arrivo_registrato', null));
    }

    public function test_si_segna_l_arrivo_e_si_salva_l_orario_del_tocco(): void
    {
        $this->travelTo(today()->setTime(8, 10, 40));

        $this->actingAs($this->acc)->postJson("/oggi/linee/{$this->verde->id}/arrivo")
            ->assertOk()
            ->assertJson(['ora' => '08:10', 'da' => 'Aldo Verdi', 'nuovo' => true]);

        $arrivo = Arrivo::query()->firstOrFail();
        $this->assertSame(today()->toDateString(), $arrivo->data->toDateString());
        $this->assertSame('08:10:40', $arrivo->arrivata_alle->format('H:i:s'));
        $this->assertSame($this->acc->id, $arrivo->registrata_da);
        $this->assertSame($this->citta->id, $arrivo->citta_id);

        $this->actingAs($this->acc)->get("/oggi/linee/{$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p->where('arrivo_registrato.ora', '08:10')->where('arrivo_registrato.da', 'Aldo Verdi'));
    }

    public function test_il_primo_tocco_vince(): void
    {
        $this->travelTo(today()->setTime(8, 10));
        $this->actingAs($this->acc)->postJson("/oggi/linee/{$this->verde->id}/arrivo")->assertOk();

        $this->travelTo(today()->setTime(8, 12));
        $altro = $this->persona(Ruolo::Accompagnatore);
        $this->parco->assegnaAccompagnatore($altro);

        // The same chaperone again, and a second chaperone: the recorded time does not change.
        $this->actingAs($this->acc)->postJson("/oggi/linee/{$this->verde->id}/arrivo")->assertOk()->assertJson(['ora' => '08:10', 'nuovo' => false]);
        $this->actingAs($altro)->postJson("/oggi/linee/{$this->verde->id}/arrivo")->assertOk()->assertJson(['ora' => '08:10', 'nuovo' => false]);

        $this->assertSame(1, Arrivo::count());
    }

    public function test_l_arrivo_si_segna_fino_a_trenta_minuti_dopo_l_arrivo_previsto(): void
    {
        $this->travelTo(today()->setTime(8, 35)); // 08:05 + 30
        $this->actingAs($this->acc)->postJson("/oggi/linee/{$this->verde->id}/arrivo")->assertOk();
    }

    public function test_dopo_la_finestra_l_arrivo_non_si_segna_piu(): void
    {
        $this->travelTo(today()->setTime(8, 36));

        $this->actingAs($this->acc)->postJson("/oggi/linee/{$this->verde->id}/arrivo")
            ->assertForbidden()
            ->assertJsonPath('message', fn ($messaggio) => str_contains($messaggio, 'Il tempo per segnare l\'arrivo è scaduto'));

        $this->assertSame(0, Arrivo::count());
    }

    public function test_chi_non_e_con_il_gruppo_alla_destinazione_non_segna_l_arrivo(): void
    {
        $this->travelTo(today()->setTime(8, 10));
        $estraneo = $this->persona(Ruolo::Accompagnatore); // chaperone of nothing
        $admin = $this->admin;

        $this->actingAs($estraneo)->postJson("/oggi/linee/{$this->verde->id}/arrivo")->assertForbidden();
        $this->actingAs($admin)->postJson("/oggi/linee/{$this->verde->id}/arrivo")->assertForbidden();

        $this->assertSame(0, Arrivo::count());
    }

    public function test_una_linea_senza_destinazione_non_ha_l_arrivo(): void
    {
        $linea = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);
        $piazza = $this->fermata($linea, 1, '07:45:00', 'Piazza');
        $piazza->assegnaAccompagnatore($this->acc);

        $this->actingAs($this->acc)->postJson("/oggi/linee/{$linea->id}/arrivo")
            ->assertStatus(422)
            ->assertJson(['message' => 'Questa linea non ha una destinazione.']);
    }

    public function test_su_una_linea_archiviata_non_si_segna_l_arrivo(): void
    {
        $this->verde->update(['archiviata_il' => now()]);

        $this->actingAs($this->acc)->postJson("/oggi/linee/{$this->verde->id}/arrivo")->assertNotFound();
    }

    public function test_alla_destinazione_non_si_segnano_bambini(): void
    {
        $bambino = Bambino::factory()->create(['citta_id' => $this->citta->id]);

        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->scuola->id}/presenze", ['bambino_id' => $bambino->id, 'presente' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($messaggio) => str_contains($messaggio, 'Alla destinazione non salgono bambini'));

        $this->assertSame(0, Presenza::count());
        $this->assertFalse($this->acc->can('registrare', [Presenza::class, $this->scuola, $bambino]));
    }

    public function test_la_ricerca_alla_destinazione_non_offre_nessuno(): void
    {
        Bambino::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Marco']);

        $this->actingAs($this->acc)->getJson("/oggi/fermate/{$this->scuola->id}/cerca?q=marco")->assertJsonCount(0, 'risultati');
    }

    public function test_i_bambini_degli_altri_stop_continuano_a_funzionare(): void
    {
        $luca = Bambino::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Luca']);
        $this->parco->assegnaBambino($luca);

        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->parco->id}/presenze", ['bambino_id' => $luca->id, 'presente' => true])->assertOk();
    }

    // ------------------------------------------------------- the dashboard

    public function test_la_dashboard_mostra_l_arrivo_della_giornata(): void
    {
        $this->travelTo(today()->setTime(8, 10));
        Arrivo::registra($this->verde, now(), $this->acc);

        $this->actingAs($this->resp)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p
                ->where('linee.0.con_destinazione', true)
                ->where('linee.0.arrivo_registrato.ora', '08:10')
                ->where('linee.0.arrivo_registrato.da', 'Aldo Verdi')
                ->where('linee.0.fermate.2.destinazione', true)
                ->where('linee.0.fermate.2.bambini', []));

        // Another day: no arrival recorded.
        $ieri = today()->subDay()->toDateString();
        $this->actingAs($this->resp)->get("/presenze?data={$ieri}")
            ->assertInertia(fn (Assert $p) => $p->where('linee.0.arrivo_registrato', null));
    }

    public function test_una_linea_senza_destinazione_non_mostra_l_arrivo(): void
    {
        $linea = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);
        $this->fermata($linea, 1, '07:45:00', 'Piazza');

        $this->actingAs($this->admin)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p->where('linee.0.nome', 'Blu')->where('linee.0.con_destinazione', false));
    }

    // ------------------------------------------------- administrators: arrival

    private function correggiArrivo(?string $ora, ?string $data = null, ?User $come = null, ?Linea $linea = null)
    {
        return $this->actingAs($come ?? $this->admin)->postJson('/presenze/arrivo', [
            'data' => $data ?? today()->toDateString(),
            'linea_id' => ($linea ?? $this->verde)->id,
            'ora' => $ora,
        ]);
    }

    public function test_l_amministratore_imposta_e_azzera_l_orario_di_arrivo_in_qualsiasi_giorno(): void
    {
        $this->travelTo(today()->setTime(15, 0)); // long after the morning window
        $ieri = today()->subDays(2)->toDateString();

        $this->correggiArrivo('08:20', $ieri)->assertOk()->assertJson(['ora' => '08:20']);
        $arrivo = Arrivo::query()->firstOrFail();
        $this->assertSame($ieri, $arrivo->data->toDateString());
        $this->assertSame($this->admin->id, $arrivo->registrata_da);

        $this->correggiArrivo('08:25', $ieri)->assertOk()->assertJson(['ora' => '08:25']);
        $this->assertSame(1, Arrivo::count(), 'it replaces, not adds');

        $this->correggiArrivo(null, $ieri)->assertOk()->assertJson(['ora' => null]);
        $this->assertSame(0, Arrivo::count());
    }

    public function test_l_orario_di_arrivo_non_puo_essere_nel_futuro_ne_malformato(): void
    {
        $this->correggiArrivo('09:00')->assertStatus(422);  // it is 07:30
        $this->correggiArrivo('8.20')->assertStatus(422);
        $this->actingAs($this->admin)->postJson('/presenze/arrivo', ['data' => today()->toDateString(), 'linea_id' => $this->verde->id])->assertStatus(422);

        $this->assertSame(0, Arrivo::count());
    }

    public function test_solo_gli_amministratori_correggono_l_arrivo(): void
    {
        $this->correggiArrivo('07:00', null, $this->resp)->assertForbidden();
        $this->correggiArrivo('07:00', null, $this->acc)->assertForbidden();
        $this->correggiArrivo('07:00', null, $this->globale)->assertOk();

        $altra = User::factory()->perCitta($this->altra)->conRuolo(Ruolo::AdminCitta)->create();
        $this->correggiArrivo('07:00', null, $altra)->assertNotFound();
    }

    public function test_non_si_corregge_l_arrivo_di_una_linea_senza_destinazione(): void
    {
        $linea = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);
        $this->fermata($linea, 1, '07:45:00', 'Piazza');

        $this->correggiArrivo('07:00', null, null, $linea)->assertStatus(422);
    }
}
