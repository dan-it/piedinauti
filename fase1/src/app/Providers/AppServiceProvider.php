<?php

namespace App\Providers;

use App\Support\CittaCorrente;
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
        //
    }
}
