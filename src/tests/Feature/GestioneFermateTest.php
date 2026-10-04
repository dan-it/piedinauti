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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GestioneFermateTest extends TestCase
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
        $this->linea = Linea::factory()->create(['citta_id' => $this->citta->id]);
    }

    /** Names of the line's stops, in the stored order. */
    private function ordine(?Linea $linea = null): array
    {
        return Fermata::query()->where('linea_id', ($linea ?? $this->linea)->id)->orderBy('ordine')->pluck('nome')->all();
    }

    private function aggiungi(string $nome, string $orario)
    {
        return $this->actingAs($this->admin)->post("/linee/{$this->linea->id}/fermate", ['nome' => $nome, 'orario' => $orario]);
    }

    public function test_solo_l_amministratore_di_citta_accede(): void
    {
        $responsabile = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Responsabile)->create();

        $this->actingAs($responsabile)->get("/linee/{$this->linea->id}/fermate/create")->assertForbidden();
        $this->actingAs($responsabile)->post("/linee/{$this->linea->id}/fermate", ['nome' => 'X', 'orario' => '07:00'])->assertForbidden();
        $this->assertSame(0, Fermata::count());
    }

    public function test_si_aggiunge_una_fermata_alla_linea(): void
    {
        $this->actingAs($this->admin)->get("/linee/{$this->linea->id}/fermate/create")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('fermate/Form')->where('linea.id', $this->linea->id)->where('fermata', null));

        $this->aggiungi('  Parco giochi ', '07:40')->assertRedirect("/linee/{$this->linea->id}/edit");

        $fermata = Fermata::query()->firstOrFail();
        $this->assertSame('Parco giochi', $fermata->nome);
        $this->assertSame('07:40:00', $fermata->orario);
        $this->assertSame($this->citta->id, $fermata->citta_id);
        $this->assertSame($this->linea->id, $fermata->linea_id);
        $this->assertSame(1, $fermata->ordine);
    }

    public function test_le_fermate_si_ordinano_da_sole_per_orario(): void
    {
        $this->aggiungi('Scuola', '08:05');
        $this->aggiungi('Parco', '07:40');
        $this->aggiungi('Via Roma', '07:50');

        $this->assertSame(['Parco', 'Via Roma', 'Scuola'], $this->ordine());
        $this->assertSame([1, 2, 3], Fermata::query()->orderBy('ordine')->pluck('ordine')->all());
    }

    public function test_cambiando_l_orario_la_fermata_cambia_posto(): void
    {
        $this->aggiungi('Parco', '07:40');
        $this->aggiungi('Via Roma', '07:50');
        $this->aggiungi('Scuola', '08:05');
        $parco = Fermata::query()->where('nome', 'Parco')->firstOrFail();

        $this->actingAs($this->admin)->get("/fermate/{$parco->id}/edit")
            ->assertInertia(fn (Assert $p) => $p->component('fermate/Form')->where('fermata.orario', '07:40'));

        // The first stop moves to the end: a swap that would collide on the unique (line, order) pair
        // if the stops were renumbered one by one.
        $this->actingAs($this->admin)->put("/fermate/{$parco->id}", ['nome' => 'Parco giochi', 'orario' => '08:30'])
            ->assertRedirect("/linee/{$this->linea->id}/edit");

        $this->assertSame(['Via Roma', 'Scuola', 'Parco giochi'], $this->ordine());
    }

    public function test_due_fermate_alla_stessa_ora_restano_nell_ordine_di_inserimento(): void
    {
        $this->aggiungi('Prima', '07:45');
        $this->aggiungi('Seconda', '07:45');

        $this->assertSame(['Prima', 'Seconda'], $this->ordine());
    }

    public function test_eliminando_una_fermata_le_altre_si_rinumerano(): void
    {
        $this->aggiungi('Parco', '07:40');
        $this->aggiungi('Via Roma', '07:50');
        $this->aggiungi('Scuola', '08:05');
        $centrale = Fermata::query()->where('nome', 'Via Roma')->firstOrFail();

        $this->actingAs($this->admin)->delete("/fermate/{$centrale->id}")->assertRedirect("/linee/{$this->linea->id}/edit");

        $this->assertSame(['Parco', 'Scuola'], $this->ordine());
        $this->assertSame([1, 2], Fermata::query()->orderBy('ordine')->pluck('ordine')->all());
    }

    public function test_nome_e_orario_sono_validati(): void
    {
        $this->aggiungi('', '07:40')->assertSessionHasErrors('nome');
        $this->aggiungi('Parco', '')->assertSessionHasErrors('orario');
        $this->aggiungi('Parco', '25:00')->assertSessionHasErrors('orario');
        $this->aggiungi('Parco', 'sette e mezza')->assertSessionHasErrors('orario');

        $this->assertSame(0, Fermata::count());
    }

    public function test_una_fermata_con_presenze_non_si_elimina(): void
    {
        $this->aggiungi('Parco', '07:40');
        $fermata = Fermata::query()->firstOrFail();
        $bambino = Bambino::factory()->create(['citta_id' => $this->citta->id]);
        Presenza::registra($fermata, $bambino, today(), presente: true);

        $this->actingAs($this->admin)->delete("/fermate/{$fermata->id}")->assertSessionHas('errore');

        $this->assertNotNull($fermata->fresh());
    }

    public function test_le_fermate_e_le_linee_di_altre_citta_non_si_toccano(): void
    {
        $lineaEstranea = Linea::factory()->create(['citta_id' => $this->altra->id]);
        $fermataEstranea = Fermata::factory()->create([
            'linea_id' => $lineaEstranea->id, 'citta_id' => $this->altra->id, 'ordine' => 1, 'orario' => '07:40:00',
        ]);

        $this->actingAs($this->admin)->get("/linee/{$lineaEstranea->id}/fermate/create")->assertNotFound();
        $this->actingAs($this->admin)->post("/linee/{$lineaEstranea->id}/fermate", ['nome' => 'X', 'orario' => '07:00'])->assertNotFound();
        $this->actingAs($this->admin)->get("/fermate/{$fermataEstranea->id}/edit")->assertNotFound();
        $this->actingAs($this->admin)->put("/fermate/{$fermataEstranea->id}", ['nome' => 'X', 'orario' => '07:00'])->assertNotFound();
        $this->actingAs($this->admin)->delete("/fermate/{$fermataEstranea->id}")->assertNotFound();

        $this->assertSame(1, Fermata::query()->withoutGlobalScopes()->count());
    }
}
