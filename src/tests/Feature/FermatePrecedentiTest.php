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
 * A chaperone can also work on the stops just before their own, as many as the line allows
 * ("fermate_precedenti_visibili"): look at the children and mark them, under the same rules as on
 * their own stops.
 *
 * Fixture: line "Verde" with stops A 07:30, B 07:35, C 07:40, D 07:45, E 07:50 and the destination
 * Scuola 08:00. The chaperone "acc" starts at D (so is present at D, E and Scuola); "altroAcc" starts
 * at A. Each of A..E has one child assigned; Zoe is added for the day at C.
 */
class FermatePrecedentiTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Citta $altra;

    private User $admin;

    private User $acc;

    private User $altroAcc;

    private Linea $verde;

    /** @var array<string, Fermata> */
    private array $f = [];

    /** @var array<string, Bambino> */
    private array $b = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(today()->setTime(7, 30));

        $this->citta = Citta::factory()->create();
        $this->altra = Citta::factory()->create();
        $this->admin = $this->persona(Ruolo::AdminCitta);
        $this->acc = $this->persona(Ruolo::Accompagnatore);
        $this->altroAcc = $this->persona(Ruolo::Accompagnatore, ['nome' => 'Aldo', 'cognome' => 'Verdi']);

        $this->verde = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']);

        foreach ([['A', 1, '07:30:00'], ['B', 2, '07:35:00'], ['C', 3, '07:40:00'], ['D', 4, '07:45:00'], ['E', 5, '07:50:00']] as [$nome, $ordine, $orario]) {
            $this->f[$nome] = Fermata::factory()->create([
                'linea_id' => $this->verde->id, 'citta_id' => $this->citta->id, 'ordine' => $ordine, 'orario' => $orario, 'nome' => $nome,
            ]);
            $this->b[$nome] = Bambino::factory()->create(['citta_id' => $this->citta->id, 'nome' => "Bimbo{$nome}", 'cognome' => '']);
            $this->f[$nome]->assegnaBambino($this->b[$nome]);
        }
        $this->f['Scuola'] = Fermata::factory()->create([
            'linea_id' => $this->verde->id, 'citta_id' => $this->citta->id, 'ordine' => 6, 'orario' => '08:00:00', 'nome' => 'Scuola', 'destinazione' => true,
        ]);

        $this->f['D']->assegnaAccompagnatore($this->acc);
        $this->f['A']->assegnaAccompagnatore($this->altroAcc);

        // What the other chaperone has marked so far.
        $zoe = Bambino::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Zoe', 'cognome' => '']);
        Presenza::registra($this->f['C'], $this->b['C'], today(), true, false, $this->altroAcc);
        Presenza::registra($this->f['B'], $this->b['B'], today(), false, false, $this->altroAcc);
        Presenza::registra($this->f['C'], $zoe, today(), true, true, $this->altroAcc);
    }

    private function persona(Ruolo $ruolo, array $attributi = []): User
    {
        return User::factory()->perCitta($this->citta)->conRuolo($ruolo)->create($attributi);
    }

    private function visibili(int $quante): void
    {
        $this->verde->update(['fermate_precedenti_visibili' => $quante]);
    }

    /** The line page as the given chaperone sees it. */
    private function pagina(?User $come = null)
    {
        return $this->actingAs($come ?? $this->acc)->get("/oggi/linee/{$this->verde->id}");
    }

    /** Names of the stops (A..Scuola) that are shown as "previous", in order. */
    private function precedenti(?User $come = null): array
    {
        $fermate = $this->pagina($come)->viewData('page')['props']['fermate'];

        return collect($fermate)->filter(fn ($f) => $f['precedente'])->pluck('nome')->values()->all();
    }

    // ------------------------------------------------------------ the setting

    public function test_per_impostazione_predefinita_non_si_vede_nessuna_fermata_precedente(): void
    {
        $this->pagina()
            ->assertInertia(fn (Assert $p) => $p
                ->where('precedenti_visibili', 0)
                ->where('fermate.2.nome', 'C')
                ->where('fermate.2.precedente', false)
                ->where('fermate.2.bambini', [])
                ->where('fermate.2.mia', false));
    }

    public function test_con_uno_si_vede_la_fermata_appena_prima_della_propria(): void
    {
        $this->visibili(1);

        $this->assertSame(['C'], $this->precedenti());
    }

    public function test_con_due_si_vedono_le_due_fermate_prima(): void
    {
        $this->visibili(2);

        $this->assertSame(['B', 'C'], $this->precedenti());
    }

    public function test_un_numero_grande_mostra_tutte_le_fermate_prima_della_propria_e_nessun_altra(): void
    {
        $this->visibili(50);

        $this->assertSame(['A', 'B', 'C'], $this->precedenti());
    }

    public function test_le_proprie_fermate_e_la_destinazione_non_sono_mai_precedenti(): void
    {
        $this->visibili(50);

        $this->pagina()->assertInertia(fn (Assert $p) => $p
            ->where('fermate.3.nome', 'D')->where('fermate.3.mia', true)->where('fermate.3.precedente', false)
            ->where('fermate.4.nome', 'E')->where('fermate.4.mia', true)->where('fermate.4.precedente', false)
            ->where('fermate.5.nome', 'Scuola')->where('fermate.5.mia', true)->where('fermate.5.precedente', false));
    }

    public function test_chi_inizia_alla_prima_fermata_non_ha_nulla_prima(): void
    {
        $this->visibili(50);

        $this->assertSame([], $this->precedenti($this->altroAcc));
    }

    public function test_ognuno_vede_le_fermate_prima_della_propria_partenza(): void
    {
        $this->visibili(1);
        $this->f['B']->assegnaAccompagnatore($this->persona(Ruolo::Accompagnatore)); // a third chaperone starting at B
        $terzo = $this->f['B']->accompagnatori()->first();

        $this->assertSame(['C'], $this->precedenti());           // starts at D
        $this->assertSame(['A'], $this->precedenti($terzo));     // starts at B
    }

    // ---------------------------------------------------- what they can see

    public function test_si_vedono_i_bambini_e_il_loro_stato(): void
    {
        $this->visibili(2);

        $this->pagina()->assertInertia(fn (Assert $p) => $p
            // B: one child, marked absent
            ->has('fermate.1.bambini', 1)
            ->where('fermate.1.bambini.0.nome', 'BimboB')
            ->where('fermate.1.bambini.0.presente', false)
            // C: its own child present, and Zoe added for the day
            ->has('fermate.2.bambini', 2)
            ->where('fermate.2.bambini.0.nome', 'BimboC')
            ->where('fermate.2.bambini.0.presente', true)
            ->where('fermate.2.bambini.0.temporaneo', false)
            ->where('fermate.2.bambini.1.nome', 'Zoe')
            ->where('fermate.2.bambini.1.presente', true)
            ->where('fermate.2.bambini.1.temporaneo', true)
            // A is out of range: only a landmark
            ->where('fermate.0.bambini', []));
    }

    public function test_un_bambino_non_ancora_segnato_si_vede_senza_stato(): void
    {
        $this->visibili(3);

        $this->pagina()->assertInertia(fn (Assert $p) => $p->where('fermate.0.bambini.0.nome', 'BimboA')->where('fermate.0.bambini.0.presente', null));
    }

    public function test_lo_stato_aggiornato_dagli_altri_compare_ricaricando(): void
    {
        $this->visibili(3);
        $this->pagina()->assertInertia(fn (Assert $p) => $p->where('fermate.0.bambini.0.presente', null));

        Presenza::registra($this->f['A'], $this->b['A'], today(), true, false, $this->altroAcc);

        $this->pagina()->assertInertia(fn (Assert $p) => $p->where('fermate.0.bambini.0.presente', true));
    }

    public function test_le_fermate_precedenti_si_possono_modificare_come_le_proprie(): void
    {
        $this->visibili(3);

        $this->pagina()->assertInertia(fn (Assert $p) => $p
            ->where('fermate.0.bambini.0.modificabile', true)
            ->where('fermate.1.bambini.0.modificabile', true)
            ->where('fermate.2.bambini.0.modificabile', true)
            ->where('fermate.3.bambini.0.modificabile', true));
    }

    public function test_si_segna_su_una_fermata_precedente_entro_il_numero_consentito(): void
    {
        $this->visibili(3);
        $bambino = $this->b['C'];   // marked present by the other chaperone

        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->f['C']->id}/presenze", ['bambino_id' => $bambino->id, 'presente' => false])
            ->assertOk()
            ->assertJson(['presente' => false, 'temporaneo' => false, 'applicata' => true]);

        $presenza = Presenza::query()->where('bambino_id', $bambino->id)->firstOrFail();
        $this->assertFalse($presenza->presente);
        $this->assertSame($this->acc->id, $presenza->registrata_da);
        $this->assertSame($this->f['C']->id, $presenza->fermata_id);
        $this->assertSame(1, Presenza::query()->where('bambino_id', $bambino->id)->count(), 'the same row is updated');
    }

    public function test_si_segna_la_prima_volta_un_bambino_di_una_fermata_precedente(): void
    {
        $this->visibili(3);

        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->f['A']->id}/presenze", ['bambino_id' => $this->b['A']->id, 'presente' => true])->assertOk();

        $this->pagina()->assertInertia(fn (Assert $p) => $p->where('fermate.0.bambini.0.presente', true));
    }

    public function test_oltre_il_numero_consentito_non_si_segna_ne_si_cerca(): void
    {
        $this->visibili(1);   // only C

        foreach (['B', 'A'] as $nome) {
            $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->f[$nome]->id}/presenze", ['bambino_id' => $this->b[$nome]->id, 'presente' => true])->assertForbidden();
            $this->actingAs($this->acc)->getJson("/oggi/fermate/{$this->f[$nome]->id}/cerca?q=bimbo")->assertForbidden();
        }

        $this->assertNull(Presenza::query()->where('bambino_id', $this->b['A']->id)->first());
    }

    public function test_con_zero_si_lavora_solo_dalla_propria_fermata_in_poi(): void
    {
        $this->visibili(0);

        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->f['C']->id}/presenze", ['bambino_id' => $this->b['C']->id, 'presente' => false])->assertForbidden();
        $this->actingAs($this->acc)->getJson("/oggi/fermate/{$this->f['C']->id}/cerca?q=bimbo")->assertForbidden();
    }

    public function test_si_aggiunge_un_bambino_per_il_giorno_a_una_fermata_precedente(): void
    {
        $this->visibili(3);
        $ospite = Bambino::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Marco', 'cognome' => '']);

        $this->actingAs($this->acc)->getJson("/oggi/fermate/{$this->f['B']->id}/cerca?q=marco")
            ->assertOk()
            ->assertJsonCount(1, 'risultati')
            ->assertJsonPath('risultati.0.nome', 'Marco');

        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->f['B']->id}/presenze", ['bambino_id' => $ospite->id, 'presente' => true])
            ->assertOk()
            ->assertJson(['temporaneo' => true]);
    }

    public function test_sulle_fermate_precedenti_vale_la_stessa_finestra_di_trenta_minuti(): void
    {
        $this->visibili(3);

        // Arrival 08:00: the window closes at 08:30, here as on the chaperone's own stops.
        $this->travelTo(today()->setTime(8, 31));

        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->f['C']->id}/presenze", ['bambino_id' => $this->b['C']->id, 'presente' => false])
            ->assertForbidden();
        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->f['D']->id}/presenze", ['bambino_id' => $this->b['D']->id, 'presente' => false])
            ->assertForbidden();

        $this->pagina()->assertInertia(fn (Assert $p) => $p
            ->where('modifica_aperta', false)
            ->where('fermate.2.bambini.0.modificabile', false));
    }

    public function test_un_tocco_fatto_senza_rete_e_inviato_dopo_vale_anche_sulle_fermate_precedenti(): void
    {
        $this->visibili(3);
        $this->travelTo(today()->setTime(12, 0));

        // Tapped at 07:50 with no signal and sent at midday: accepted, because 07:50 was inside the window.
        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->f['C']->id}/presenze", [
            'bambino_id' => $this->b['C']->id, 'presente' => false, 'registrata_il' => today()->setTime(7, 50)->toIso8601String(),
        ])->assertOk()->assertJson(['applicata' => true]);
    }

    public function test_tra_due_accompagnatori_vince_il_tocco_piu_recente(): void
    {
        $this->visibili(3);
        $bambino = $this->b['C'];

        // The other chaperone (who covers the whole line) marks later than this one tapped.
        $this->travelTo(today()->setTime(7, 40));
        $this->actingAs($this->altroAcc)->postJson("/oggi/fermate/{$this->f['C']->id}/presenze", ['bambino_id' => $bambino->id, 'presente' => true])->assertOk();

        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->f['C']->id}/presenze", [
            'bambino_id' => $bambino->id, 'presente' => false, 'registrata_il' => today()->setTime(7, 35)->toIso8601String(),
        ])->assertOk()->assertJson(['applicata' => false, 'presente' => true]);

        $this->assertTrue(Presenza::query()->where('bambino_id', $bambino->id)->firstOrFail()->presente);
    }

    public function test_le_proprie_fermate_funzionano_come_prima(): void
    {
        $this->visibili(3);

        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->f['D']->id}/presenze", ['bambino_id' => $this->b['D']->id, 'presente' => true])->assertOk();
    }

    // -------------------------------------------------------------- the rule

    public function test_la_regola_della_policy_coincide_con_quella_della_pagina(): void
    {
        foreach ([0, 1, 2, 3, 10] as $quante) {
            $this->visibili($quante);

            $nellaPagina = collect($this->pagina()->viewData('page')['props']['fermate'])
                ->filter(fn ($f) => $f['mia'] || $f['precedente'])
                ->pluck('nome')
                ->all();

            $perLaPolicy = collect($this->f)
                ->filter(fn (Fermata $fermata) => $this->acc->can('vedereFermata', [Presenza::class, $fermata->fresh()]))
                ->keys()
                ->all();

            $this->assertEqualsCanonicalizing($nellaPagina, $perLaPolicy, "with {$quante} previous stops (look)");

            // Marking follows the same stops (the destination only takes the arrival, no children).
            $segnabili = collect($this->f)
                ->reject(fn (Fermata $fermata) => $fermata->destinazione)
                ->filter(fn (Fermata $fermata) => $this->acc->can('registrare', [Presenza::class, $fermata->fresh(), $this->b['D']]))
                ->keys()
                ->all();
            $conBambini = collect($nellaPagina)->reject(fn ($nome) => $nome === 'Scuola')->all();

            $this->assertEqualsCanonicalizing($conBambini, $segnabili, "with {$quante} previous stops (mark)");
        }
    }

    public function test_la_policy_per_chi_non_e_accompagnatore_non_cambia(): void
    {
        $this->visibili(0);
        $responsabile = $this->persona(Ruolo::Responsabile);
        $this->verde->assegnaResponsabile($responsabile);

        foreach ($this->f as $fermata) {
            $this->assertTrue($this->admin->can('vedereFermata', [Presenza::class, $fermata]));
            $this->assertTrue($responsabile->can('vedereFermata', [Presenza::class, $fermata]));
        }
    }

    public function test_una_linea_non_influisce_sulle_altre(): void
    {
        $this->visibili(50);
        $blu = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);
        $x = Fermata::factory()->create(['linea_id' => $blu->id, 'citta_id' => $this->citta->id, 'ordine' => 1, 'orario' => '07:30:00']);
        $y = Fermata::factory()->create(['linea_id' => $blu->id, 'citta_id' => $this->citta->id, 'ordine' => 2, 'orario' => '07:40:00']);
        $y->assegnaAccompagnatore($this->acc);   // Blu has the default 0

        $this->assertFalse($this->acc->can('vedereFermata', [Presenza::class, $x->fresh()]));
    }

    // ------------------------------------------------------ the administrator

    public function test_l_amministratore_imposta_il_numero(): void
    {
        $this->actingAs($this->admin)->put("/linee/{$this->verde->id}/visibilita", ['fermate_precedenti_visibili' => 2])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Gli accompagnatori potranno vedere e segnare 2 fermate prima della loro.');
        $this->assertSame(2, $this->verde->fresh()->fermate_precedenti_visibili);

        $this->actingAs($this->admin)->put("/linee/{$this->verde->id}/visibilita", ['fermate_precedenti_visibili' => 1])
            ->assertSessionHas('status', 'Gli accompagnatori potranno vedere e segnare 1 fermata prima della loro.');

        $this->actingAs($this->admin)->put("/linee/{$this->verde->id}/visibilita", ['fermate_precedenti_visibili' => 0])
            ->assertSessionHas('status', 'Gli accompagnatori lavoreranno solo dalla loro fermata in poi.');
        $this->assertSame(0, $this->verde->fresh()->fermate_precedenti_visibili);
    }

    public function test_la_pagina_della_linea_mostra_il_valore_attuale(): void
    {
        $this->visibili(3);

        $this->actingAs($this->admin)->get("/linee/{$this->verde->id}/edit")
            ->assertInertia(fn (Assert $p) => $p->where('linea.fermate_precedenti_visibili', 3));
    }

    public function test_il_numero_deve_essere_un_intero_tra_0_e_99(): void
    {
        foreach (['', -1, 100, 'due', 2.5, null] as $valore) {
            $this->actingAs($this->admin)->put("/linee/{$this->verde->id}/visibilita", ['fermate_precedenti_visibili' => $valore])
                ->assertSessionHasErrors('fermate_precedenti_visibili');
        }

        $this->actingAs($this->admin)->put("/linee/{$this->verde->id}/visibilita", ['fermate_precedenti_visibili' => 99])->assertSessionHasNoErrors();
        $this->assertSame(99, $this->verde->fresh()->fermate_precedenti_visibili);
    }

    public function test_solo_l_amministratore_di_citta_lo_imposta(): void
    {
        $responsabile = $this->persona(Ruolo::Responsabile);
        $this->verde->assegnaResponsabile($responsabile);
        $globale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();

        foreach ([$this->acc, $responsabile, $globale] as $utente) {
            $this->actingAs($utente)->put("/linee/{$this->verde->id}/visibilita", ['fermate_precedenti_visibili' => 5])->assertForbidden();
        }

        $this->assertSame(0, $this->verde->fresh()->fermate_precedenti_visibili);
    }

    public function test_le_linee_di_altre_citta_e_quelle_archiviate_non_si_toccano(): void
    {
        $estranea = Linea::factory()->create(['citta_id' => $this->altra->id]);
        $this->actingAs($this->admin)->put("/linee/{$estranea->id}/visibilita", ['fermate_precedenti_visibili' => 5])->assertNotFound();

        $this->verde->update(['archiviata_il' => now()]);
        $this->actingAs($this->admin)->put("/linee/{$this->verde->id}/visibilita", ['fermate_precedenti_visibili' => 5])->assertNotFound();
    }

    public function test_duplicando_la_linea_si_copia_anche_il_numero(): void
    {
        $this->visibili(3);

        $this->actingAs($this->admin)->post("/linee/{$this->verde->id}/duplica", ['nome' => 'Verde - Ritorno'])->assertSessionHasNoErrors();

        $this->assertSame(3, Linea::query()->where('nome', 'Verde - Ritorno')->firstOrFail()->fermate_precedenti_visibili);
    }

    public function test_riordinando_le_fermate_per_orario_cambia_cio_che_si_vede(): void
    {
        $this->visibili(1);
        $this->assertSame(['C'], $this->precedenti());

        // C moves to the very first place in time: the stop right before D is now B.
        $this->f['C']->update(['orario' => '07:00:00']);
        $this->verde->riordinaFermate();

        $this->assertSame(['B'], $this->precedenti());
    }
}
