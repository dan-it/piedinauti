<?php

namespace App\Http\Middleware;

use App\Enums\Ruolo;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $utente = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                // The interface builds its menu from the roles, and shows the city name.
                'user' => $utente === null ? null : [
                    ...$utente->toArray(),
                    'ruoli' => $utente->ruoli()->map(fn (Ruolo $ruolo) => $ruolo->value)->values()->all(),
                    'citta_nome' => $utente->citta?->nome,
                ],
            ],
            // One-off messages shown after an action (success or error).
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'errore' => fn () => $request->session()->get('errore'),
                // A warning: the action went through, but something needs attention.
                'avviso' => fn () => $request->session()->get('avviso'),
            ],
        ];
    }
}
