<?php

use App\Http\Controllers\Gestione\PresenzeController;
use Illuminate\Support\Facades\Route;

// Read-only view of attendance for managers and city administrators.
Route::middleware('auth')->group(function () {
    Route::get('presenze', [PresenzeController::class, 'index'])->name('presenze.index');

    // Administrators correct attendance from the dashboard (JSON, no page reload).
    Route::post('presenze/correggi', [PresenzeController::class, 'correggi'])->name('presenze.correggi');
    Route::post('presenze/arrivo', [PresenzeController::class, 'correggiArrivo'])->name('presenze.arrivo');
});
