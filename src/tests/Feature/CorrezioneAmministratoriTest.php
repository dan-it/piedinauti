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
 * Administrators correct attendance from the dashboard: any day, any time. Fixture: line "Verde"
 * (Parco 07:40, Via Roma 07:50, Scuola 08:05), and it is 15:00, long after the morning window.
 */
class CorrezioneAmministratoriTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(today()->setTime(15, 0));

        $this->citta = Citta::factory()->create(['nome' => 'Alfa']);
        $this->altra = Citta::factory()->create(['nome' => 'Zeta']);
        $this->admin = $this->persona(Ruolo::AdminCitta);
        $this->globale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();
        $this->resp = $this->persona(Ruolo::Responsabile);
        $this->acc = $this->persona(Ruolo::Accompagnatore);

        $this->verde = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']);
        $this->parco = $this->fermata($this->verde, 1, '07:40:00', 'Parco');
        $this->viaRoma = $this->fermata($this->verde, 2, '07:50:00', 'Via Roma');
        $this->fermata($this->verde, 3, '08:05:00', 'Scuola');
        $this->verde->assegnaResponsabile($this->resp);
        $this->parco->assegnaAccompagnatore($this->acc);
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

    private function bambino(string $nome, ?Citta $citta = null): Bambino
    {
        return Bambino::factory()->create(['citta_id' => ($citta ?? $this->citta)->id, 'nome' => $nome, 'cognome' => 'Rossi']);
    }

    private function correggi(Fermata $fermata, Bambino $bambino, ?bool $presente, ?string $data = null, ?User $come = null)
    {
        return $this->actingAs($come ?? $this->admin)->postJson('/presenze/correggi', [
            'data' => $data ?? today()->toDateString(),
            'fermata_id' => $fermata->id,
            'bambino_id' => $bambino->id,
            'presente' => $presente,
        ]);
    }

    // ------------------------------------------------------- the dashboard

    public function test_gli_amministratori_possono_modificare_i_responsabili_no(): void
    {
        $this->actingAs($this->admin)->get('/presenze')->assertInertia(fn (Assert $p) => $p->where('puo_modificare', true));
        $this->actingAs($this->globale)->get('/presenze')->assertInertia(fn (Assert $p) => $p->where('puo_modificare', true));
        $this->actingAs($this->resp)->get('/presenze')->assertInertia(fn (Assert $p) => $p->where('puo_modificare', false));
    }

    public function test_l_amministratore_globale_sceglie_la_citta(): void
    {
        $lineaZeta = Linea::factory()->create(['citta_id' => $this->altra->id, 'nome' => 'Rossa']);
        $archiviata = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Vecchia']);
        $archiviata->update(['archiviata_il' => now()]);

        // Default: the first city by name (Alfa), without its archived lines.
        $this->actingAs($this->globale)->get('/presenze')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('citte', 2)
                ->where('citte.0.nome', 'Alfa')
                ->where('citta_scelta', $this->citta->id)
                ->has('linee', 1)
                ->where('linee.0.nome', 'Verde'));

        // The chosen city comes back as a number even though the browser sent it as text.
        $this->actingAs($this->globale)->get("/presenze?citta={$this->altra->id}")
            ->assertInertia(fn (Assert $p) => $p->where('citta_scelta', $this->altra->id)->has('linee', 1)->where('linee.0.nome', $lineaZeta->nome));
    }

    public function test_una_citta_inesistente_e_rifiutata(): void
    {
        $this->actingAs($this->globale)->get('/presenze?citta=99999')->assertSessionHasErrors('citta');
    }

    public function test_gli_altri_non_vedono_la_scelta_della_citta(): void
    {
        $this->actingAs($this->admin)->get('/presenze')->assertInertia(fn (Assert $p) => $p->where('citte', [])->where('citta_scelta', null));
    }

    // ------------------------------------------------------ the correction

    public function test_l_amministratore_registra_dopo_la_chiusura_anche_la_prima_volta(): void
    {
        $luca = $this->bambino('Luca');
        $this->parco->assegnaBambino($luca);

        $this->correggi($this->parco, $luca, true)
            ->assertOk()
            ->assertJson(['bambino_id' => $luca->id, 'stato' => true, 'temporaneo' => false]);

        $presenza = Presenza::query()->firstOrFail();
        $this->assertTrue($presenza->presente);
        $this->assertSame($this->admin->id, $presenza->registrata_da);
        $this->assertSame($this->citta->id, $presenza->citta_id);
    }

    public function test_cambia_lo_stato_e_poi_lo_azzera(): void
    {
        $luca = $this->bambino('Luca');
        $this->parco->assegnaBambino($luca);
        Presenza::registra($this->parco, $luca, today(), presente: true, registrataDa: $this->acc);

        $this->correggi($this->parco, $luca, false)->assertOk()->assertJson(['stato' => false]);
        $this->assertFalse(Presenza::query()->firstOrFail()->presente);
        $this->assertSame(1, Presenza::count());

        $this->correggi($this->parco, $luca, null)->assertOk()->assertJson(['stato' => null]);
        $this->assertSame(0, Presenza::count());

        // Clearing something that was never marked is harmless.
        $this->correggi($this->parco, $luca, null)->assertOk();
    }

    public function test_si_corregge_un_giorno_passato(): void
    {
        $luca = $this->bambino('Luca');
        $this->parco->assegnaBambino($luca);
        $ieri = today()->subDays(3)->toDateString();

        $this->correggi($this->parco, $luca, true, $ieri)->assertOk();

        $this->assertSame($ieri, Presenza::query()->firstOrFail()->data->toDateString());
    }

    public function test_non_si_correggono_i_giorni_futuri_o_date_non_valide(): void
    {
        $luca = $this->bambino('Luca');

        $this->correggi($this->parco, $luca, true, today()->addDay()->toDateString())->assertStatus(422);
        $this->correggi($this->parco, $luca, true, 'ieri')->assertStatus(422);

        $this->assertSame(0, Presenza::count());
    }

    public function test_il_dato_presente_e_obbligatorio_anche_se_puo_essere_nullo(): void
    {
        $luca = $this->bambino('Luca');

        $this->actingAs($this->admin)->postJson('/presenze/correggi', [
            'data' => today()->toDateString(), 'fermata_id' => $this->parco->id, 'bambino_id' => $luca->id,
        ])->assertStatus(422);

        $this->actingAs($this->admin)->postJson('/presenze/correggi', [
            'data' => today()->toDateString(), 'fermata_id' => $this->parco->id, 'bambino_id' => $luca->id, 'presente' => 'forse',
        ])->assertStatus(422);
    }

    public function test_un_bambino_non_assegnato_e_temporaneo_e_la_correzione_lo_mantiene(): void
    {
        $ospite = $this->bambino('Zoe');

        $this->correggi($this->parco, $ospite, true)->assertJson(['temporaneo' => true]);
        $this->correggi($this->parco, $ospite, false)->assertJson(['temporaneo' => true]); // still "added for the day"

        $assegnato = $this->bambino('Luca');
        $this->parco->assegnaBambino($assegnato);
        $this->correggi($this->parco, $assegnato, true)->assertJson(['temporaneo' => false]);
    }

    public function test_correggere_su_un_altra_fermata_della_linea_sposta_la_presenza(): void
    {
        $luca = $this->bambino('Luca');
        $this->parco->assegnaBambino($luca);
        Presenza::registra($this->parco, $luca, today(), presente: true);

        $this->correggi($this->viaRoma, $luca, true)->assertOk();

        $this->assertSame(1, Presenza::count());
        $this->assertSame($this->viaRoma->id, Presenza::query()->firstOrFail()->fermata_id);
    }

    public function test_un_bambino_di_un_altra_citta_si_rifiuta(): void
    {
        $estraneo = $this->bambino('Paolo', $this->altra);

        $this->correggi($this->parco, $estraneo, true)->assertStatus(422)->assertJson(['message' => 'Bambino non trovato.']);
        $this->correggi($this->parco, $estraneo, true, null, $this->globale)->assertStatus(422);

        $this->assertSame(0, Presenza::count());
    }

    // ------------------------------------------------------ who may do it

    public function test_responsabili_e_accompagnatori_non_correggono(): void
    {
        $luca = $this->bambino('Luca');

        $this->correggi($this->parco, $luca, true, null, $this->resp)->assertForbidden();
        $this->correggi($this->parco, $luca, true, null, $this->acc)->assertForbidden();

        $this->assertSame(0, Presenza::count());
    }

    public function test_gli_ospiti_non_correggono(): void
    {
        $luca = $this->bambino('Luca');

        $this->postJson('/presenze/correggi', ['data' => today()->toDateString(), 'fermata_id' => $this->parco->id, 'bambino_id' => $luca->id, 'presente' => true])
            ->assertUnauthorized();
    }

    public function test_l_amministratore_di_un_altra_citta_non_trova_la_fermata(): void
    {
        $adminAltra = $this->persona(Ruolo::AdminCitta, $this->altra);
        $luca = $this->bambino('Luca');

        $this->correggi($this->parco, $luca, true, null, $adminAltra)->assertNotFound();

        $this->assertSame(0, Presenza::count());
    }

    public function test_l_amministratore_globale_corregge_in_qualsiasi_citta(): void
    {
        $luca = $this->bambino('Luca');

        $this->correggi($this->parco, $luca, true, null, $this->globale)->assertOk();

        $presenza = Presenza::query()->firstOrFail();
        $this->assertSame($this->globale->id, $presenza->registrata_da);
        $this->assertSame($this->citta->id, $presenza->citta_id);
    }

    public function test_gli_accompagnatori_restano_bloccati_dopo_la_finestra_anche_se_un_amministratore_puo_correggere(): void
    {
        $luca = $this->bambino('Luca');
        $this->parco->assegnaBambino($luca);

        // 15:00: the window closed at 08:35. The chaperone cannot, the administrator can.
        $this->actingAs($this->acc)->postJson("/oggi/fermate/{$this->parco->id}/presenze", ['bambino_id' => $luca->id, 'presente' => true])->assertForbidden();
        $this->correggi($this->parco, $luca, true)->assertOk();
    }
}
