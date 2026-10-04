<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A chaperone is assigned to the stop where they start and is present at that stop and every
 * later one. The assignment is stored once, on the starting stop.
 */
class CoperturaAccompagnatoriTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Linea $linea1;

    private Linea $linea2;

    /** @var array<string, Fermata> */
    private array $f = [];

    private User $acc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->citta = Citta::factory()->create();
        $this->linea1 = Linea::factory()->create(['citta_id' => $this->citta->id]);
        $this->linea2 = Linea::factory()->create(['citta_id' => $this->citta->id]);

        foreach ([['a', $this->linea1, 1], ['b', $this->linea1, 2], ['c', $this->linea1, 3], ['d', $this->linea1, 4], ['x', $this->linea2, 1], ['y', $this->linea2, 2]] as [$nome, $linea, $ordine]) {
            $this->f[$nome] = Fermata::factory()->create([
                'linea_id' => $linea->id, 'citta_id' => $this->citta->id, 'ordine' => $ordine, 'orario' => sprintf('07:%02d:00', 40 + $ordine), 'nome' => $nome,
            ]);
        }

        $this->acc = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Accompagnatore)->create();
    }

    /** @return list<string> names of the stops where the chaperone is present */
    private function coperte(): array
    {
        return $this->acc->fermateCoperte()->orderBy('linea_id')->orderBy('ordine')->pluck('nome')->all();
    }

    public function test_dalla_fermata_di_inizio_si_e_presenti_su_tutte_le_successive(): void
    {
        $this->f['b']->assegnaAccompagnatore($this->acc);

        $this->assertSame(['b', 'c', 'd'], $this->coperte());
    }

    public function test_le_fermate_prima_della_partenza_non_sono_coperte(): void
    {
        $this->f['c']->assegnaAccompagnatore($this->acc);

        $this->assertFalse($this->acc->eAccompagnatoreDi($this->f['a']));
        $this->assertFalse($this->acc->eAccompagnatoreDi($this->f['b']));
        $this->assertTrue($this->acc->eAccompagnatoreDi($this->f['c']));
        $this->assertTrue($this->acc->eAccompagnatoreDi($this->f['d']));
    }

    public function test_la_copertura_non_passa_da_una_linea_all_altra(): void
    {
        $this->f['b']->assegnaAccompagnatore($this->acc);

        $this->assertFalse($this->acc->eAccompagnatoreDi($this->f['x']));
        $this->assertFalse($this->acc->eAccompagnatoreDi($this->f['y']));

        $this->f['y']->assegnaAccompagnatore($this->acc);
        $this->assertSame(['b', 'c', 'd', 'y'], $this->coperte());
    }

    public function test_le_due_funzioni_dicono_la_stessa_cosa_per_ogni_fermata(): void
    {
        $this->f['b']->assegnaAccompagnatore($this->acc);
        $this->f['y']->assegnaAccompagnatore($this->acc);

        $coperte = $this->acc->fermateCoperte()->pluck('nome')->all();

        foreach ($this->f as $nome => $fermata) {
            $this->assertSame(in_array($nome, $coperte, true), $this->acc->eAccompagnatoreDi($fermata), "stop {$nome}");
        }
    }

    public function test_chi_non_e_accompagnatore_non_copre_nulla(): void
    {
        $responsabile = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Responsabile)->create();
        $this->f['a']->accompagnatori()->attach($responsabile->id, ['citta_id' => $this->citta->id]);

        $this->assertFalse($responsabile->eAccompagnatoreDi($this->f['a']));
    }

    public function test_se_gli_orari_cambiano_l_ordine_cambia_anche_la_copertura(): void
    {
        $this->f['b']->assegnaAccompagnatore($this->acc);
        $this->assertTrue($this->acc->eAccompagnatoreDi($this->f['c']));

        // Stop "c" is moved before "b" in time: it now comes before the starting stop.
        $this->f['c']->update(['orario' => '07:00:00']);
        $this->linea1->riordinaFermate();

        $this->assertFalse($this->acc->eAccompagnatoreDi($this->f['c']->fresh()));
    }

    // ---------------------------------------------------------- data cleanup

    public function test_la_migrazione_lascia_una_sola_fermata_di_inizio_per_linea(): void
    {
        // Older data: the same person on several stops of one line, and on another line.
        foreach (['a', 'b', 'd', 'x'] as $nome) {
            DB::table('fermata_accompagnatore')->insert([
                'citta_id' => $this->citta->id, 'fermata_id' => $this->f[$nome]->id, 'user_id' => $this->acc->id,
            ]);
        }
        $altra = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Accompagnatore)->create();
        DB::table('fermata_accompagnatore')->insert(['citta_id' => $this->citta->id, 'fermata_id' => $this->f['c']->id, 'user_id' => $altra->id]);

        $migrazione = require database_path('migrations/2026_10_08_100000_keep_one_start_stop_per_chaperone_and_line.php');
        $migrazione->up();

        $this->assertEqualsCanonicalizing(['a', 'x'], $this->acc->fermateAccompagnatore()->pluck('nome')->all(), 'earliest stop of each line');
        $this->assertSame(['c'], $altra->fermateAccompagnatore()->pluck('nome')->all(), 'a single assignment is left alone');

        // Running it again changes nothing.
        $migrazione->up();
        $this->assertSame(2, $this->acc->fermateAccompagnatore()->count());
    }
}
