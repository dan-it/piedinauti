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
 * The attendance report shows who marked each child and at what time.
 */
class PresenzeOrarioTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private User $admin;

    private User $acc;

    private Fermata $parco;

    private Bambino $luca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(today()->setTime(9, 0));

        $this->citta = Citta::factory()->create();
        $this->admin = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create(['nome' => 'Carlo', 'cognome' => 'Bianchi']);
        $this->acc = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Accompagnatore)->create(['nome' => 'Aldo', 'cognome' => 'Verdi']);

        $linea = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']);
        $this->parco = Fermata::factory()->create(['linea_id' => $linea->id, 'citta_id' => $this->citta->id, 'ordine' => 1, 'orario' => '07:40:00', 'nome' => 'Parco']);
        $this->parco->assegnaAccompagnatore($this->acc);

        $this->luca = Bambino::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Luca', 'cognome' => 'Rossi']);
        $this->parco->assegnaBambino($this->luca);
    }

    public function test_il_report_mostra_chi_ha_segnato_e_a_che_ora(): void
    {
        Presenza::registra($this->parco, $this->luca, today(), presente: true, registrataDa: $this->acc, registrataIl: today()->setTime(7, 52));

        $this->actingAs($this->admin)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p
                ->where('linee.0.fermate.0.bambini.0.registrata_da', 'Aldo Verdi')
                ->where('linee.0.fermate.0.bambini.0.registrata_alle', '07:52'));
    }

    public function test_l_orario_e_quello_del_tocco_non_dell_invio(): void
    {
        // The phone tapped at 07:52 and sent it at 09:00 (the moment the test runs at).
        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->parco->id}/presenze", [
            'bambino_id' => $this->luca->id, 'presente' => true, 'registrata_il' => today()->setTime(7, 52)->toIso8601String(),
        ])->assertOk();

        $this->actingAs($this->admin)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p->where('linee.0.fermate.0.bambini.0.registrata_alle', '07:52'));
    }

    public function test_i_dati_senza_orario_del_tocco_mostrano_quando_sono_stati_salvati(): void
    {
        // Saved before the moment of the tap was recorded.
        Presenza::query()->create([
            'citta_id' => $this->citta->id, 'data' => today()->toDateString(), 'linea_id' => $this->parco->linea_id,
            'fermata_id' => $this->parco->id, 'bambino_id' => $this->luca->id, 'presente' => true,
            'registrata_da' => $this->acc->id, 'registrata_il' => null,
        ]);

        $this->actingAs($this->admin)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p->where('linee.0.fermate.0.bambini.0.registrata_alle', '09:00'));
    }

    public function test_un_bambino_non_segnato_non_ha_ne_chi_ne_quando(): void
    {
        $this->actingAs($this->admin)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p
                ->where('linee.0.fermate.0.bambini.0.stato', null)
                ->where('linee.0.fermate.0.bambini.0.registrata_da', null)
                ->where('linee.0.fermate.0.bambini.0.registrata_alle', null));
    }

    public function test_la_correzione_dell_amministratore_risponde_con_chi_e_quando(): void
    {
        $this->actingAs($this->admin)->postJson('/presenze/correggi', [
            'data' => today()->toDateString(), 'fermata_id' => $this->parco->id, 'bambino_id' => $this->luca->id, 'presente' => false,
        ])->assertOk()->assertJson(['stato' => false, 'registrata_da' => 'Carlo Bianchi', 'registrata_alle' => '09:00']);

        $this->actingAs($this->admin)->get('/presenze')
            ->assertInertia(fn (Assert $p) => $p
                ->where('linee.0.fermate.0.bambini.0.registrata_da', 'Carlo Bianchi')
                ->where('linee.0.fermate.0.bambini.0.registrata_alle', '09:00'));

        $this->actingAs($this->admin)->postJson('/presenze/correggi', [
            'data' => today()->toDateString(), 'fermata_id' => $this->parco->id, 'bambino_id' => $this->luca->id, 'presente' => null,
        ])->assertOk()->assertJson(['stato' => null, 'registrata_da' => null, 'registrata_alle' => null]);
    }

    public function test_il_report_di_un_giorno_passato_mostra_l_orario_di_quel_giorno(): void
    {
        $ieri = today()->subDay();
        Presenza::registra($this->parco, $this->luca, $ieri, presente: true, registrataDa: $this->acc, registrataIl: $ieri->setTime(7, 48));

        $this->actingAs($this->admin)->get('/presenze?data='.$ieri->toDateString())
            ->assertInertia(fn (Assert $p) => $p->where('linee.0.fermate.0.bambini.0.registrata_alle', '07:48'));
    }
}
