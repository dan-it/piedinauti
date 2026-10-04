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
 * The chaperone's morning screen. Fixture: line "Verde" with three stops (Parco 07:40, Via Roma 07:50,
 * Scuola 08:05 = expected arrival). The chaperone STARTS at Via Roma, so is present at Via Roma and Scuola
 * but not at Parco, which comes before. Another chaperone starts at Parco and so covers the whole line.
 * Line "Blu" belongs to someone else.
 */
class OggiTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Citta $altra;

    private User $acc;

    private User $altroAcc;

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

        // Early morning, well before the line's arrival: the result must not depend on the
        // real time the tests run at. Tests about the modification window travel from here.
        $this->travelTo(today()->setTime(7, 30));

        $this->citta = Citta::factory()->create();
        $this->altra = Citta::factory()->create();
        $this->acc = $this->persona(Ruolo::Accompagnatore);
        $this->altroAcc = $this->persona(Ruolo::Accompagnatore);

        $this->verde = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']);
        $this->blu = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);
        $this->parco = $this->fermata($this->verde, 1, '07:40:00', 'Parco');
        $this->viaRoma = $this->fermata($this->verde, 2, '07:50:00', 'Via Roma');
        $this->scuola = $this->fermata($this->verde, 3, '08:05:00', 'Scuola');
        $this->piazza = $this->fermata($this->blu, 1, '07:45:00', 'Piazza');

        $this->viaRoma->assegnaAccompagnatore($this->acc);      // starts here, goes on to Scuola
        $this->parco->assegnaAccompagnatore($this->altroAcc);   // starts here, covers the whole line
        $this->piazza->assegnaAccompagnatore($this->altroAcc);
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

    private function segna(Fermata $fermata, Bambino $bambino, bool $presente, ?User $come = null)
    {
        return $this->actingAs($come ?? $this->acc)->postJson("/oggi/fermate/{$fermata->id}/presenze", [
            'bambino_id' => $bambino->id, 'presente' => $presente,
        ]);
    }

    // ------------------------------------------------------------ access

    public function test_solo_gli_accompagnatori_accedono(): void
    {
        $admin = $this->persona(Ruolo::AdminCitta);
        $responsabile = $this->persona(Ruolo::Responsabile);

        foreach ([$admin, $responsabile] as $utente) {
            $this->actingAs($utente)->get('/oggi')->assertForbidden();
        }
    }

    public function test_gli_ospiti_vanno_al_login(): void
    {
        $this->get('/oggi')->assertRedirect('/login');
    }

    public function test_con_una_sola_linea_si_va_direttamente_alla_linea(): void
    {
        $this->actingAs($this->acc)->get('/oggi')->assertRedirect("/oggi/linee/{$this->verde->id}");
    }

    public function test_con_piu_linee_si_sceglie_ordinate_per_orario_della_fermata_di_inizio(): void
    {
        $this->piazza->assegnaAccompagnatore($this->acc); // Blu: starts at 07:45; Verde: starts at 07:50

        $this->actingAs($this->acc)->get('/oggi')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('oggi/Index')
                ->has('linee', 2)
                ->where('linee.0.nome', 'Blu')
                ->where('linee.0.prima_orario', '07:45')
                ->where('linee.1.nome', 'Verde')
                ->where('linee.1.inizio.nome', 'Via Roma')
                ->where('linee.1.inizio.orario', '07:50'));
    }

    public function test_senza_fermate_si_vede_un_messaggio(): void
    {
        $nuovo = $this->persona(Ruolo::Accompagnatore);

        $this->actingAs($nuovo)->get('/oggi')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('oggi/Index')->has('linee', 0));
    }

    public function test_una_linea_archiviata_non_compare(): void
    {
        $this->piazza->assegnaAccompagnatore($this->acc);
        $this->blu->update(['archiviata_il' => now()]);

        // Only Verde is left, so the chaperone goes straight to it.
        $this->actingAs($this->acc)->get('/oggi')->assertRedirect("/oggi/linee/{$this->verde->id}");
        $this->actingAs($this->acc)->get("/oggi/linee/{$this->blu->id}")->assertNotFound();
    }

    public function test_l_accompagnatore_solo_atterra_su_oggi_dalla_home(): void
    {
        $this->actingAs($this->acc)->get('/dashboard')->assertRedirect('/oggi');
    }

    public function test_chi_ha_anche_altri_ruoli_vede_la_home(): void
    {
        $this->acc->assegnaRuolo(Ruolo::Responsabile);

        $this->actingAs($this->acc->fresh())->get('/dashboard')->assertOk();
    }

    // ------------------------------------------------------------ a line

    public function test_si_vede_solo_una_linea_su_cui_si_ha_una_fermata(): void
    {
        $this->actingAs($this->acc)->get("/oggi/linee/{$this->verde->id}")->assertOk();
        $this->actingAs($this->acc)->get("/oggi/linee/{$this->blu->id}")->assertForbidden();

        $estraneo = $this->persona(Ruolo::Accompagnatore, $this->altra);
        $this->actingAs($estraneo)->get("/oggi/linee/{$this->verde->id}")->assertNotFound();
    }

    public function test_dalla_fermata_di_inizio_in_poi_le_fermate_sono_sue(): void
    {
        $this->parco->assegnaBambino($this->bambino('Luca', 'Rossi'));   // before the starting stop
        $this->viaRoma->assegnaBambino($this->bambino('Anna', 'Bianchi'));
        $this->scuola->assegnaBambino($this->bambino('Marco', 'Verdi'));  // later stop: also his

        $this->actingAs($this->acc)->get("/oggi/linee/{$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->component('oggi/Linea')
                ->where('linea.nome', 'Verde')
                ->where('arrivo', '08:05')
                ->has('fermate', 3)
                ->where('fermate.0.nome', 'Parco')
                ->where('fermate.0.mia', false)
                ->where('fermate.0.bambini', [])   // before the start: only a landmark
                ->where('fermate.1.nome', 'Via Roma')
                ->where('fermate.1.mia', true)
                ->where('fermate.1.bambini.0.nome', 'Anna Bianchi')
                ->where('fermate.2.nome', 'Scuola')
                ->where('fermate.2.mia', true)
                ->where('fermate.2.bambini.0.nome', 'Marco Verdi'));
    }

    public function test_chi_inizia_alla_prima_fermata_le_ha_tutte(): void
    {
        $this->actingAs($this->altroAcc)->get("/oggi/linee/{$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('fermate.0.mia', true)
                ->where('fermate.1.mia', true)
                ->where('fermate.2.mia', true));
    }

    public function test_una_fermata_senza_bambini_si_mostra_vuota(): void
    {
        $this->actingAs($this->acc)->get("/oggi/linee/{$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p->where('fermate.1.bambini', [])->where('fermate.2.bambini', []));
    }

    public function test_lo_stato_di_oggi_si_vede_e_quello_di_ieri_no(): void
    {
        $luca = $this->bambino('Luca');
        $anna = $this->bambino('Anna');
        $this->viaRoma->assegnaBambino($luca);
        $this->viaRoma->assegnaBambino($anna);
        Presenza::registra($this->viaRoma, $luca, today(), presente: true);
        Presenza::registra($this->viaRoma, $anna, today()->subDay(), presente: true); // yesterday

        $this->actingAs($this->acc)->get("/oggi/linee/{$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('fermate.1.bambini.0.nome', 'Anna Rossi')
                ->where('fermate.1.bambini.0.presente', null)
                ->where('fermate.1.bambini.1.nome', 'Luca Rossi')
                ->where('fermate.1.bambini.1.presente', true));
    }

    public function test_un_bambino_aggiunto_per_oggi_compare_come_temporaneo(): void
    {
        $ospite = $this->bambino('Zoe', 'Verdi');
        $this->segna($this->viaRoma, $ospite, true)->assertOk();

        $this->actingAs($this->acc)->get("/oggi/linee/{$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->has('fermate.1.bambini', 1)
                ->where('fermate.1.bambini.0.nome', 'Zoe Verdi')
                ->where('fermate.1.bambini.0.temporaneo', true)
                ->where('fermate.1.bambini.0.presente', true));
    }

    public function test_un_bambino_senza_cognome_si_mostra_per_nome(): void
    {
        $this->viaRoma->assegnaBambino($this->bambino('Luca', ''));

        $this->actingAs($this->acc)->get("/oggi/linee/{$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p->where('fermate.1.bambini.0.nome', 'Luca'));
    }

    public function test_dopo_la_finestra_non_si_modifica_piu_nulla(): void
    {
        $luca = $this->bambino('Luca');
        $this->viaRoma->assegnaBambino($luca);
        $this->viaRoma->assegnaBambino($this->bambino('Anna'));
        Presenza::registra($this->viaRoma, $luca, today(), presente: true);

        // Arrival 08:05: the window closes at 08:35.
        $this->travelTo(today()->setTime(8, 35));
        $this->actingAs($this->acc)->get("/oggi/linee/{$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('modifica_aperta', true)
                ->where('fermate.1.bambini.0.modificabile', true)
                ->where('fermate.1.bambini.1.modificabile', true));

        $this->travelTo(today()->setTime(8, 36));
        $this->actingAs($this->acc)->get("/oggi/linee/{$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('modifica_aperta', false)
                ->where('minuti_modifica', 30)
                ->where('fermate.1.bambini.0.modificabile', false)   // not marked: locked too
                ->where('fermate.1.bambini.1.modificabile', false)); // already marked: locked
    }

    // ------------------------------------------------------- marking

    public function test_si_segna_la_presenza(): void
    {
        $luca = $this->bambino('Luca');
        $this->viaRoma->assegnaBambino($luca);

        $this->segna($this->viaRoma, $luca, true)
            ->assertOk()
            ->assertJson(['bambino_id' => $luca->id, 'presente' => true, 'temporaneo' => false]);

        $presenza = Presenza::query()->firstOrFail();
        $this->assertTrue($presenza->presente);
        $this->assertSame(today()->toDateString(), $presenza->data->toDateString());
        $this->assertSame($this->verde->id, $presenza->linea_id);
        $this->assertSame($this->viaRoma->id, $presenza->fermata_id);
        $this->assertSame($this->acc->id, $presenza->registrata_da);
        $this->assertSame($this->citta->id, $presenza->citta_id);
    }

    public function test_si_segna_anche_alle_fermate_dopo_quella_di_inizio(): void
    {
        $marco = $this->bambino('Marco');
        $this->scuola->assegnaBambino($marco);

        // The chaperone started at Via Roma and is with the group at Scuola too.
        $this->segna($this->scuola, $marco, true)->assertOk();

        $this->assertSame($this->scuola->id, Presenza::query()->firstOrFail()->fermata_id);
    }

    public function test_non_si_segna_prima_della_fermata_di_inizio(): void
    {
        $luca = $this->bambino('Luca');
        $this->parco->assegnaBambino($luca);

        $this->segna($this->parco, $luca, true)->assertForbidden();

        $this->assertSame(0, Presenza::count());
    }

    public function test_ripetere_o_cambiare_il_tocco_non_crea_duplicati(): void
    {
        $luca = $this->bambino('Luca');
        $this->viaRoma->assegnaBambino($luca);

        $this->segna($this->viaRoma, $luca, true)->assertOk();
        $this->segna($this->viaRoma, $luca, true)->assertOk();
        $this->assertSame(1, Presenza::count());

        $this->segna($this->viaRoma, $luca, false)->assertOk()->assertJson(['presente' => false]);
        $this->assertSame(1, Presenza::count());
        $this->assertFalse(Presenza::query()->firstOrFail()->presente);
    }

    public function test_un_bambino_non_assegnato_alla_fermata_e_temporaneo(): void
    {
        $ospite = $this->bambino('Zoe');

        $this->segna($this->viaRoma, $ospite, true)->assertJson(['temporaneo' => true]);

        $this->assertTrue(Presenza::query()->firstOrFail()->temporaneo);
    }

    public function test_un_bambino_assegnato_non_e_temporaneo(): void
    {
        $luca = $this->bambino('Luca');
        $this->viaRoma->assegnaBambino($luca);

        $this->segna($this->viaRoma, $luca, true)->assertJson(['temporaneo' => false]);
    }

    public function test_spostare_un_bambino_su_un_altra_fermata_della_linea_aggiorna_la_stessa_riga(): void
    {
        $luca = $this->bambino('Luca');
        $this->viaRoma->assegnaBambino($luca);
        $this->segna($this->viaRoma, $luca, true)->assertOk();

        $this->segna($this->scuola, $luca, true)->assertOk();

        $this->assertSame(1, Presenza::count());
        $this->assertSame($this->scuola->id, Presenza::query()->firstOrFail()->fermata_id);
    }

    public function test_un_bambino_puo_essere_segnato_su_linee_diverse_nello_stesso_giorno(): void
    {
        $luca = $this->bambino('Luca');
        $this->piazza->assegnaAccompagnatore($this->acc);

        $this->segna($this->viaRoma, $luca, true)->assertOk();
        $this->segna($this->piazza, $luca, true)->assertOk();

        $this->assertSame(2, Presenza::count());
    }

    public function test_non_si_segna_su_una_linea_dove_non_si_e_accompagnatori(): void
    {
        $luca = $this->bambino('Luca');

        $this->segna($this->piazza, $luca, true)->assertForbidden();

        $this->assertSame(0, Presenza::count());
    }

    public function test_un_bambino_di_un_altra_citta_non_si_puo_segnare(): void
    {
        $estraneo = $this->bambino('Paolo', 'Estraneo', $this->altra);

        $this->segna($this->viaRoma, $estraneo, true)->assertStatus(422)->assertJson(['message' => 'Bambino non trovato.']);

        $this->assertSame(0, Presenza::count());
    }

    public function test_i_dati_sono_validati(): void
    {
        $luca = $this->bambino('Luca');

        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->viaRoma->id}/presenze", ['bambino_id' => $luca->id])->assertStatus(422);
        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->viaRoma->id}/presenze", ['presente' => true])->assertStatus(422);
        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->viaRoma->id}/presenze", ['bambino_id' => $luca->id, 'presente' => 'forse'])->assertStatus(422);
    }

    public function test_chi_non_e_accompagnatore_non_segna(): void
    {
        $luca = $this->bambino('Luca');
        $admin = $this->persona(Ruolo::AdminCitta);

        $this->segna($this->viaRoma, $luca, true, $admin)->assertForbidden();
    }

    public function test_dopo_la_finestra_nessuna_modifica_con_un_messaggio(): void
    {
        $luca = $this->bambino('Luca');
        $this->viaRoma->assegnaBambino($luca);
        $this->segna($this->viaRoma, $luca, true)->assertOk();

        $this->travelTo(today()->setTime(8, 36)); // arrival 08:05 + 30 minutes = 08:35
        $this->segna($this->viaRoma, $luca, false)
            ->assertForbidden()
            ->assertJsonPath('message', fn ($messaggio) => str_contains($messaggio, '30 minuti dall\'arrivo della linea'));

        $this->assertTrue(Presenza::query()->firstOrFail()->presente, 'the original mark is untouched');
    }

    public function test_dopo_la_finestra_non_si_fa_nemmeno_la_prima_registrazione(): void
    {
        $luca = $this->bambino('Luca');
        $this->viaRoma->assegnaBambino($luca);
        $this->travelTo(today()->setTime(11, 0));

        $this->segna($this->viaRoma, $luca, true)->assertForbidden();

        $this->assertSame(0, Presenza::count());
    }

    public function test_su_una_linea_archiviata_non_si_segna(): void
    {
        $luca = $this->bambino('Luca');
        $this->verde->update(['archiviata_il' => now()]);

        // The stop is hidden together with the line: "not found" for the chaperone.
        $this->segna($this->viaRoma, $luca, true)->assertNotFound();
        $this->assertSame(0, Presenza::count());
    }

    // ---------------------------------------------------------- search

    public function test_la_ricerca_trova_i_bambini_della_citta_non_ancora_in_elenco(): void
    {
        $this->viaRoma->assegnaBambino($this->bambino('Luca', 'Rossi'));
        $this->bambino('Lucia', 'Verdi');
        $this->bambino('Luigi', 'Estraneo', $this->altra);
        $aggiunto = $this->bambino('Lucio', 'Neri');
        $this->segna($this->viaRoma, $aggiunto, true)->assertOk(); // already added today

        $this->actingAs($this->acc)->getJson("/oggi/fermate/{$this->viaRoma->id}/cerca?q=lu")
            ->assertOk()
            ->assertJsonCount(1, 'risultati')
            ->assertJsonPath('risultati.0.nome', 'Lucia Verdi')
            ->assertJsonPath('risultati.0.altrove', null);
    }

    public function test_la_ricerca_segnala_chi_oggi_e_gia_su_un_altra_fermata(): void
    {
        $luca = $this->bambino('Luca', 'Rossi');
        $this->segna($this->scuola, $luca, true)->assertOk();

        $this->actingAs($this->acc)->getJson("/oggi/fermate/{$this->viaRoma->id}/cerca?q=luca")
            ->assertJsonPath('risultati.0.altrove', 'Scuola');
    }

    public function test_la_ricerca_parte_da_due_lettere_ha_un_limite_e_vuole_una_fermata_propria(): void
    {
        Bambino::factory()->count(15)->create(['citta_id' => $this->citta->id, 'nome' => 'Marco']);

        $this->actingAs($this->acc)->getJson("/oggi/fermate/{$this->viaRoma->id}/cerca?q=m")->assertJsonCount(0, 'risultati');
        $this->actingAs($this->acc)->getJson("/oggi/fermate/{$this->viaRoma->id}/cerca?q=marco")->assertJsonCount(10, 'risultati');
        // Parco comes before the chaperone's starting stop.
        $this->actingAs($this->acc)->getJson("/oggi/fermate/{$this->parco->id}/cerca?q=marco")->assertForbidden();
    }

    public function test_dopo_la_finestra_la_ricerca_non_offre_piu_nessuno(): void
    {
        Bambino::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Marco']);
        $this->travelTo(today()->setTime(8, 36));

        $this->actingAs($this->acc)->getJson("/oggi/fermate/{$this->viaRoma->id}/cerca?q=marco")->assertJsonCount(0, 'risultati');
    }
}
