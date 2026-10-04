<?php

namespace Tests\Feature;

use App\Models\Bambino;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rules enforced by the database itself, independently of application code.
 */
class VincoliDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Linea $linea;

    private Fermata $fermata;

    private Bambino $bambino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->citta = Citta::factory()->create();
        $this->linea = Linea::factory()->create(['citta_id' => $this->citta->id]);
        $this->fermata = Fermata::factory()->create(['linea_id' => $this->linea->id, 'citta_id' => $this->citta->id]);
        $this->bambino = Bambino::factory()->create(['citta_id' => $this->citta->id]);
    }

    public function test_registrare_due_volte_la_stessa_presenza_non_crea_duplicati(): void
    {
        $oggi = now();

        Presenza::registra($this->fermata, $this->bambino, $oggi, presente: true);
        $presenza = Presenza::registra($this->fermata, $this->bambino, $oggi, presente: false);

        $this->assertSame(1, Presenza::count());
        $this->assertFalse($presenza->presente);
    }

    public function test_un_bambino_puo_comparire_su_due_linee_nello_stesso_giorno(): void
    {
        $ritorno = Linea::factory()->create(['citta_id' => $this->citta->id]);
        $fermataRitorno = Fermata::factory()->create(['linea_id' => $ritorno->id, 'citta_id' => $this->citta->id]);

        Presenza::registra($this->fermata, $this->bambino, now(), presente: true);
        Presenza::registra($fermataRitorno, $this->bambino, now(), presente: true);

        $this->assertSame(2, Presenza::count());
    }

    public function test_il_database_rifiuta_un_duplicato_per_data_linea_e_bambino(): void
    {
        $riga = [
            'citta_id' => $this->citta->id,
            'data' => now()->toDateString(),
            'linea_id' => $this->linea->id,
            'fermata_id' => $this->fermata->id,
            'bambino_id' => $this->bambino->id,
            'presente' => true,
        ];
        Presenza::create($riga);

        $this->expectException(QueryException::class);

        Presenza::create($riga);
    }

    public function test_una_fermata_non_puo_appartenere_a_una_linea_di_un_altra_citta(): void
    {
        $altra = Citta::factory()->create();

        $this->expectException(QueryException::class);

        // The line belongs to $this->citta, the stop claims another city.
        Fermata::factory()->create(['linea_id' => $this->linea->id, 'citta_id' => $altra->id]);
    }

    public function test_un_bambino_di_un_altra_citta_non_si_assegna_a_una_fermata(): void
    {
        $estraneo = Bambino::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('fermata_bambino')->insert([
            'citta_id' => $this->citta->id,
            'fermata_id' => $this->fermata->id,
            'bambino_id' => $estraneo->id,
        ]);
    }

    public function test_una_presenza_non_puo_usare_una_fermata_di_un_altra_linea(): void
    {
        $altraLinea = Linea::factory()->create(['citta_id' => $this->citta->id]);

        $this->expectException(QueryException::class);

        Presenza::create([
            'citta_id' => $this->citta->id,
            'data' => now()->toDateString(),
            'linea_id' => $altraLinea->id,
            'fermata_id' => $this->fermata->id,
            'bambino_id' => $this->bambino->id,
            'presente' => true,
        ]);
    }
}
