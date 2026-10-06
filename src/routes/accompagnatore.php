<?php

use App\Http\Controllers\Accompagnatore\OggiController;
use Illuminate\Support\Facades\Route;

// The chaperone's morning screen: today's lines, stops and attendance.
Route::middleware('auth')->group(function () {
    Route::get('oggi', [OggiController::class, 'index'])->name('oggi.index');
    Route::get('oggi/linee/{linea}', [OggiController::class, 'linea'])->name('oggi.linea');

    // JSON endpoints used by the screen while the chaperone taps (no page reload).
    Route::post('oggi/fermate/{fermata}/presenze', [OggiController::class, 'registra'])->name('oggi.presenze');
    Route::get('oggi/fermate/{fermata}/cerca', [OggiController::class, 'cerca'])->name('oggi.cerca');
    Route::get('oggi/bambini', [OggiController::class, 'bambini'])->name('oggi.bambini');
    Route::post('oggi/linee/{linea}/arrivo', [OggiController::class, 'arrivo'])->name('oggi.arrivo');
    Route::post('oggi/linee/{linea}/arrivo/annulla', [OggiController::class, 'annullaArrivo'])->name('oggi.arrivo.annulla');
});
