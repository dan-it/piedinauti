<?php

namespace Tests\Feature;

use App\Models\Citta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreaCittaTest extends TestCase
{
    use RefreshDatabase;

    public function test_il_comando_crea_una_citta(): void
    {
        $this->artisan('piedinauti:crea-citta', ['nome' => 'Città di prova'])->assertExitCode(0);

        $this->assertTrue(Citta::query()->where('nome', 'Città di prova')->exists());
    }

    public function test_il_comando_rifiuta_nomi_duplicati_o_vuoti(): void
    {
        Citta::factory()->create(['nome' => 'Milano']);

        $this->artisan('piedinauti:crea-citta', ['nome' => 'Milano'])->assertExitCode(1);
        $this->artisan('piedinauti:crea-citta', ['nome' => '  '])->assertExitCode(1);

        $this->assertSame(1, Citta::count());
    }
}
