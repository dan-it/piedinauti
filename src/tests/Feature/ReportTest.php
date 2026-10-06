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
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The attendance reports. The data is built by hand so that every number can be checked:
 *
 *   line Verde (managed by resp1): Parco 07:40, Via Roma 07:50, destination Scuola 08:05
 *   line Blu   (managed by resp2): Piazza 07:45, no destination
 *
 *   yesterday:        Verde  Luca present, Anna absent      | Blu  Marco present      | Verde arrived 08:10 (+5)
 *   two days ago:     Verde  Luca, Anna, Zoe (added for the day) present | Blu  Marco absent | Verde arrived 08:00 (-5)
 *   forty days ago:   Verde  Luca absent (outside the default 30 days)
 *
 *   Paolo is assigned to Parco and never marked.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Citta $altra;

    private User $admin;

    private User $globale;

    private User $resp1;

    private User $resp2;

    private User $acc;

    private Linea $verde;

    private Linea $blu;

    private Fermata $parco;

    private Fermata $viaRoma;

    private Fermata $scuola;

    private Fermata $piazza;

    private Bambino $luca;

    private Bambino $anna;

    private Bambino $marco;

    private Bambino $zoe;

    private Bambino $paolo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(today()->setTime(12, 0));

        $this->citta = Citta::factory()->create(['nome' => 'Alfa']);
        $this->altra = Citta::factory()->create(['nome' => 'Zeta']);
        $this->admin = $this->persona(Ruolo::AdminCitta);
        $this->globale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();
        $this->resp1 = $this->persona(Ruolo::Responsabile);
        $this->resp2 = $this->persona(Ruolo::Responsabile);
        $this->acc = $this->persona(Ruolo::Accompagnatore, ['nome' => 'Aldo', 'cognome' => 'Verdi']);

        $this->verde = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']);
        $this->blu = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);
        $this->parco = $this->fermata($this->verde, 1, '07:40:00', 'Parco');
        $this->viaRoma = $this->fermata($this->verde, 2, '07:50:00', 'Via Roma');
        $this->scuola = $this->fermata($this->verde, 3, '08:05:00', 'Scuola', true);
        $this->piazza = $this->fermata($this->blu, 1, '07:45:00', 'Piazza');
        $this->verde->assegnaResponsabile($this->resp1);
        $this->blu->assegnaResponsabile($this->resp2);
        $this->parco->assegnaAccompagnatore($this->acc);

        $this->luca = $this->bambino('Luca', 'Rossi');
        $this->anna = $this->bambino('Anna', 'Bianchi');
        $this->marco = $this->bambino('Marco', 'Verdi');
        $this->zoe = $this->bambino('Zoe', '');
        $this->paolo = $this->bambino('Paolo', 'Neri');
        $this->parco->assegnaBambino($this->luca);
        $this->parco->assegnaBambino($this->anna);
        $this->parco->assegnaBambino($this->paolo);
        $this->piazza->assegnaBambino($this->marco);

        $ieri = $this->giorno(1);
        $this->segna($this->parco, $this->luca, $ieri, true);
        $this->segna($this->parco, $this->anna, $ieri, false);
        $this->segna($this->piazza, $this->marco, $ieri, true);
        $this->arrivo($ieri, 8, 10);

        $dueGiorniFa = $this->giorno(2);
        $this->segna($this->parco, $this->luca, $dueGiorniFa, true);
        $this->segna($this->parco, $this->anna, $dueGiorniFa, true);
        $this->segna($this->viaRoma, $this->zoe, $dueGiorniFa, true, true);
        $this->segna($this->piazza, $this->marco, $dueGiorniFa, false);
        $this->arrivo($dueGiorniFa, 8, 0);

        $this->segna($this->parco, $this->luca, $this->giorno(40), false);
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

    private function bambino(string $nome, string $cognome, ?Citta $citta = null): Bambino
    {
        return Bambino::factory()->create(['citta_id' => ($citta ?? $this->citta)->id, 'nome' => $nome, 'cognome' => $cognome]);
    }

    private function giorno(int $giorniFa): CarbonInterface
    {
        return today()->subDays($giorniFa);
    }

    private function segna(Fermata $fermata, Bambino $bambino, CarbonInterface $giorno, bool $presente, bool $temporaneo = false): void
    {
        Presenza::registra($fermata, $bambino, $giorno, $presente, $temporaneo, $this->acc, $giorno->setTime(7, 50));
    }

    private function arrivo(CarbonInterface $giorno, int $ore, int $minuti): void
    {
        Arrivo::query()->create([
            'citta_id' => $this->citta->id, 'linea_id' => $this->verde->id, 'data' => $giorno->toDateString(), 'arrivata_alle' => $giorno->setTime($ore, $minuti),
        ]);
    }

    /**
     * Percentages and averages are compared by value: JSON writes 50.0 as 50, so an exact
     * comparison of types would fail for whole numbers.
     */
    private function uguale(float $atteso): \Closure
    {
        return fn ($valore) => is_numeric($valore) && abs($valore - $atteso) < 0.001;
    }

    // ------------------------------------------------------------ access

    public function test_gli_accompagnatori_non_accedono_ai_report(): void
    {
        foreach (['/report', '/report/bambini', '/report/esporta', "/report/bambini/{$this->luca->id}"] as $indirizzo) {
            $this->actingAs($this->acc)->get($indirizzo)->assertForbidden();
        }
    }

    public function test_gli_ospiti_vanno_al_login(): void
    {
        $this->get('/report')->assertRedirect('/login');
        $this->get('/report/esporta')->assertRedirect('/login');
    }

    public function test_responsabili_amministratori_e_globali_accedono(): void
    {
        foreach ([$this->resp1, $this->admin, $this->globale] as $utente) {
            $this->actingAs($utente)->get('/report')->assertOk();
            $this->actingAs($utente)->get('/report/bambini')->assertOk();
        }
    }

    // ------------------------------------------------------------- period

    public function test_il_periodo_predefinito_e_gli_ultimi_trenta_giorni(): void
    {
        $this->actingAs($this->admin)->get('/report')
            ->assertInertia(fn (Assert $p) => $p
                ->component('report/Index')
                ->where('a', today()->toDateString())
                ->where('da', today()->subDays(29)->toDateString())
                ->where('linea_scelta', null));
    }

    public function test_le_date_scelte_si_applicano(): void
    {
        $da = today()->subDays(3)->toDateString();
        $a = today()->subDays(2)->toDateString();

        $this->actingAs($this->admin)->get("/report?da={$da}&a={$a}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('da', $da)
                ->where('a', $a)
                ->where('totali.giorni', 1)         // only two days ago
                ->where('totali.presenti', 3)       // Luca, Anna and Zoe on Verde
                ->where('totali.assenti', 1)        // Marco on Blu
                ->etc());
    }

    public function test_date_non_valide_sono_rifiutate(): void
    {
        $oggi = today()->toDateString();

        $this->actingAs($this->admin)->get('/report?da=ieri')->assertSessionHasErrors('da');
        $this->actingAs($this->admin)->get('/report?da='.today()->addDay()->toDateString())->assertSessionHasErrors('da');
        $this->actingAs($this->admin)->get('/report?a='.today()->addDay()->toDateString())->assertSessionHasErrors('a');
        $this->actingAs($this->admin)->get('/report?da='.today()->toDateString().'&a='.today()->subDay()->toDateString())->assertSessionHasErrors('da');
        $this->actingAs($this->admin)->get('/report/bambini?da=2026-13-40&a='.$oggi)->assertSessionHasErrors('da');
        $this->actingAs($this->admin)->get('/report/esporta?da=ieri')->assertSessionHasErrors('da');
    }

    public function test_il_periodo_ha_un_massimo_di_366_giorni(): void
    {
        $this->actingAs($this->admin)->get('/report?da='.today()->subDays(366)->toDateString())->assertSessionHasErrors('da');
        $this->actingAs($this->admin)->get('/report?da='.today()->subDays(365)->toDateString())->assertSessionHasNoErrors();
    }

    public function test_un_periodo_senza_presenze_da_zeri_e_nessuna_frequenza(): void
    {
        $da = today()->subDays(100)->toDateString();
        $a = today()->subDays(90)->toDateString();

        $this->actingAs($this->admin)->get("/report?da={$da}&a={$a}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('totali.presenti', 0)
                ->where('totali.assenti', 0)
                ->where('totali.frequenza', null)
                ->where('totali.giorni', 0)
                ->where('giorni', []));
    }

    // ----------------------------------------------------------- by line

    public function test_il_riepilogo_per_linea(): void
    {
        $this->actingAs($this->admin)->get('/report')
            ->assertInertia(fn (Assert $p) => $p
                ->has('linee_riepilogo', 2)
                // Blu: yesterday Marco present, two days ago absent.
                ->where('linee_riepilogo.0.nome', 'Blu')
                ->where('linee_riepilogo.0.giorni', 2)
                ->where('linee_riepilogo.0.presenti', 1)
                ->where('linee_riepilogo.0.assenti', 1)
                ->where('linee_riepilogo.0.bambini', 1)
                ->where('linee_riepilogo.0.frequenza', $this->uguale(50.0))
                ->where('linee_riepilogo.0.puntualita', null)
                // Verde: present Luca (yesterday), Luca+Anna+Zoe (two days ago); absent Anna (yesterday).
                ->where('linee_riepilogo.1.nome', 'Verde')
                ->where('linee_riepilogo.1.giorni', 2)
                ->where('linee_riepilogo.1.presenti', 4)
                ->where('linee_riepilogo.1.assenti', 1)
                ->where('linee_riepilogo.1.bambini', 3)
                ->where('linee_riepilogo.1.frequenza', $this->uguale(80.0)));
    }

    public function test_i_totali_sommano_le_linee(): void
    {
        $this->actingAs($this->admin)->get('/report')
            ->assertInertia(fn (Assert $p) => $p
                ->where('totali.presenti', 5)
                ->where('totali.assenti', 2)
                ->where('totali.frequenza', $this->uguale(71.4))   // 5 out of 7 marked
                ->where('totali.giorni', 2));
    }

    public function test_la_puntualita_e_la_media_degli_scarti(): void
    {
        // Expected 08:05: arrived 08:10 (+5) and 08:00 (-5): average 0.
        $this->actingAs($this->admin)->get('/report')
            ->assertInertia(fn (Assert $p) => $p
                ->where('linee_riepilogo.1.puntualita.arrivi', 2)
                ->where('linee_riepilogo.1.puntualita.scarto_medio', $this->uguale(0.0)));

        // Only the late one: yesterday 08:10.
        $ieri = today()->subDay()->toDateString();
        $this->actingAs($this->admin)->get("/report?da={$ieri}&a={$ieri}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('linee_riepilogo.1.puntualita.arrivi', 1)
                ->where('linee_riepilogo.1.puntualita.scarto_medio', $this->uguale(5.0)));
    }

    public function test_il_giorno_per_giorno_di_tutte_le_linee_e_senza_arrivi(): void
    {
        $this->actingAs($this->admin)->get('/report')
            ->assertInertia(fn (Assert $p) => $p
                ->has('giorni', 2)
                ->where('giorni.0.data', today()->subDays(2)->toDateString())   // oldest first
                ->where('giorni.0.presenti', 3)
                ->where('giorni.0.assenti', 1)
                ->where('giorni.0.arrivo', null)
                ->where('giorni.1.data', today()->subDay()->toDateString())
                ->where('giorni.1.presenti', 2)
                ->where('giorni.1.assenti', 1)
                ->where('giorni.1.arrivo', null));
    }

    public function test_scegliendo_una_linea_si_vedono_i_suoi_giorni_e_gli_arrivi(): void
    {
        $this->actingAs($this->admin)->get("/report?linea={$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('linea_scelta', $this->verde->id)
                ->has('giorni', 2)
                ->where('giorni.0.presenti', 3)
                ->where('giorni.0.assenti', 0)
                ->where('giorni.0.arrivo', '08:00')
                ->where('giorni.0.scarto_minuti', -5)
                ->where('giorni.1.presenti', 1)
                ->where('giorni.1.assenti', 1)
                ->where('giorni.1.arrivo', '08:10')
                ->where('giorni.1.scarto_minuti', 5));
    }

    public function test_un_giorno_con_solo_l_arrivo_compare_senza_presenze(): void
    {
        $this->arrivo($this->giorno(5), 8, 5);

        $this->actingAs($this->admin)->get("/report?linea={$this->verde->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->has('giorni', 3)
                ->where('giorni.0.data', today()->subDays(5)->toDateString())
                ->where('giorni.0.presenti', 0)
                ->where('giorni.0.arrivo', '08:05')
                ->where('giorni.0.scarto_minuti', 0));
    }

    public function test_si_vedono_solo_le_linee_che_si_possono_vedere(): void
    {
        $this->actingAs($this->resp1)->get('/report')
            ->assertInertia(fn (Assert $p) => $p
                ->has('linee', 1)
                ->where('linee.0.nome', 'Verde')
                ->has('linee_riepilogo', 1)
                ->where('totali.presenti', 4)
                ->where('totali.assenti', 1));

        $this->actingAs($this->resp1)->get("/report?linea={$this->blu->id}")->assertSessionHasErrors('linea');
        $this->actingAs($this->resp1)->get('/report?linea=99999')->assertSessionHasErrors('linea');
    }

    public function test_una_linea_archiviata_non_compare(): void
    {
        $this->blu->update(['archiviata_il' => now()]);

        $this->actingAs($this->admin)->get('/report')
            ->assertInertia(fn (Assert $p) => $p->has('linee_riepilogo', 1)->where('linee_riepilogo.0.nome', 'Verde'));
    }

    public function test_le_linee_di_un_altra_citta_non_compaiono(): void
    {
        $estranea = Linea::factory()->create(['citta_id' => $this->altra->id, 'nome' => 'Rossa']);
        $fermata = Fermata::factory()->create(['linea_id' => $estranea->id, 'citta_id' => $this->altra->id, 'ordine' => 1, 'orario' => '07:40:00']);
        $bambino = $this->bambino('Paolo', 'Estraneo', $this->altra);
        Presenza::registra($fermata, $bambino, today()->subDay(), true, false, null);

        $this->actingAs($this->admin)->get('/report')
            ->assertInertia(fn (Assert $p) => $p->has('linee_riepilogo', 2)->where('totali.presenti', 5));
    }

    public function test_l_amministratore_globale_sceglie_la_citta(): void
    {
        $this->actingAs($this->globale)->get('/report')
            ->assertInertia(fn (Assert $p) => $p
                ->has('citte', 2)
                ->where('citta_scelta', $this->citta->id)   // the first by name: Alfa
                ->has('linee_riepilogo', 2));

        $lineaZeta = Linea::factory()->create(['citta_id' => $this->altra->id, 'nome' => 'Rossa']);

        $this->actingAs($this->globale)->get("/report?citta={$this->altra->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('citta_scelta', $this->altra->id)       // a number, not the text of the address
                ->has('linee_riepilogo', 1)
                ->where('linee_riepilogo.0.nome', $lineaZeta->nome));

        $this->actingAs($this->globale)->get('/report?citta=99999')->assertSessionHasErrors('citta');
    }

    // ---------------------------------------------------------- by child

    public function test_il_report_per_bambino(): void
    {
        $this->actingAs($this->admin)->get('/report/bambini')
            ->assertInertia(fn (Assert $p) => $p
                ->component('report/Bambini')
                ->has('bambini', 5)
                // sorted by surname, or by first name when there is none
                ->where('bambini.0.nome', 'Anna Bianchi')
                ->where('bambini.0.giorni', 2)
                ->where('bambini.0.presenti', 1)
                ->where('bambini.0.assenti', 1)
                ->where('bambini.0.frequenza', $this->uguale(50.0))
                ->where('bambini.1.nome', 'Paolo Neri')       // assigned, never marked
                ->where('bambini.1.giorni', 0)
                ->where('bambini.1.frequenza', null)
                ->where('bambini.2.nome', 'Luca Rossi')
                ->where('bambini.2.giorni', 2)
                ->where('bambini.2.presenti', 2)
                ->where('bambini.2.frequenza', $this->uguale(100.0))
                ->where('bambini.3.nome', 'Marco Verdi')
                ->where('bambini.3.presenti', 1)
                ->where('bambini.3.assenti', 1)
                ->where('bambini.4.nome', 'Zoe')
                ->where('bambini.4.temporanei', 1));
    }

    public function test_un_periodo_piu_lungo_include_altri_giorni(): void
    {
        $da = today()->subDays(45)->toDateString();

        $this->actingAs($this->admin)->get("/report/bambini?da={$da}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('bambini.2.nome', 'Luca Rossi')
                ->where('bambini.2.giorni', 3)
                ->where('bambini.2.presenti', 2)
                ->where('bambini.2.assenti', 1)
                ->where('bambini.2.frequenza', $this->uguale(66.7)));
    }

    public function test_il_report_per_bambino_segue_la_linea_scelta_e_i_permessi(): void
    {
        $this->actingAs($this->admin)->get("/report/bambini?linea={$this->blu->id}")
            ->assertInertia(fn (Assert $p) => $p->has('bambini', 1)->where('bambini.0.nome', 'Marco Verdi'));

        // resp1 manages Verde only: Marco (Blu) is not among his children.
        $this->actingAs($this->resp1)->get('/report/bambini')
            ->assertInertia(fn (Assert $p) => $p->has('bambini', 4)->where('bambini.3.nome', 'Zoe'));
    }

    public function test_il_dettaglio_di_un_bambino_giorno_per_giorno(): void
    {
        $this->actingAs($this->admin)->get("/report/bambini/{$this->luca->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('report/Bambino')
                ->where('bambino.nome', 'Luca Rossi')
                ->where('totali.giorni', 2)
                ->where('totali.presenti', 2)
                ->where('totali.assenti', 0)
                ->where('totali.frequenza', $this->uguale(100.0))
                ->has('registri', 2)
                ->where('registri.0.data', today()->subDay()->toDateString())      // newest first
                ->where('registri.0.linea', 'Verde')
                ->where('registri.0.fermata', 'Parco')
                ->where('registri.0.stato', true)
                ->where('registri.0.registrata_da', 'Aldo Verdi')
                ->where('registri.0.registrata_alle', '07:50')
                ->where('registri.1.data', today()->subDays(2)->toDateString()));
    }

    public function test_il_dettaglio_mostra_i_giorni_aggiunti_per_un_giorno_solo(): void
    {
        $this->actingAs($this->admin)->get("/report/bambini/{$this->zoe->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('totali.temporanei', 1)
                ->where('registri.0.temporaneo', true)
                ->where('registri.0.fermata', 'Via Roma'));
    }

    public function test_un_responsabile_vede_solo_i_bambini_delle_sue_linee(): void
    {
        // Luca is on Verde: resp1 can open him; resp2 (Blu) cannot.
        $this->actingAs($this->resp1)->get("/report/bambini/{$this->luca->id}")->assertOk();
        $this->actingAs($this->resp2)->get("/report/bambini/{$this->luca->id}")->assertNotFound();

        // Paolo is assigned to Parco without any mark: still resp1's child.
        $this->actingAs($this->resp1)->get("/report/bambini/{$this->paolo->id}")->assertOk();
    }

    public function test_un_responsabile_vede_di_un_bambino_solo_le_registrazioni_delle_sue_linee(): void
    {
        // Marco rides Blu; give him one mark on Verde too (a day he joined it).
        $this->segna($this->parco, $this->marco, $this->giorno(3), true, true);

        $this->actingAs($this->resp1)->get("/report/bambini/{$this->marco->id}")
            ->assertInertia(fn (Assert $p) => $p->has('registri', 1)->where('registri.0.linea', 'Verde'));

        $this->actingAs($this->admin)->get("/report/bambini/{$this->marco->id}")
            ->assertInertia(fn (Assert $p) => $p->has('registri', 3));
    }

    public function test_l_amministratore_vede_ogni_bambino_della_sua_citta_anche_se_non_assegnato(): void
    {
        $libero = $this->bambino('Libero', 'Solo');

        $this->actingAs($this->admin)->get("/report/bambini/{$libero->id}")->assertOk();
        $this->actingAs($this->resp1)->get("/report/bambini/{$libero->id}")->assertNotFound();
    }

    public function test_i_bambini_di_un_altra_citta_non_si_aprono(): void
    {
        $estraneo = $this->bambino('Paolo', 'Estraneo', $this->altra);

        $this->actingAs($this->admin)->get("/report/bambini/{$estraneo->id}")->assertNotFound();
    }

    public function test_l_amministratore_globale_apre_i_bambini_della_citta_scelta(): void
    {
        $estraneo = $this->bambino('Paolo', 'Estraneo', $this->altra);

        $this->actingAs($this->globale)->get("/report/bambini/{$this->luca->id}")->assertOk();
        $this->actingAs($this->globale)->get("/report/bambini/{$estraneo->id}")->assertNotFound();
        $this->actingAs($this->globale)->get("/report/bambini/{$estraneo->id}?citta={$this->altra->id}")->assertOk();
    }

    // --------------------------------------------------------------- CSV

    private function csv(string $indirizzo, ?User $utente = null): array
    {
        $risposta = $this->actingAs($utente ?? $this->admin)->get($indirizzo)->assertOk();
        $testo = $risposta->streamedContent();

        return [$risposta, $testo, array_values(array_filter(preg_split('/\r?\n/', ltrim($testo, "\xEF\xBB\xBF")))) ];
    }

    public function test_l_esportazione_e_un_csv_per_excel(): void
    {
        [$risposta, $testo, $righe] = $this->csv('/report/esporta');

        $this->assertStringStartsWith("\xEF\xBB\xBF", $testo, 'byte order mark, so Excel reads the accents');
        $this->assertStringContainsString('text/csv', $risposta->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'presenze-'.today()->subDays(29)->toDateString().'_'.today()->toDateString().'.csv',
            $risposta->headers->get('Content-Disposition'),
        );
        $this->assertSame('Data;Linea;Fermata;Bambino;Stato;"Solo quel giorno";"Segnato da";"Segnato alle"', $righe[0]);
        $this->assertCount(1 + 7, $righe, 'header + the 7 marks of the last 30 days');
    }

    public function test_le_righe_del_csv_hanno_i_dati_giusti_in_ordine_di_data(): void
    {
        [, , $righe] = $this->csv("/report/esporta?linea={$this->verde->id}");

        $primo = str_getcsv($righe[1], ';');
        $this->assertSame([today()->subDays(2)->format('d/m/Y'), 'Verde', 'Parco'], array_slice($primo, 0, 3));

        $zoe = collect($righe)->first(fn ($riga) => str_contains($riga, 'Zoe'));
        $this->assertSame(
            [today()->subDays(2)->format('d/m/Y'), 'Verde', 'Via Roma', 'Zoe', 'Presente', 'sì', 'Aldo Verdi', '07:50'],
            str_getcsv($zoe, ';'),
        );

        $anna = collect($righe)->first(fn ($riga) => str_contains($riga, 'Anna Bianchi') && str_contains($riga, 'Assente'));
        $this->assertNotNull($anna);
        $this->assertSame('no', str_getcsv($anna, ';')[5]);
    }

    public function test_il_csv_rispetta_il_periodo_e_le_linee_visibili(): void
    {
        [, , $righe] = $this->csv('/report/esporta', $this->resp2);
        $this->assertCount(1 + 2, $righe, 'resp2 sees only Blu: Marco present and absent');
        $this->assertStringNotContainsString('Verde', implode("\n", $righe));

        $da = today()->subDays(45)->toDateString();
        [, , $lunghe] = $this->csv("/report/esporta?da={$da}");
        $this->assertCount(1 + 8, $lunghe, 'the mark of forty days ago is included');
    }

    public function test_il_csv_non_esegue_formule(): void
    {
        $maligno = $this->bambino('=SUM(1+1)', '');
        $this->parco->assegnaBambino($maligno);
        $this->segna($this->parco, $maligno, $this->giorno(1), true);

        [, $testo] = $this->csv('/report/esporta');

        $this->assertStringContainsString("'=SUM(1+1)", $testo);
        $this->assertStringNotContainsString(';=SUM(1+1);', $testo);
    }

    public function test_il_csv_e_riservato_a_chi_puo_vedere_i_report(): void
    {
        $this->actingAs($this->acc)->get('/report/esporta')->assertForbidden();
    }
}
