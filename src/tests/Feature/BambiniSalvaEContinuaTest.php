<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Save and add another" on the new-child form: after saving, back to an empty form.
 */
class BambiniSalvaEContinuaTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->citta = Citta::factory()->create();
        $this->admin = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();
    }

    public function test_salva_e_aggiungi_un_altro_torna_al_modulo_vuoto(): void
    {
        $this->actingAs($this->admin)->post('/bambini', ['nome' => 'Luca', 'cognome' => 'Rossi', 'continua' => true])
            ->assertRedirect('/bambini/create')
            ->assertSessionHas('status', 'Luca Rossi aggiunto. Aggiungi il prossimo.');

        $this->assertSame(1, Bambino::count());
    }

    public function test_salva_semplice_torna_all_elenco(): void
    {
        $this->actingAs($this->admin)->post('/bambini', ['nome' => 'Luca', 'cognome' => 'Rossi', 'continua' => false])
            ->assertRedirect('/bambini')
            ->assertSessionHas('status', 'Luca Rossi aggiunto.');

        $this->actingAs($this->admin)->post('/bambini', ['nome' => 'Anna'])->assertRedirect('/bambini');

        $this->assertSame(2, Bambino::count());
    }

    public function test_senza_cognome_il_messaggio_non_ha_spazi_in_piu(): void
    {
        $this->actingAs($this->admin)->post('/bambini', ['nome' => 'Zoe', 'continua' => true])
            ->assertSessionHas('status', 'Zoe aggiunto. Aggiungi il prossimo.');
    }

    public function test_se_ci_sono_errori_si_resta_sul_modulo_senza_salvare(): void
    {
        $this->actingAs($this->admin)->post('/bambini', ['nome' => '', 'cognome' => 'Rossi', 'continua' => true])
            ->assertSessionHasErrors('nome');

        $this->assertSame(0, Bambino::count());
    }

    public function test_il_bambino_va_sempre_nella_citta_dell_amministratore(): void
    {
        $altra = Citta::factory()->create();

        $this->actingAs($this->admin)->post('/bambini', ['nome' => 'Luca', 'cognome' => 'Rossi', 'continua' => true, 'citta_id' => $altra->id]);

        $this->assertSame($this->citta->id, Bambino::query()->firstOrFail()->citta_id);
    }

    public function test_si_possono_aggiungere_piu_bambini_di_seguito(): void
    {
        foreach ([['Luca', 'Rossi'], ['Anna', 'Bianchi'], ['Marco', '']] as [$nome, $cognome]) {
            $this->actingAs($this->admin)->post('/bambini', ['nome' => $nome, 'cognome' => $cognome, 'continua' => true])
                ->assertRedirect('/bambini/create');
        }

        $this->assertSame(3, Bambino::count());
    }
}
