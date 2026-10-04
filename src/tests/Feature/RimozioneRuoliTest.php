<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RimozioneRuoliTest extends TestCase
{
    use RefreshDatabase;

    public function test_rimuovere_un_ruolo_toglie_le_assegnazioni_che_ne_dipendono(): void
    {
        $citta = Citta::factory()->create();
        $linea = Linea::factory()->create(['citta_id' => $citta->id]);
        $fermata = Fermata::factory()->create(['linea_id' => $linea->id, 'citta_id' => $citta->id, 'ordine' => 1]);
        $persona = User::factory()->perCitta($citta)->conRuolo(Ruolo::Responsabile)->create();
        $persona->assegnaRuolo(Ruolo::Accompagnatore);
        $linea->assegnaResponsabile($persona);
        $fermata->assegnaAccompagnatore($persona);

        $persona->rimuoviRuolo(Ruolo::Responsabile);
        $this->assertSame(0, $linea->responsabili()->count());
        $this->assertSame(1, $fermata->accompagnatori()->count());

        $persona->rimuoviRuolo(Ruolo::Accompagnatore);
        $this->assertSame(0, $fermata->accompagnatori()->count());
    }
}
