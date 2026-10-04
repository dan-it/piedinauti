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
 * Thirty minutes after the line's arrival nobody can record or change attendance:
 * not the chaperones, not the managers, not the administrators.
 */
class CorrezionePresenzeTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Fermata $fermata;

    private Bambino $bambino;

    private User $accompagnatore;

    private Presenza $presenza;

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
        $this->presenza = Presenza::registra($this->fermata, $this->bambino, today(), presente: true, registrataDa: $this->accompagnatore);
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

    public function test_gli_amministratori_non_hanno_nessuna_eccezione(): void
    {
        $adminCitta = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();
        $adminGlobale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();

        foreach ([[7, 30], [8, 21], [15, 0]] as [$ore, $minuti]) {
            $this->travelTo(today()->setTime($ore, $minuti));

            foreach ([$adminCitta, $adminGlobale] as $admin) {
                $this->assertFalse($admin->can('registrare', [Presenza::class, $this->fermata, $this->bambino]), "{$ore}:{$minuti}");
                // The old "administrators can correct later" ability no longer exists.
                $this->assertFalse($admin->can('correggere', $this->presenza), "{$ore}:{$minuti}");
            }
        }
    }
}
