<?php

use App\Enums\Ruolo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// The site has no public landing page: send everybody to the dashboard,
// which asks guests to sign in first.
Route::redirect('/', '/dashboard')->name('home');

Route::get('dashboard', function (Request $request) {
    $utente = $request->user();

    // A chaperone with no other role has one thing to do in the morning: go straight to it.
    $soloAccompagnatore = $utente->haRuolo(Ruolo::Accompagnatore)
        && ! $utente->eAdminGlobale()
        && ! $utente->eAdminCitta()
        && ! $utente->haRuolo(Ruolo::Responsabile);

    if ($soloAccompagnatore) {
        return to_route('oggi.index');
    }

    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/gestione.php';
require __DIR__.'/accompagnatore.php';
require __DIR__.'/presenze.php';
require __DIR__.'/report.php';
