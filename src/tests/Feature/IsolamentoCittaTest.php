<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\User;
use App\Support\CittaCorrente;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class IsolamentoCittaTest extends TestCase
{
    use RefreshDatabase;

    private Citta $a;

    private Citta $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Citta::factory()->create();
        $this->b = Citta::factory()->create();
        Bambino::factory()->count(2)->create(['citta_id' => $this->a->id]);
        Bambino::factory()->count(3)->create(['citta_id' => $this->b->id]);
    }

    public function test_senza_limite_si_vedono_tutte_le_citta(): void
    {
        $this->assertSame(5, Bambino::count());
        $this->assertSame(2, Citta::count());
    }

    public function test_il_limite_nasconde_i_dati_delle_altre_citta(): void
    {
        app(CittaCorrente::class)->limitaA($this->a->id);

        $this->assertSame(2, Bambino::count());
        $this->assertSame(1, Citta::count());
        $this->assertNull(Citta::find($this->b->id));
    }

    public function test_un_utente_senza_citta_non_vede_nulla(): void
    {
        app(CittaCorrente::class)->limitaA(null);

        $this->assertSame(0, Bambino::count());
        $this->assertSame(0, Citta::count());
    }

    public function test_i_nuovi_record_ereditano_la_citta_corrente(): void
    {
        app(CittaCorrente::class)->limitaA($this->a->id);

        $bambino = Bambino::create(['nome' => 'Luca', 'cognome' => 'Bianchi']);

        $this->assertSame($this->a->id, $bambino->citta_id);
    }

    public function test_non_si_puo_creare_un_record_in_un_altra_citta(): void
    {
        app(CittaCorrente::class)->limitaA($this->a->id);

        $this->expectException(DomainException::class);

        Bambino::create(['citta_id' => $this->b->id, 'nome' => 'Luca', 'cognome' => 'Bianchi']);
    }

    public function test_senza_limiti_sospende_temporaneamente_il_limite(): void
    {
        $corrente = app(CittaCorrente::class);
        $corrente->limitaA($this->a->id);

        $totale = $corrente->senzaLimiti(fn () => Bambino::count());

        $this->assertSame(5, $totale);
        $this->assertSame(2, Bambino::count());
    }

    public function test_la_richiesta_web_e_limitata_alla_citta_dell_utente(): void
    {
        Route::middleware('web')->get('/_prova/conta-bambini', fn () => response((string) Bambino::count()));

        $utente = User::factory()->perCitta($this->a)->conRuolo(Ruolo::AdminCitta)->create();

        $this->actingAs($utente)->get('/_prova/conta-bambini')->assertOk()->assertContent('2');
    }

    public function test_l_amministratore_globale_vede_tutte_le_citta(): void
    {
        Route::middleware('web')->get('/_prova/conta-bambini', fn () => response((string) Bambino::count()));

        $admin = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();

        $this->actingAs($admin)->get('/_prova/conta-bambini')->assertOk()->assertContent('5');
    }

    public function test_un_utente_senza_citta_e_senza_ruoli_non_vede_nulla(): void
    {
        Route::middleware('web')->get('/_prova/conta-bambini', fn () => response((string) Bambino::count()));

        $utente = User::factory()->senzaCitta()->create();

        $this->actingAs($utente)->get('/_prova/conta-bambini')->assertOk()->assertContent('0');
    }
}
