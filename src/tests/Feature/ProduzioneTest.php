<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Behaviour that matters once the app sits behind the HTTPS proxy (Caddy) in production.
 */
class ProduzioneTest extends TestCase
{
    use RefreshDatabase;

    public function test_dietro_il_proxy_https_l_app_vede_lo_schema_e_il_client_reali(): void
    {
        Route::middleware('web')->get('/_prova/proxy', fn (Request $request) => response()->json([
            'sicura' => $request->isSecure(),
            'ip' => $request->ip(),
            'url' => url('/dashboard'),
        ]));

        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Host' => 'piedinauti.it'])
            ->get('/_prova/proxy')
            ->assertOk()
            ->assertJson(['sicura' => true, 'ip' => '203.0.113.7', 'url' => 'https://piedinauti.it/dashboard']);
    }

    public function test_senza_intestazioni_del_proxy_la_richiesta_resta_http(): void
    {
        Route::middleware('web')->get('/_prova/proxy', fn (Request $request) => response()->json(['sicura' => $request->isSecure()]));

        $this->get('/_prova/proxy')->assertOk()->assertJson(['sicura' => false]);
    }

    public function test_in_produzione_i_link_sono_sempre_https(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', url('/dashboard'));
        $this->assertStringStartsWith('https://', route('login'));
    }

    public function test_in_sviluppo_i_link_restano_come_sono(): void
    {
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('http://', url('/dashboard'));
    }

    public function test_il_controllo_di_salute_risponde(): void
    {
        // Used by monitoring services and by the container health check.
        $this->get('/up')->assertOk();
    }

    public function test_le_rotte_si_possono_mettere_in_cache(): void
    {
        // At startup the production container runs "artisan optimize", which caches the routes:
        // every route must be serializable (a closure that captured a non-serializable object would not be).
        foreach (Route::getRoutes() as $rotta) {
            $rotta->prepareForSerialization();
        }

        $this->assertTrue(true);
    }
}
