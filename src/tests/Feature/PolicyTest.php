<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Models\User;
use App\Policies\PresenzaPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authorization rules from the project document. The fixture is one city with two lines,
 * plus a second, unrelated city.
 */
class PolicyTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Citta $altra;

    private User $adminGlobale;

    private User $adminCitta;

    private User $adminAltra;

    private User $resp1;

    private User $resp2;

    private User $acc1;

    private User $acc2;

    private Linea $linea1;

    private Linea $linea2;

    private Linea $lineaAltra;

    private Fermata $f1a;

    private Fermata $f1b;

    private Fermata $f2a;

    private Fermata $fAltra;

    private Bambino $bambino;

    private Bambino $bambinoAltro;

    protected function setUp(): void
    {
        parent::setUp();

        // Early morning, before the lines arrive: attendance rules depend on the time of day.
        $this->travelTo(today()->setTime(7, 30));

        $this->citta = Citta::factory()->create();
        $this->altra = Citta::factory()->create();

        $this->adminGlobale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();
        $this->adminCitta = $this->persona($this->citta, Ruolo::AdminCitta);
        $this->adminAltra = $this->persona($this->altra, Ruolo::AdminCitta);
        $this->resp1 = $this->persona($this->citta, Ruolo::Responsabile);
        $this->resp2 = $this->persona($this->citta, Ruolo::Responsabile);
        $this->acc1 = $this->persona($this->citta, Ruolo::Accompagnatore);
        $this->acc2 = $this->persona($this->citta, Ruolo::Accompagnatore);

        $this->linea1 = Linea::factory()->create(['citta_id' => $this->citta->id]);
        $this->linea2 = Linea::factory()->create(['citta_id' => $this->citta->id]);
        $this->lineaAltra = Linea::factory()->create(['citta_id' => $this->altra->id]);

        $this->f1a = $this->fermata($this->linea1, 1, '07:40:00');
        $this->f1b = $this->fermata($this->linea1, 2, '07:50:00'); // last stop: expected arrival 07:50
        $this->f2a = $this->fermata($this->linea2, 1, '07:45:00');
        $this->fAltra = $this->fermata($this->lineaAltra, 1, '07:45:00');

        $this->linea1->assegnaResponsabile($this->resp1);
        $this->linea2->assegnaResponsabile($this->resp2);
        $this->f1a->assegnaAccompagnatore($this->acc1);
        $this->f1b->assegnaAccompagnatore($this->acc2);
        $this->f2a->assegnaAccompagnatore($this->acc2);

        $this->bambino = Bambino::factory()->create(['citta_id' => $this->citta->id]);
        $this->bambinoAltro = Bambino::factory()->create(['citta_id' => $this->altra->id]);
    }

    private function persona(Citta $citta, Ruolo $ruolo): User
    {
        return User::factory()->perCitta($citta)->conRuolo($ruolo)->create();
    }

    private function fermata(Linea $linea, int $ordine, string $orario): Fermata
    {
        return Fermata::factory()->create([
            'linea_id' => $linea->id,
            'citta_id' => $linea->citta_id,
            'ordine' => $ordine,
            'orario' => $orario,
        ]);
    }

    // ------------------------------------------------------------ cities

    public function test_solo_l_amministratore_globale_gestisce_le_citta(): void
    {
        $this->assertTrue($this->adminGlobale->can('viewAny', Citta::class));
        $this->assertTrue($this->adminGlobale->can('create', Citta::class));
        $this->assertTrue($this->adminGlobale->can('update', $this->citta));

        foreach ([$this->adminCitta, $this->resp1, $this->acc1] as $persona) {
            $this->assertFalse($persona->can('viewAny', Citta::class));
            $this->assertFalse($persona->can('create', Citta::class));
            $this->assertFalse($persona->can('update', $this->citta));
        }

        $this->assertFalse($this->adminGlobale->can('delete', $this->citta));
    }

    public function test_ognuno_vede_la_propria_citta_e_nessun_altra(): void
    {
        $this->assertTrue($this->acc1->can('view', $this->citta));
        $this->assertFalse($this->acc1->can('view', $this->altra));
        $this->assertTrue($this->adminGlobale->can('view', $this->altra));
    }

    // ------------------------------------------------------------ people

    public function test_chi_puo_invitare_chi(): void
    {
        // Global administrator: administrators only.
        $this->assertTrue($this->adminGlobale->can('invitare', [User::class, null, [Ruolo::AdminGlobale]]));
        $this->assertTrue($this->adminGlobale->can('invitare', [User::class, $this->citta, [Ruolo::AdminCitta]]));
        $this->assertFalse($this->adminGlobale->can('invitare', [User::class, $this->citta, [Ruolo::Responsabile]]));

        // City administrator: anybody except global administrators, in their own city.
        $this->assertTrue($this->adminCitta->can('invitare', [User::class, $this->citta, [Ruolo::Responsabile, Ruolo::Accompagnatore]]));
        $this->assertTrue($this->adminCitta->can('invitare', [User::class, $this->citta, [Ruolo::AdminCitta]]));
        $this->assertFalse($this->adminCitta->can('invitare', [User::class, $this->altra, [Ruolo::Responsabile]]));
        $this->assertFalse($this->adminCitta->can('invitare', [User::class, null, [Ruolo::AdminGlobale]]));
        $this->assertFalse($this->adminCitta->can('invitare', [User::class, $this->citta, []]));

        // Everybody else: nobody.
        foreach ([$this->resp1, $this->acc1] as $persona) {
            $this->assertFalse($persona->can('invitare', [User::class, $this->citta, [Ruolo::Accompagnatore]]));
        }
    }

    public function test_chi_puo_vedere_e_modificare_le_persone(): void
    {
        // City administrator: own city only, never a global administrator.
        $this->assertTrue($this->adminCitta->can('update', $this->acc1));
        $this->assertFalse($this->adminCitta->can('update', $this->adminAltra));
        $this->assertFalse($this->adminCitta->can('update', $this->adminGlobale));
        $this->assertTrue($this->adminCitta->can('assegnareRuoli', [$this->acc1, [Ruolo::Responsabile]]));
        $this->assertFalse($this->adminCitta->can('assegnareRuoli', [$this->acc1, [Ruolo::AdminGlobale]]));

        // Global administrator: administrators only.
        $this->assertTrue($this->adminGlobale->can('update', $this->adminCitta));
        $this->assertFalse($this->adminGlobale->can('update', $this->acc1));

        // Managers see the chaperones of their city, nobody else.
        $this->assertTrue($this->resp1->can('view', $this->acc1));
        $this->assertFalse($this->resp1->can('view', $this->resp2));
        $this->assertFalse($this->resp1->can('update', $this->acc1));

        // Chaperones see only themselves.
        $this->assertTrue($this->acc1->can('view', $this->acc1));
        $this->assertFalse($this->acc1->can('view', $this->acc2));
    }

    public function test_nessuno_si_elimina_da_solo(): void
    {
        $this->assertFalse($this->adminCitta->can('delete', $this->adminCitta));
        $this->assertTrue($this->adminCitta->can('delete', $this->acc1));
    }

    public function test_una_persona_con_piu_ruoli_somma_i_permessi(): void
    {
        $this->resp1->assegnaRuolo(Ruolo::Accompagnatore);
        $this->f2a->assegnaAccompagnatore($this->resp1);
        $this->resp1->unsetRelation('ruoliAssegnati');

        $this->assertTrue($this->resp1->can('gestireAssegnazioni', $this->f1a)); // as manager
        $this->assertTrue($this->resp1->can('registrare', [Presenza::class, $this->f2a, $this->bambino])); // as chaperone
    }

    // ---------------------------------------------------------- children

    public function test_i_bambini_li_gestisce_l_amministratore_di_citta(): void
    {
        $this->assertTrue($this->adminCitta->can('create', Bambino::class));
        $this->assertTrue($this->adminCitta->can('update', $this->bambino));
        $this->assertTrue($this->adminCitta->can('delete', $this->bambino));
        $this->assertFalse($this->adminCitta->can('update', $this->bambinoAltro));
        $this->assertFalse($this->adminGlobale->can('viewAny', Bambino::class));

        foreach ([$this->resp1, $this->acc1] as $persona) {
            $this->assertTrue($persona->can('viewAny', Bambino::class));
            $this->assertTrue($persona->can('view', $this->bambino));
            $this->assertFalse($persona->can('view', $this->bambinoAltro));
            $this->assertFalse($persona->can('create', Bambino::class));
            $this->assertFalse($persona->can('update', $this->bambino));
        }
    }

    // ------------------------------------------------------------- lines

    public function test_le_linee_le_gestisce_l_amministratore_di_citta(): void
    {
        $this->assertTrue($this->adminCitta->can('create', Linea::class));
        $this->assertTrue($this->adminCitta->can('update', $this->linea1));
        $this->assertFalse($this->adminCitta->can('update', $this->lineaAltra));
        $this->assertFalse($this->adminAltra->can('update', $this->linea1));

        foreach ([$this->resp1, $this->acc1, $this->adminGlobale] as $persona) {
            $this->assertFalse($persona->can('create', Linea::class));
            $this->assertFalse($persona->can('update', $this->linea1));
        }
    }

    public function test_ognuno_vede_solo_le_proprie_linee(): void
    {
        $this->assertTrue($this->adminCitta->can('view', $this->linea1));
        $this->assertTrue($this->resp1->can('view', $this->linea1));
        $this->assertFalse($this->resp1->can('view', $this->linea2));
        $this->assertTrue($this->acc1->can('view', $this->linea1));
        $this->assertFalse($this->acc1->can('view', $this->linea2));
        $this->assertTrue($this->acc2->can('view', $this->linea1));
        $this->assertTrue($this->acc2->can('view', $this->linea2));
        $this->assertFalse($this->adminAltra->can('view', $this->linea1));
    }

    // ------------------------------------------------------------- stops

    public function test_le_fermate_seguono_la_linea(): void
    {
        $this->assertTrue($this->adminCitta->can('create', [Fermata::class, $this->linea1]));
        $this->assertFalse($this->adminCitta->can('create', [Fermata::class, $this->lineaAltra]));
        $this->assertTrue($this->adminCitta->can('update', $this->f1a));
        $this->assertFalse($this->resp1->can('update', $this->f1a));

        // A chaperone sees every stop of the lines where they have a stop.
        $this->assertTrue($this->acc1->can('view', $this->f1b));
        $this->assertFalse($this->acc1->can('view', $this->f2a));
    }

    public function test_le_assegnazioni_le_fa_il_responsabile_solo_sulle_sue_linee(): void
    {
        $this->assertTrue($this->resp1->can('gestireAssegnazioni', $this->f1a));
        $this->assertFalse($this->resp1->can('gestireAssegnazioni', $this->f2a));
        $this->assertTrue($this->adminCitta->can('gestireAssegnazioni', $this->f2a));
        $this->assertFalse($this->adminAltra->can('gestireAssegnazioni', $this->f2a));
        $this->assertFalse($this->acc1->can('gestireAssegnazioni', $this->f1a));
    }

    // -------------------------------------------------------- attendance

    public function test_chi_vede_le_presenze(): void
    {
        $this->assertTrue($this->adminCitta->can('vedereLinea', [Presenza::class, $this->linea1]));
        $this->assertTrue($this->resp1->can('vedereLinea', [Presenza::class, $this->linea1]));
        $this->assertFalse($this->resp1->can('vedereLinea', [Presenza::class, $this->linea2]));
        $this->assertFalse($this->acc1->can('vedereLinea', [Presenza::class, $this->linea1]));

        // A chaperone is present from the starting stop to the end of the line.
        $this->assertTrue($this->acc1->can('vedereFermata', [Presenza::class, $this->f1a]));
        $this->assertTrue($this->acc1->can('vedereFermata', [Presenza::class, $this->f1b]));
        $this->assertFalse($this->acc2->can('vedereFermata', [Presenza::class, $this->f1a]), 'before the starting stop');
        $this->assertTrue($this->acc2->can('vedereFermata', [Presenza::class, $this->f1b]));
        $this->assertFalse($this->acc1->can('vedereFermata', [Presenza::class, $this->f2a]), 'another line');
    }

    public function test_l_accompagnatore_registra_dalla_fermata_di_inizio_in_poi(): void
    {
        // acc1 starts at f1a (first stop of line 1): present at f1a and f1b.
        $this->assertTrue($this->acc1->can('registrare', [Presenza::class, $this->f1a, $this->bambino]));
        $this->assertTrue($this->acc1->can('registrare', [Presenza::class, $this->f1b, $this->bambino]), 'later stop of the same line');

        // acc2 starts at f1b: not present at f1a, which comes before.
        $this->assertFalse($this->acc2->can('registrare', [Presenza::class, $this->f1a, $this->bambino]));
        $this->assertTrue($this->acc2->can('registrare', [Presenza::class, $this->f1b, $this->bambino]));

        // Another line, a manager and an administrator: no.
        $this->assertFalse($this->acc1->can('registrare', [Presenza::class, $this->f2a, $this->bambino]));
        $this->assertFalse($this->resp1->can('registrare', [Presenza::class, $this->f1a, $this->bambino]), 'manager only');
        $this->assertFalse($this->adminCitta->can('registrare', [Presenza::class, $this->f1a, $this->bambino]), 'administrator only');
    }

    public function test_un_bambino_di_un_altra_citta_non_si_puo_aggiungere(): void
    {
        $this->assertFalse($this->acc1->can('registrare', [Presenza::class, $this->f1a, $this->bambinoAltro]));
    }

    public function test_dopo_la_finestra_non_si_puo_piu_registrare_ne_correggere(): void
    {
        $this->assertTrue($this->acc1->can('registrare', [Presenza::class, $this->f1a, $this->bambino]));

        Presenza::registra($this->f1a, $this->bambino, today(), presente: true, registrataDa: $this->acc1);

        // Expected arrival is 07:50 (last stop); the window closes at 08:20.
        $this->travelTo(now()->setTime(8, 20));
        $this->assertTrue($this->acc1->can('registrare', [Presenza::class, $this->f1a, $this->bambino]));

        $this->travelTo(now()->setTime(8, 21));
        $this->assertFalse($this->acc1->can('registrare', [Presenza::class, $this->f1a, $this->bambino]), 'correction');
    }

    public function test_dopo_la_finestra_non_si_puo_nemmeno_fare_la_prima_registrazione(): void
    {
        $this->travelTo(now()->setTime(11, 0));

        $this->assertFalse($this->acc1->can('registrare', [Presenza::class, $this->f1a, $this->bambino]));
    }

    public function test_la_presenza_di_ieri_non_blocca_quella_di_oggi(): void
    {
        Presenza::registra($this->f1a, $this->bambino, today()->subDay(), presente: true);

        $this->assertTrue($this->acc1->can('registrare', [Presenza::class, $this->f1a, $this->bambino]));
    }

    public function test_la_modifica_e_aperta_fino_a_trenta_minuti_dopo_l_arrivo(): void
    {
        $this->assertTrue(PresenzaPolicy::modificaAperta($this->linea1));

        $this->travelTo(now()->setTime(8, 20));
        $this->assertTrue(PresenzaPolicy::modificaAperta($this->linea1));

        $this->travelTo(now()->setTime(8, 21));
        $this->assertFalse(PresenzaPolicy::modificaAperta($this->linea1));

        // A line without stops never has an open window.
        $vuota = Linea::factory()->create(['citta_id' => $this->citta->id]);
        $this->assertFalse(PresenzaPolicy::modificaAperta($vuota));
    }

    public function test_arrivo_previsto_e_l_orario_dell_ultima_fermata(): void
    {
        $arrivo = $this->linea1->arrivoPrevisto(today());

        $this->assertSame('07:50', $arrivo->format('H:i'));
        $this->assertSame(today()->toDateString(), $arrivo->toDateString());
    }
}
