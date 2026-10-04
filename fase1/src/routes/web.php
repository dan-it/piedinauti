<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// The site has no public landing page: send everybody to the dashboard,
// which asks guests to sign in first.
Route::redirect('/', '/dashboard')->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
