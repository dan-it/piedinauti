<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DuplicaLineaTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Citta $altra;

    private User $admin;

    private Linea $linea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->citta = Citta::factory()->create();
        $this->altra = Citta::factory()->create();
        $this->admin = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();
        $this->linea = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde - Andata']);

        foreach ([[1, '07:40:00', 'Parco'], [2, '07:50:00', 'Via Roma'], [3, '08:05:00', 'Scuola']] as [$ordine, $orario, $nome]) {
            Fermata::factory()->create([
                'linea_id' => $this->linea->id, 'citta_id' => $this->citta->id, 'ordine' => $ordine, 'orario' => $orario, 'nome' => $nome,
            ]);
        }
    }

    private function duplica(string $nome)
    {
        return $this->actingAs($this->admin)->post("/linee/{$this->linea->id}/duplica", ['nome' => $nome]);
    }

    public function test_il_modulo_propone_un_nome_e_mostra_quante_fermate_si_copiano(): void
    {
        $this->actingAs($this->admin)->get("/linee/{$this->linea->id}/duplica")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('linee/Duplica')
                ->where('linea.fermate', 3)
                ->where('nome_proposto', 'Verde - Andata (copia)'));
    }

    public function test_la_copia_ha_le_stesse_fermate_con_nomi_orari_e_ordine(): void
    {
        $this->duplica('Verde - Ritorno')->assertSessionHas('status', 'Linea duplicata con 3 fermate.');

        $copia = Linea::query()->where('nome', 'Verde - Ritorno')->firstOrFail();

        $this->assertSame($this->citta->id, $copia->citta_id);
        $this->assertSame(
            [['Parco', '07:40:00', 1], ['Via Roma', '07:50:00', 2], ['Scuola', '08:05:00', 3]],
            $copia->fermate->map(fn (Fermata $f) => [$f->nome, $f->orario, $f->ordine])->all(),
        );
        $this->assertTrue($copia->fermate->every(fn (Fermata $f) => $f->citta_id === $this->citta->id && $f->linea_id === $copia->id));
    }

    public function test_dopo_la_copia_si_va_alla_pagina_della_nuova_linea(): void
    {
        $risposta = $this->duplica('Verde - Ritorno');

        $copia = Linea::query()->where('nome', 'Verde - Ritorno')->firstOrFail();
        $risposta->assertRedirect("/linee/{$copia->id}/edit");
    }

    public function test_l_originale_resta_com_e(): void
    {
        $this->duplica('Verde - Ritorno');

        $this->assertSame(3, $this->linea->fermate()->count());
        $this->assertSame(['Parco', 'Via Roma', 'Scuola'], $this->linea->fermate()->pluck('nome')->all());
        $this->assertSame(2, Linea::count());
        $this->assertSame(6, Fermata::count());
    }

    public function test_responsabili_accompagnatori_e_bambini_non_vengono_copiati(): void
    {
        $responsabile = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Responsabile)->create();
        $accompagnatore = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Accompagnatore)->create();
        $this->linea->assegnaResponsabile($responsabile);
        $prima = $this->linea->fermate->first();
        $prima->assegnaAccompagnatore($accompagnatore);
        $prima->assegnaBambino(Bambino::factory()->create(['citta_id' => $this->citta->id]));

        $this->duplica('Verde - Ritorno');

        $copia = Linea::query()->where('nome', 'Verde - Ritorno')->firstOrFail();
        $this->assertSame(0, $copia->responsabili()->count());
        $this->assertSame(0, $copia->fermate->sum(fn (Fermata $f) => $f->accompagnatori()->count() + $f->bambini()->count()));
        // ... and the original keeps all of them.
        $this->assertSame(1, $this->linea->responsabili()->count());
        $this->assertSame(1, $prima->accompagnatori()->count());
    }

    public function test_si_duplica_anche_una_linea_senza_fermate(): void
    {
        $vuota = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Vuota']);

        $this->actingAs($this->admin)->post("/linee/{$vuota->id}/duplica", ['nome' => 'Vuota 2'])
            ->assertSessionHas('status', 'Linea duplicata con 0 fermate.');

        $this->assertSame(0, Linea::query()->where('nome', 'Vuota 2')->firstOrFail()->fermate()->count());
    }

    public function test_il_nome_e_obbligatorio_e_non_puo_essere_quello_di_una_linea_attiva(): void
    {
        $this->duplica('')->assertSessionHasErrors('nome');
        $this->duplica('Verde - Andata')->assertSessionHasErrors(['nome' => 'Esiste già una linea con questo nome.']);

        $this->assertSame(1, Linea::count());
        $this->assertSame(3, Fermata::count());
    }

    public function test_la_copia_puo_prendere_il_nome_di_una_linea_archiviata(): void
    {
        $archiviata = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde - Ritorno']);
        $archiviata->update(['archiviata_il' => now()]);

        $this->duplica('Verde - Ritorno')->assertSessionHasNoErrors();

        // Two lines share the name, but only one is active.
        $this->assertSame(2, Linea::query()->withoutGlobalScopes()->where('nome', 'Verde - Ritorno')->count());
        $this->assertSame(1, Linea::query()->withoutGlobalScopes()->where('nome', 'Verde - Ritorno')->whereNull('archiviata_il')->count());
    }

    public function test_solo_l_amministratore_di_citta_duplica(): void
    {
        $responsabile = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Responsabile)->create();
        $this->linea->assegnaResponsabile($responsabile);

        $this->actingAs($responsabile)->get("/linee/{$this->linea->id}/duplica")->assertForbidden();
        $this->actingAs($responsabile)->post("/linee/{$this->linea->id}/duplica", ['nome' => 'Copia'])->assertForbidden();

        $this->assertSame(1, Linea::count());
    }

    public function test_le_linee_di_altre_citta_e_quelle_archiviate_non_si_duplicano(): void
    {
        $estranea = Linea::factory()->create(['citta_id' => $this->altra->id]);
        $this->actingAs($this->admin)->post("/linee/{$estranea->id}/duplica", ['nome' => 'Copia'])->assertNotFound();

        $this->linea->update(['archiviata_il' => now()]);
        $this->actingAs($this->admin)->post("/linee/{$this->linea->id}/duplica", ['nome' => 'Copia'])->assertNotFound();
        $this->actingAs($this->admin)->get("/linee/{$this->linea->id}/duplica")->assertNotFound();
    }

    public function test_gli_ospiti_vanno_al_login(): void
    {
        $this->get("/linee/{$this->linea->id}/duplica")->assertRedirect('/login');
    }
}
