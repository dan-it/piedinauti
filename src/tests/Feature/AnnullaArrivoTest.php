<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Arrivo;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A chaperone can cancel the arrival they marked: same rule as every other change, so possible until
 * 30 minutes after the line's expected arrival, judged at the moment of the tap (also for a phone
 * that sends it later). Fixture: line "Verde" with Parco 07:40, Via Roma 07:50 and the destination
 * Scuola 08:05 (window closes at 08:35); the chaperone starts at Parco. The tests run at 12:00.
 */
class AnnullaArrivoTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private User $acc;

    private Linea $verde;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(today()->setTime(12, 0));

        $this->citta = Citta::factory()->create();
        $this->acc = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Accompagnatore)->create(['nome' => 'Aldo', 'cognome' => 'Verdi']);
        $this->verde = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']);

        $parco = $this->fermata(1, '07:40:00', 'Parco');
        $this->fermata(2, '07:50:00', 'Via Roma');
        $this->fermata(3, '08:05:00', 'Scuola', true);
        $parco->assegnaAccompagnatore($this->acc);
    }

    private function fermata(int $ordine, string $orario, string $nome, bool $destinazione = false): Fermata
    {
        return Fermata::factory()->create([
            'linea_id' => $this->verde->id, 'citta_id' => $this->citta->id, 'ordine' => $ordine, 'orario' => $orario, 'nome' => $nome, 'destinazione' => $destinazione,
        ]);
    }

    private function alle(int $ore, int $minuti): string
    {
        return today()->setTime($ore, $minuti)->toIso8601String();
    }

    private function segna(string $quando, ?User $come = null, ?Linea $linea = null)
    {
        return $this->actingAs($come ?? $this->acc)->postJson('/oggi/linee/'.($linea ?? $this->verde)->id.'/arrivo', ['registrata_il' => $quando]);
    }

    private function annulla(?string $quando, ?User $come = null, ?Linea $linea = null)
    {
        return $this->actingAs($come ?? $this->acc)->postJson('/oggi/linee/'.($linea ?? $this->verde)->id.'/arrivo/annulla', $quando === null ? [] : ['registrata_il' => $quando]);
    }

    public function test_si_annulla_l_arrivo_dentro_la_finestra(): void
    {
        $this->segna($this->alle(8, 10))->assertOk();
        $this->assertSame(1, Arrivo::count());

        $this->annulla($this->alle(8, 12))
            ->assertOk()
            ->assertJson(['ora' => null, 'da' => null, 'applicata' => true]);

        $this->assertSame(0, Arrivo::count());
    }

    public function test_dopo_l_annullamento_si_puo_segnare_di_nuovo_con_un_nuovo_orario(): void
    {
        $this->segna($this->alle(8, 10))->assertOk();
        $this->annulla($this->alle(8, 12))->assertOk();
        $this->segna($this->alle(8, 20))->assertOk()->assertJson(['ora' => '08:20', 'nuovo' => true]);

        $this->assertSame('08:20', Arrivo::query()->firstOrFail()->arrivata_alle->format('H:i'));
        $this->assertSame(1, Arrivo::count());
    }

    public function test_l_ultimo_minuto_della_finestra_e_valido_poi_no(): void
    {
        $this->segna($this->alle(8, 10))->assertOk();

        $this->annulla($this->alle(8, 36))
            ->assertForbidden()
            ->assertJsonPath('message', fn ($messaggio) => str_contains($messaggio, 'Il tempo per modificare l\'arrivo è scaduto'));
        $this->assertSame(1, Arrivo::count(), 'the arrival is still there');

        $this->annulla($this->alle(8, 35))->assertOk();
        $this->assertSame(0, Arrivo::count());
    }

    public function test_senza_orario_vale_il_momento_dell_invio(): void
    {
        $this->segna($this->alle(8, 10))->assertOk();

        // 12:00 and no moment of its own: the window is judged now, and is closed.
        $this->annulla(null)->assertForbidden();

        $this->assertSame(1, Arrivo::count());
    }

    public function test_un_annullamento_fatto_nella_finestra_e_inviato_dopo_viene_accettato(): void
    {
        $this->segna($this->alle(8, 10))->assertOk();

        // The phone had no signal: the tap was made at 08:20 and is only sent now, at 12:00.
        $this->annulla($this->alle(8, 20))->assertOk()->assertJson(['applicata' => true]);

        $this->assertSame(0, Arrivo::count());
    }

    public function test_un_annullamento_piu_vecchio_dell_arrivo_lo_lascia_com_e(): void
    {
        $this->segna($this->alle(8, 12))->assertOk();

        // A cancellation made at 08:05, before this arrival: it referred to an earlier arrival.
        $this->annulla($this->alle(8, 5))
            ->assertOk()
            ->assertJson(['ora' => '08:12', 'da' => 'Aldo Verdi', 'applicata' => false]);

        $this->assertSame(1, Arrivo::count());
    }

    public function test_annullare_senza_un_arrivo_registrato_non_fa_danni(): void
    {
        $this->annulla($this->alle(8, 10))->assertOk()->assertJson(['ora' => null, 'applicata' => true]);

        $this->assertSame(0, Arrivo::count());
    }

    public function test_l_annullamento_vale_per_il_giorno_del_tocco(): void
    {
        $this->segna($this->alle(8, 10))->assertOk();
        $ieri = today()->subDay();
        Arrivo::query()->create(['citta_id' => $this->citta->id, 'data' => $ieri->toDateString(), 'linea_id' => $this->verde->id, 'arrivata_alle' => $ieri->setTime(8, 10)]);

        $this->annulla($this->alle(8, 20))->assertOk();

        $this->assertSame(1, Arrivo::count());
        $this->assertSame($ieri->toDateString(), Arrivo::query()->firstOrFail()->data->toDateString(), "yesterday's arrival is untouched");
    }

    public function test_chi_non_e_con_il_gruppo_alla_destinazione_non_annulla(): void
    {
        $this->segna($this->alle(8, 10))->assertOk();
        $estraneo = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Accompagnatore)->create();
        $admin = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();

        $this->annulla($this->alle(8, 12), $estraneo)->assertForbidden();
        $this->annulla($this->alle(8, 12), $admin)->assertForbidden();

        $this->assertSame(1, Arrivo::count());
    }

    public function test_una_linea_senza_destinazione_non_ha_arrivo_da_annullare(): void
    {
        $blu = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);
        $piazza = Fermata::factory()->create(['linea_id' => $blu->id, 'citta_id' => $this->citta->id, 'ordine' => 1, 'orario' => '07:45:00', 'nome' => 'Piazza']);
        $piazza->assegnaAccompagnatore($this->acc);

        $this->annulla($this->alle(8, 10), null, $blu)->assertStatus(422)->assertJson(['message' => 'Questa linea non ha una destinazione.']);
    }

    public function test_su_una_linea_archiviata_non_si_annulla(): void
    {
        $this->segna($this->alle(8, 10))->assertOk();
        $this->verde->update(['archiviata_il' => now()]);

        $this->annulla($this->alle(8, 12))->assertNotFound();
    }

    public function test_un_orario_nel_futuro_o_non_valido_e_rifiutato(): void
    {
        $this->segna($this->alle(8, 10))->assertOk();

        $this->annulla($this->alle(12, 30))->assertStatus(422)->assertJsonValidationErrors('registrata_il');
        $this->annulla('ieri')->assertStatus(422)->assertJsonValidationErrors('registrata_il');

        $this->assertSame(1, Arrivo::count());
    }

    public function test_gli_ospiti_non_annullano(): void
    {
        $this->postJson("/oggi/linee/{$this->verde->id}/arrivo/annulla", [])->assertUnauthorized();
    }
}
