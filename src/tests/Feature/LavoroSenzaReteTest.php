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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Working without signal: a phone sends its taps later, together with the moment each one was made.
 * Fixture: line "Verde" with Parco 07:40, Via Roma 07:50 and the destination Scuola 08:05 (so the
 * modification window closes at 08:35); the chaperone starts at Parco. The tests run at 12:00 unless
 * they say otherwise: long after the window, as when a phone gets its signal back at midday.
 */
class LavoroSenzaReteTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private User $acc;

    private User $admin;

    private Linea $verde;

    private Fermata $parco;

    private Fermata $scuola;

    private Bambino $luca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(today()->setTime(12, 0));

        $this->citta = Citta::factory()->create();
        $this->acc = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Accompagnatore)->create(['nome' => 'Aldo', 'cognome' => 'Verdi']);
        $this->admin = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();

        $this->verde = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']);
        $this->parco = $this->fermata(1, '07:40:00', 'Parco');
        $this->fermata(2, '07:50:00', 'Via Roma');
        $this->scuola = $this->fermata(3, '08:05:00', 'Scuola', true);
        $this->parco->assegnaAccompagnatore($this->acc);

        $this->luca = Bambino::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Luca', 'cognome' => 'Rossi']);
        $this->parco->assegnaBambino($this->luca);
    }

    private function fermata(int $ordine, string $orario, string $nome, bool $destinazione = false): Fermata
    {
        return Fermata::factory()->create([
            'linea_id' => $this->verde->id, 'citta_id' => $this->citta->id, 'ordine' => $ordine, 'orario' => $orario, 'nome' => $nome, 'destinazione' => $destinazione,
        ]);
    }

    /** ISO 8601 string of a moment today, as a phone would send it. */
    private function alle(int $ore, int $minuti, int $secondi = 0): string
    {
        return today()->setTime($ore, $minuti, $secondi)->toIso8601String();
    }

    private function segna(bool $presente, ?string $registrataIl, ?Bambino $bambino = null, ?User $come = null)
    {
        return $this->actingAs($come ?? $this->acc)->postJson("/oggi/fermate/{$this->parco->id}/presenze", array_filter([
            'bambino_id' => ($bambino ?? $this->luca)->id,
            'presente' => $presente,
            'registrata_il' => $registrataIl,
        ], fn ($valore) => $valore !== null));
    }

    private function arrivo(?string $registrataIl)
    {
        return $this->actingAs($this->acc)->postJson("/oggi/linee/{$this->verde->id}/arrivo", $registrataIl === null ? [] : ['registrata_il' => $registrataIl]);
    }

    // ----------------------------------------- the window, judged at the moment of the tap

    public function test_un_tocco_fatto_nella_finestra_e_inviato_dopo_viene_accettato(): void
    {
        $this->segna(true, $this->alle(8, 0))->assertOk()->assertJson(['presente' => true, 'applicata' => true]);

        $presenza = Presenza::query()->firstOrFail();
        $this->assertTrue($presenza->presente);
        $this->assertSame('08:00:00', $presenza->registrata_il->format('H:i:s'));
        $this->assertSame(today()->toDateString(), $presenza->data->toDateString());
    }

    public function test_un_tocco_fatto_dopo_la_finestra_e_rifiutato_anche_se_inviato_subito(): void
    {
        // 08:35 is the last minute of the window (08:05 + 30).
        $this->segna(true, $this->alle(8, 35))->assertOk();
        $this->segna(false, $this->alle(8, 36), $this->luca)->assertForbidden();

        $this->assertTrue(Presenza::query()->firstOrFail()->presente);
    }

    public function test_senza_orario_vale_il_momento_dell_invio(): void
    {
        // It is 12:00 and the tap has no moment of its own: the window is judged now, and is closed.
        $this->segna(true, null)->assertForbidden();

        $this->assertSame(0, Presenza::count());
    }

    public function test_la_data_della_presenza_e_quella_del_tocco_non_dell_invio(): void
    {
        $this->travelTo(today()->setTime(7, 30));
        $ieri = CarbonImmutable::yesterday()->setTime(7, 45)->toIso8601String(); // 23 h 45 min ago

        $this->segna(true, $ieri)->assertOk();

        $this->assertSame(today()->subDay()->toDateString(), Presenza::query()->firstOrFail()->data->toDateString());
    }

    public function test_un_tocco_piu_vecchio_di_un_giorno_e_rifiutato(): void
    {
        $ieri = CarbonImmutable::yesterday()->setTime(7, 50)->toIso8601String(); // 28 h 10 min before 12:00

        $this->segna(true, $ieri)->assertStatus(422)->assertJsonValidationErrors('registrata_il');

        $this->assertSame(0, Presenza::count());
    }

    public function test_un_orario_nel_futuro_e_rifiutato_ma_un_piccolo_scarto_e_tollerato(): void
    {
        $this->segna(true, $this->alle(12, 10))->assertStatus(422)->assertJsonValidationErrors('registrata_il');

        $this->travelTo(today()->setTime(7, 30));
        $this->segna(true, $this->alle(7, 31))->assertOk();

        // The stored moment is never in the future.
        $this->assertSame('07:30:00', Presenza::query()->firstOrFail()->registrata_il->format('H:i:s'));
    }

    public function test_un_orario_non_valido_e_rifiutato(): void
    {
        $this->segna(true, 'ieri')->assertStatus(422)->assertJsonValidationErrors('registrata_il');
    }

    // ------------------------------------------------- order and repetition

    public function test_un_tocco_piu_vecchio_non_annulla_uno_piu_recente(): void
    {
        $this->segna(true, $this->alle(7, 55))->assertOk()->assertJson(['applicata' => true]);

        // Out of order: the older tap (07:50) reaches the server after the newer one.
        $this->segna(false, $this->alle(7, 50))->assertOk()->assertJson(['applicata' => false, 'presente' => true]);
        $this->assertTrue(Presenza::query()->firstOrFail()->presente);

        // A newer one does apply.
        $this->segna(false, $this->alle(7, 58))->assertOk()->assertJson(['applicata' => true, 'presente' => false]);
        $this->assertFalse(Presenza::query()->firstOrFail()->presente);
        $this->assertSame(1, Presenza::count());
    }

    public function test_inviare_due_volte_lo_stesso_tocco_non_crea_duplicati(): void
    {
        $momento = $this->alle(7, 55);

        $this->segna(true, $momento)->assertOk()->assertJson(['applicata' => true]);
        $this->segna(true, $momento)->assertOk()->assertJson(['applicata' => true]);

        $this->assertSame(1, Presenza::count());
    }

    public function test_la_correzione_di_un_amministratore_vince_su_un_tocco_piu_vecchio_inviato_tardi(): void
    {
        $oggi = today()->toDateString();
        $this->actingAs($this->admin)->postJson('/presenze/correggi', [
            'data' => $oggi, 'fermata_id' => $this->parco->id, 'bambino_id' => $this->luca->id, 'presente' => false,
        ])->assertOk();

        // The chaperone's phone had marked "present" at 08:00 and only now gets its signal back.
        $this->segna(true, $this->alle(8, 0))->assertOk()->assertJson(['applicata' => false, 'presente' => false]);

        $this->assertFalse(Presenza::query()->firstOrFail()->presente);
    }

    public function test_un_dato_senza_orario_registrato_prima_della_migrazione_si_aggiorna(): void
    {
        $this->travelTo(today()->setTime(7, 30));
        Presenza::query()->create([
            'citta_id' => $this->citta->id, 'data' => today()->toDateString(), 'linea_id' => $this->verde->id,
            'fermata_id' => $this->parco->id, 'bambino_id' => $this->luca->id, 'presente' => true, 'registrata_il' => null,
        ]);

        $this->segna(false, $this->alle(7, 20))->assertOk()->assertJson(['applicata' => true]);

        $this->assertFalse(Presenza::query()->firstOrFail()->presente);
    }

    public function test_il_modello_ignora_una_marcatura_piu_vecchia(): void
    {
        $nuova = Presenza::registra($this->parco, $this->luca, today(), true, false, $this->acc, today()->setTime(7, 55));
        $vecchia = Presenza::registra($this->parco, $this->luca, today(), false, false, $this->acc, today()->setTime(7, 50));

        $this->assertFalse($nuova->ignorata);
        $this->assertTrue($vecchia->ignorata);
        $this->assertTrue(Presenza::query()->firstOrFail()->presente);
    }

    // ----------------------------------------------------------------- arrival

    public function test_l_arrivo_usa_l_orario_del_tocco(): void
    {
        $this->arrivo($this->alle(8, 12, 30))->assertOk()->assertJson(['ora' => '08:12', 'da' => 'Aldo Verdi', 'nuovo' => true]);

        $this->assertSame('08:12:30', Arrivo::query()->firstOrFail()->arrivata_alle->format('H:i:s'));
    }

    public function test_l_arrivo_segnato_oltre_la_finestra_e_rifiutato_anche_se_inviato_subito(): void
    {
        $this->arrivo($this->alle(8, 36))->assertForbidden();
        $this->arrivo($this->alle(8, 35))->assertOk();
    }

    public function test_l_arrivo_piu_vecchio_inviato_dopo_sostituisce_quello_piu_recente(): void
    {
        $this->arrivo($this->alle(8, 12))->assertOk()->assertJson(['ora' => '08:12', 'nuovo' => true]);

        // A second phone had tapped earlier and sends it later: the earliest time is the real one.
        $this->arrivo($this->alle(8, 10))->assertOk()->assertJson(['ora' => '08:10', 'nuovo' => false]);
        $this->assertSame('08:10', Arrivo::query()->firstOrFail()->arrivata_alle->format('H:i'));

        // A later tap changes nothing.
        $this->arrivo($this->alle(8, 20))->assertOk()->assertJson(['ora' => '08:10']);
        $this->assertSame(1, Arrivo::count());
    }

    public function test_l_arrivo_senza_orario_vale_il_momento_dell_invio(): void
    {
        $this->arrivo(null)->assertForbidden(); // 12:00: the window is closed
    }

    // ------------------------------------------- list of children for the phone

    public function test_l_elenco_dei_bambini_per_il_telefono(): void
    {
        Bambino::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Anna', 'cognome' => 'Bianchi']);
        Bambino::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Zoe', 'cognome' => '']);
        Bambino::factory()->create(['citta_id' => Citta::factory()->create()->id, 'nome' => 'Estraneo', 'cognome' => 'Altrove']);

        $this->actingAs($this->acc)->getJson('/oggi/bambini')
            ->assertOk()
            ->assertJsonCount(3, 'bambini')                       // Luca, Anna, Zoe: the other city is not included
            ->assertJsonPath('bambini.0.nome', 'Anna Bianchi')    // sorted by surname, or by name when there is none
            ->assertJsonPath('bambini.1.nome', 'Luca Rossi')
            ->assertJsonPath('bambini.2.nome', 'Zoe');
    }

    public function test_l_elenco_dei_bambini_e_solo_per_gli_accompagnatori(): void
    {
        $this->actingAs($this->admin)->getJson('/oggi/bambini')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/oggi/bambini')->assertUnauthorized();
    }
}
