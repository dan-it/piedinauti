<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ImpostaCittaCorrente;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The app runs behind Caddy (inside the Docker network), which terminates HTTPS and forwards
        // X-Forwarded-Proto / X-Forwarded-For. Trusting it makes Laravel see the real scheme and client:
        // without this, generated links and cookies would think the site is plain http. Only the
        // web container can reach the app (its port is not published), so trusting every proxy is safe here.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Keeps every request inside the signed-in user's city.
            ImpostaCittaCorrente::class,
        ]);

        // The city restriction must be active before Laravel loads models from the URL
        // (SubstituteBindings): a record of another city is then simply "not found" (404).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ImpostaCittaCorrente::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
