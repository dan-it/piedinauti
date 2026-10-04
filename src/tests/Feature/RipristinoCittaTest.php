<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\User;
use App\Support\CittaCorrente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RipristinoCittaTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_restrizione_non_sopravvive_alla_richiesta(): void
    {
        $this->withoutVite();
        $citta = Citta::factory()->create();
        $altra = Citta::factory()->create();
        Bambino::factory()->create(['citta_id' => $altra->id]);
        $admin = User::factory()->perCitta($citta)->conRuolo(Ruolo::AdminCitta)->create();

        $this->actingAs($admin)->get('/bambini')->assertOk();

        $this->assertFalse(app(CittaCorrente::class)->limitato());
        $this->assertSame(1, Bambino::count(), 'after the response the whole database is visible again');
    }
}
