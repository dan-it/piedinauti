<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RuoliTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_persona_puo_avere_piu_ruoli(): void
    {
        $utente = User::factory()->perCitta()->create();

        $utente->assegnaRuolo(Ruolo::Responsabile);
        $utente->assegnaRuolo(Ruolo::Accompagnatore);
        $utente->assegnaRuolo(Ruolo::Accompagnatore); // repeating is harmless

        $this->assertTrue($utente->haRuolo(Ruolo::Responsabile));
        $this->assertTrue($utente->haRuolo(Ruolo::Accompagnatore));
        $this->assertFalse($utente->haRuolo(Ruolo::AdminCitta));
        $this->assertCount(2, $utente->fresh()->ruoli());
    }

    public function test_un_ruolo_si_puo_rimuovere(): void
    {
        $utente = User::factory()->perCitta()->conRuolo(Ruolo::Responsabile)->create();

        $utente->rimuoviRuolo(Ruolo::Responsabile);

        $this->assertFalse($utente->fresh()->haRuolo(Ruolo::Responsabile));
    }

    public function test_l_amministratore_globale_non_puo_avere_una_citta(): void
    {
        $utente = User::factory()->perCitta()->create();

        $this->expectException(InvalidArgumentException::class);

        $utente->assegnaRuolo(Ruolo::AdminGlobale);
    }

    public function test_gli_altri_ruoli_richiedono_una_citta(): void
    {
        $utente = User::factory()->senzaCitta()->create();

        $this->expectException(InvalidArgumentException::class);

        $utente->assegnaRuolo(Ruolo::Responsabile);
    }

    public function test_possono_esserci_piu_amministratori_globali_e_di_citta(): void
    {
        $citta = Citta::factory()->create();

        User::factory()->count(2)->conRuolo(Ruolo::AdminGlobale)->create();
        User::factory()->count(2)->perCitta($citta)->conRuolo(Ruolo::AdminCitta)->create();

        $this->assertSame(2, User::query()->whereHas('ruoliAssegnati', fn ($q) => $q->where('ruolo', 'admin_globale'))->count());
        $this->assertSame(2, User::query()->whereHas('ruoliAssegnati', fn ($q) => $q->where('ruolo', 'admin_citta'))->count());
    }

    public function test_solo_chi_ha_il_ruolo_puo_essere_assegnato_a_una_linea_o_a_una_fermata(): void
    {
        $citta = Citta::factory()->create();
        $linea = Linea::factory()->create(['citta_id' => $citta->id]);
        $fermata = Fermata::factory()->create(['linea_id' => $linea->id, 'citta_id' => $citta->id]);

        $responsabile = User::factory()->perCitta($citta)->conRuolo(Ruolo::Responsabile)->create();
        $accompagnatore = User::factory()->perCitta($citta)->conRuolo(Ruolo::Accompagnatore)->create();
        $linea->assegnaResponsabile($responsabile);
        $fermata->assegnaAccompagnatore($accompagnatore);

        $this->assertTrue($linea->fresh()->responsabili->contains($responsabile));
        $this->assertTrue($fermata->fresh()->accompagnatori->contains($accompagnatore));

        $this->expectException(InvalidArgumentException::class);
        $linea->assegnaResponsabile($accompagnatore);
    }

    public function test_il_comando_crea_un_amministratore_globale(): void
    {
        $this->artisan('piedinauti:admin-globale', [
            'email' => 'capo@example.com',
            'nome' => 'Giulia',
            'cognome' => 'Verdi',
        ])
            ->expectsQuestion('Password (minimo 8 caratteri)', 'una-password-lunga')
            ->assertExitCode(0);

        $utente = User::query()->where('email', 'capo@example.com')->firstOrFail();

        $this->assertNull($utente->citta_id);
        $this->assertTrue($utente->haRuolo(Ruolo::AdminGlobale));
        $this->assertSame('Giulia Verdi', $utente->name);
    }
}
