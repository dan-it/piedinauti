<?php

namespace App\Http\Middleware;

use App\Enums\Ruolo;
use App\Support\CittaCorrente;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the whole request to the signed-in user's city.
 * Must run after the session is started, so the user is known.
 */
class ImpostaCittaCorrente
{
    public function handle(Request $request, Closure $next): Response
    {
        $corrente = app(CittaCorrente::class);

        // Start from a clean state: the user itself is loaded without restrictions.
        $corrente->nessunLimite();

        $utente = $request->user();

        if ($utente !== null) {
            $globale = $utente->citta_id === null && $utente->haRuolo(Ruolo::AdminGlobale);

            if (! $globale) {
                // A user without a city (and not a global admin) sees nothing.
                $corrente->limitaA($utente->citta_id);
            }
        }

        return $next($request);
    }
}
