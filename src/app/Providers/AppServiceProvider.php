<?php

namespace App\Providers;

use App\Models\Bambino;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Models\User;
use App\Policies\BambinoPolicy;
use App\Policies\CittaPolicy;
use App\Policies\FermataPolicy;
use App\Policies\LineaPolicy;
use App\Policies\PresenzaPolicy;
use App\Policies\UserPolicy;
use App\Support\CittaCorrente;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One shared holder for the "current city" restriction.
        $this->app->singleton(CittaCorrente::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registered explicitly: the Italian model names defeat reliable auto-discovery.
        Gate::policy(Citta::class, CittaPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Bambino::class, BambinoPolicy::class);
        Gate::policy(Linea::class, LineaPolicy::class);
        Gate::policy(Fermata::class, FermataPolicy::class);
        Gate::policy(Presenza::class, PresenzaPolicy::class);
    }
}
