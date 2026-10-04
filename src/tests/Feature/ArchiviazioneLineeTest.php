<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Models\User;
use App\Support\CittaCorrente;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ArchiviazioneLineeTest extends TestCase
{
    use RefreshDatabase;

    private Citta $citta;

    private Citta $altra;

    private User $admin;

    private User $globale;

    private Linea $linea;

    private Fermata $fermata;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->citta = Citta::factory()->create();
        $this->altra = Citta::factory()->create();
        $this->admin = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::AdminCitta)->create();
        $this->globale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();
        $this->linea = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']);
        $this->fermata = Fermata::factory()->create([
            'linea_id' => $this->linea->id, 'citta_id' => $this->citta->id, 'ordine' => 1, 'orario' => '07:40:00',
        ]);
    }

    private function archivia(Linea $linea): void
    {
        $linea->update(['archiviata_il' => now()]);
    }

    public function test_l_amministratore_di_citta_archivia_una_linea(): void
    {
        $this->actingAs($this->admin)->post("/linee/{$this->linea->id}/archivia")
            ->assertRedirect('/linee')
            ->assertSessionHas('status');

        $this->assertNotNull($this->linea->fresh()->archiviata_il);
    }

    public function test_una_linea_con_presenze_si_puo_archiviare_e_il_suo_storico_resta(): void
    {
        $bambino = Bambino::factory()->create(['citta_id' => $this->citta->id]);
        Presenza::registra($this->fermata, $bambino, today(), presente: true);

        $this->actingAs($this->admin)->post("/linee/{$this->linea->id}/archivia")->assertRedirect('/linee');

        $this->assertSame(1, Presenza::count());
    }

    public function test_chi_non_e_amministratore_di_citta_non_archivia(): void
    {
        $responsabile = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Responsabile)->create();

        $this->actingAs($responsabile)->post("/linee/{$this->linea->id}/archivia")->assertForbidden();
        $this->actingAs($this->globale)->post("/linee/{$this->linea->id}/archivia")->assertForbidden();

        $this->assertNull($this->linea->fresh()->archiviata_il);
    }

    public function test_un_amministratore_non_archivia_le_linee_di_un_altra_citta(): void
    {
        $estranea = Linea::factory()->create(['citta_id' => $this->altra->id]);

        $this->actingAs($this->admin)->post("/linee/{$estranea->id}/archivia")->assertNotFound();

        $this->assertNull($estranea->fresh()->archiviata_il);
    }

    public function test_una_linea_archiviata_sparisce_per_l_amministratore_di_citta(): void
    {
        $this->archivia($this->linea);
        Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);

        $this->actingAs($this->admin)->get('/linee')
            ->assertInertia(fn (Assert $p) => $p->has('linee', 1)->where('linee.0.nome', 'Blu'));

        $this->actingAs($this->admin)->get("/linee/{$this->linea->id}/edit")->assertNotFound();
        $this->actingAs($this->admin)->put("/linee/{$this->linea->id}", ['nome' => 'X'])->assertNotFound();
        $this->actingAs($this->admin)->delete("/linee/{$this->linea->id}")->assertNotFound();
        $this->actingAs($this->admin)->post("/linee/{$this->linea->id}/archivia")->assertNotFound();
        $this->actingAs($this->admin)->get("/linee/{$this->linea->id}/fermate/create")->assertNotFound();
    }

    public function test_le_fermate_e_le_presenze_di_una_linea_archiviata_sono_nascoste_alle_persone_della_citta(): void
    {
        $bambino = Bambino::factory()->create(['citta_id' => $this->citta->id]);
        Presenza::registra($this->fermata, $bambino, today(), presente: true);
        $this->archivia($this->linea);

        $corrente = app(CittaCorrente::class);
        $corrente->limitaA($this->citta->id);
        $this->assertSame(0, Fermata::count());
        $this->assertSame(0, Presenza::count());
        $this->assertSame(0, Linea::count());

        // Not restricted (global administrator, console): everything is still there.
        $corrente->nessunLimite();
        $this->assertSame(1, Fermata::count());
        $this->assertSame(1, Presenza::count());
        $this->assertSame(1, Linea::count());
    }

    public function test_l_accompagnatore_non_vede_piu_le_fermate_di_una_linea_archiviata(): void
    {
        $accompagnatore = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Accompagnatore)->create();
        $this->fermata->assegnaAccompagnatore($accompagnatore);
        $this->archivia($this->linea);

        app(CittaCorrente::class)->limitaA($this->citta->id);

        $this->assertSame(0, $accompagnatore->fermateAccompagnatore()->count());
    }

    public function test_su_una_linea_archiviata_non_si_registrano_presenze(): void
    {
        // Before the line's arrival: attendance can only be recorded in the morning window.
        $this->travelTo(today()->setTime(7, 30));

        $accompagnatore = User::factory()->perCitta($this->citta)->conRuolo(Ruolo::Accompagnatore)->create();
        $this->fermata->assegnaAccompagnatore($accompagnatore);
        $bambino = Bambino::factory()->create(['citta_id' => $this->citta->id]);

        $this->assertTrue($accompagnatore->can('registrare', [Presenza::class, $this->fermata, $bambino]));

        $this->archivia($this->linea);

        $this->assertFalse($accompagnatore->can('registrare', [Presenza::class, $this->fermata->fresh(), $bambino]));
    }

    public function test_l_amministratore_globale_vede_le_linee_archiviate_divise_per_citta(): void
    {
        $zeta = Citta::factory()->create(['nome' => 'Zeta']);
        $alfa = Citta::factory()->create(['nome' => 'Alfa']);
        $primaDiAlfa = Linea::factory()->create(['citta_id' => $alfa->id, 'nome' => 'Vecchia']);
        $dopoDiAlfa = Linea::factory()->create(['citta_id' => $alfa->id, 'nome' => 'Recente']);
        $inZeta = Linea::factory()->create(['citta_id' => $zeta->id, 'nome' => 'Rossa']);
        $primaDiAlfa->update(['archiviata_il' => now()->subDays(3)]);
        $dopoDiAlfa->update(['archiviata_il' => now()->subDay()]);
        $inZeta->update(['archiviata_il' => now()]);
        Linea::factory()->create(['citta_id' => $alfa->id, 'nome' => 'Attiva']);

        $this->actingAs($this->globale)->get('/linee-archiviate')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('linee/Archiviate')
                ->has('gruppi', 2)
                ->where('gruppi.0.citta', 'Alfa') // alphabetical by city
                ->has('gruppi.0.linee', 2)
                ->where('gruppi.0.linee.0.nome', 'Recente') // newest archived first
                ->where('gruppi.0.linee.1.nome', 'Vecchia')
                ->where('gruppi.1.citta', 'Zeta')
                ->has('gruppi.1.linee', 1));
    }

    public function test_senza_linee_archiviate_non_ci_sono_gruppi(): void
    {
        $this->actingAs($this->globale)->get('/linee-archiviate')
            ->assertInertia(fn (Assert $p) => $p->has('gruppi', 0));
    }

    public function test_solo_l_amministratore_globale_vede_l_elenco_delle_archiviate(): void
    {
        $this->archivia($this->linea);

        $this->actingAs($this->admin)->get('/linee-archiviate')->assertForbidden();
    }

    public function test_gli_ospiti_vanno_al_login(): void
    {
        $this->get('/linee-archiviate')->assertRedirect('/login');
    }

    public function test_l_amministratore_globale_ripristina_una_linea(): void
    {
        $this->archivia($this->linea);

        $this->actingAs($this->globale)->post("/linee/{$this->linea->id}/ripristina")
            ->assertRedirect('/linee-archiviate')
            ->assertSessionHas('status');

        $this->assertNull($this->linea->fresh()->archiviata_il);

        // The city administrator sees it again.
        $this->actingAs($this->admin)->get('/linee')->assertInertia(fn (Assert $p) => $p->has('linee', 1));
    }

    public function test_l_amministratore_di_citta_non_puo_ripristinare(): void
    {
        $this->archivia($this->linea);

        // He cannot even see it: the line is "not found" for him.
        $this->actingAs($this->admin)->post("/linee/{$this->linea->id}/ripristina")->assertNotFound();

        $this->assertNotNull($this->linea->fresh()->archiviata_il);
    }

    public function test_non_si_ripristina_una_linea_non_archiviata(): void
    {
        $this->actingAs($this->globale)->post("/linee/{$this->linea->id}/ripristina")->assertForbidden();
    }

    public function test_il_nome_di_una_linea_archiviata_si_puo_riusare(): void
    {
        $this->archivia($this->linea);

        $this->actingAs($this->admin)->post('/linee', ['nome' => 'Verde'])->assertSessionHasNoErrors();

        $this->assertSame(2, Linea::query()->withoutGlobalScopes()->where('nome', 'Verde')->count());
        // After a request the city restriction is lifted: put it back to see what the administrator sees.
        app(CittaCorrente::class)->limitaA($this->citta->id);
        $this->assertSame(1, Linea::count(), 'the administrator sees only the new, active line');
    }

    public function test_due_linee_attive_non_possono_avere_lo_stesso_nome(): void
    {
        $this->actingAs($this->admin)->post('/linee', ['nome' => 'Verde'])
            ->assertSessionHasErrors(['nome' => 'Esiste già una linea con questo nome.']);
    }

    public function test_il_database_stesso_rifiuta_due_linee_attive_con_lo_stesso_nome(): void
    {
        $this->expectException(QueryException::class);

        Linea::query()->withoutGlobalScopes()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']);
    }

    public function test_il_database_accetta_piu_linee_archiviate_con_lo_stesso_nome(): void
    {
        $this->archivia($this->linea);
        Linea::query()->withoutGlobalScopes()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde', 'archiviata_il' => now()]);
        Linea::query()->withoutGlobalScopes()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde', 'archiviata_il' => now()]);

        $this->assertSame(3, Linea::query()->withoutGlobalScopes()->where('nome', 'Verde')->count());
    }

    public function test_si_rinomina_una_linea_con_il_nome_di_una_archiviata(): void
    {
        $this->archivia($this->linea);
        $blu = Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Blu']);

        $this->actingAs($this->admin)->put("/linee/{$blu->id}", ['nome' => 'Verde'])->assertSessionHasNoErrors();

        $this->assertSame('Verde', $blu->fresh()->nome);
    }

    public function test_il_ripristino_con_il_nome_gia_preso_chiede_un_altro_nome(): void
    {
        $this->archivia($this->linea);
        Linea::factory()->create(['citta_id' => $this->citta->id, 'nome' => 'Verde']); // took the name meanwhile

        $this->actingAs($this->globale)->post("/linee/{$this->linea->id}/ripristina")
            ->assertSessionHasErrors(['nome' => 'In questa città esiste già una linea attiva con questo nome: scegline un altro.']);
        $this->assertNotNull($this->linea->fresh()->archiviata_il);

        $this->actingAs($this->globale)->post("/linee/{$this->linea->id}/ripristina", ['nome' => 'Verde - vecchia'])
            ->assertSessionHasNoErrors();

        $linea = $this->linea->fresh();
        $this->assertNull($linea->archiviata_il);
        $this->assertSame('Verde - vecchia', $linea->nome);
    }

    public function test_il_ripristino_senza_conflitti_mantiene_il_nome(): void
    {
        $this->archivia($this->linea);

        $this->actingAs($this->globale)->post("/linee/{$this->linea->id}/ripristina")->assertSessionHasNoErrors();

        $this->assertSame('Verde', $this->linea->fresh()->nome);
    }
}
