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
use Tests\TestCase;

/**
 * Thirty minutes after the line's arrival chaperones can no longer record or change attendance.
 * Administrators can always correct it.
 */
class CorrezionePresenzeTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Fermata $fermata;

    private Bambino $bambino;

    private User $accompagnatore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->citta = Citta::factory()->create();
        $linea = Linea::factory()->create(['citta_id' => $this->citta->id]);
        // Single stop: expected arrival 07:50, so the window closes at 08:20.
        $this->fermata = Fermata::factory()->create([
            'linea_id' => $linea->id, 'citta_id' => $this->citta->id, 'ordine' => 1, 'orario' => '07:50:00',
        ]);
        $this->accompagnatore = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Accompagnatore)->create();
        $this->fermata->assegnaAccompagnatore($this->accompagnatore);
        $this->bambino = Bambino::factory()->create(['citta_id' => $this->citta->id]);

        $this->travelTo(today()->setTime(7, 30));
        Presenza::registra($this->fermata, $this->bambino, today(), presente: true, registrataDa: $this->accompagnatore);
    }

    public function test_fino_alla_scadenza_l_accompagnatore_modifica(): void
    {
        $this->travelTo(today()->setTime(8, 20));

        $this->assertTrue($this->accompagnatore->can('registrare', [Presenza::class, $this->fermata, $this->bambino]));
    }

    public function test_dopo_la_scadenza_l_accompagnatore_non_modifica_piu(): void
    {
        $this->travelTo(today()->setTime(8, 21));

        $this->assertFalse($this->accompagnatore->can('registrare', [Presenza::class, $this->fermata, $this->bambino]));
    }

    public function test_gli_amministratori_correggono_a_qualsiasi_ora(): void
    {
        $adminCitta = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();
        $adminGlobale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();

        foreach ([[7, 30], [8, 21], [15, 0], [23, 59]] as [$ore, $minuti]) {
            $this->travelTo(today()->setTime($ore, $minuti));

            foreach ([$adminCitta, $adminGlobale] as $admin) {
                $this->assertTrue($admin->can('correggere', [Presenza::class, $this->fermata]), "{$ore}:{$minuti}");
            }
        }
    }

    public function test_un_amministratore_di_citta_corregge_solo_nella_propria_citta(): void
    {
        $adminAltra = User::factory()->perCitta(Citta::factory()->create())->conRuolo(Ruolo::AdminCitta)->create();

        $this->assertFalse($adminAltra->can('correggere', [Presenza::class, $this->fermata]));
    }

    public function test_responsabili_e_accompagnatori_non_hanno_questa_possibilita(): void
    {
        $responsabile = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Responsabile)->create();
        $this->fermata->linea->assegnaResponsabile($responsabile);

        $this->assertFalse($responsabile->can('correggere', [Presenza::class, $this->fermata]));
        $this->assertFalse($this->accompagnatore->can('correggere', [Presenza::class, $this->fermata]));
    }
}
