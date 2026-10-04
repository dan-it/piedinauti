<?php

use App\Http\Controllers\Gestione\AmministratoreController;
use App\Http\Controllers\Gestione\AssegnazioneController;
use App\Http\Controllers\Gestione\BambinoController;
use App\Http\Controllers\Gestione\CittaController;
use App\Http\Controllers\Gestione\FermataController;
use App\Http\Controllers\Gestione\LineaArchivioController;
use App\Http\Controllers\Gestione\LineaController;
use App\Http\Controllers\Gestione\PersonaController;
use Illuminate\Support\Facades\Route;

// Management screens. Every action is authorized by a policy inside the controller.
Route::middleware('auth')->group(function () {
    // Global administrator: cities and administrators.
    Route::get('citta', [CittaController::class, 'index'])->name('citta.index');
    Route::get('citta/create', [CittaController::class, 'create'])->name('citta.create');
    Route::post('citta', [CittaController::class, 'store'])->name('citta.store');
    Route::get('citta/{citta}/edit', [CittaController::class, 'edit'])->name('citta.edit');
    Route::put('citta/{citta}', [CittaController::class, 'update'])->name('citta.update');

    Route::get('amministratori', [AmministratoreController::class, 'index'])->name('amministratori.index');
    Route::get('amministratori/create', [AmministratoreController::class, 'create'])->name('amministratori.create');
    Route::post('amministratori', [AmministratoreController::class, 'store'])->name('amministratori.store');
    Route::get('amministratori/{utente}/edit', [AmministratoreController::class, 'edit'])->name('amministratori.edit');
    Route::put('amministratori/{utente}', [AmministratoreController::class, 'update'])->name('amministratori.update');
    Route::post('amministratori/{utente}/reinvia', [AmministratoreController::class, 'reinvia'])->name('amministratori.reinvia');
    Route::delete('amministratori/{utente}', [AmministratoreController::class, 'destroy'])->name('amministratori.destroy');

    // City administrator: the people and the children of their city.
    Route::get('persone', [PersonaController::class, 'index'])->name('persone.index');
    Route::get('persone/create', [PersonaController::class, 'create'])->name('persone.create');
    Route::post('persone', [PersonaController::class, 'store'])->name('persone.store');
    Route::get('persone/{utente}/edit', [PersonaController::class, 'edit'])->name('persone.edit');
    Route::put('persone/{utente}', [PersonaController::class, 'update'])->name('persone.update');
    Route::post('persone/{utente}/reinvia', [PersonaController::class, 'reinvia'])->name('persone.reinvia');
    Route::delete('persone/{utente}', [PersonaController::class, 'destroy'])->name('persone.destroy');

    Route::get('bambini', [BambinoController::class, 'index'])->name('bambini.index');
    Route::get('bambini/create', [BambinoController::class, 'create'])->name('bambini.create');
    Route::post('bambini', [BambinoController::class, 'store'])->name('bambini.store');
    Route::get('bambini/{bambino}/edit', [BambinoController::class, 'edit'])->name('bambini.edit');
    Route::put('bambini/{bambino}', [BambinoController::class, 'update'])->name('bambini.update');
    Route::delete('bambini/{bambino}', [BambinoController::class, 'destroy'])->name('bambini.destroy');

    // City administrator: lines and their stops.
    Route::get('linee', [LineaController::class, 'index'])->name('linee.index');
    Route::get('linee/create', [LineaController::class, 'create'])->name('linee.create');
    Route::post('linee', [LineaController::class, 'store'])->name('linee.store');
    Route::get('linee/{linea}/edit', [LineaController::class, 'edit'])->name('linee.edit');
    Route::put('linee/{linea}', [LineaController::class, 'update'])->name('linee.update');
    Route::put('linee/{linea}/responsabili', [LineaController::class, 'aggiornaResponsabili'])->name('linee.responsabili');
    Route::delete('linee/{linea}', [LineaController::class, 'destroy'])->name('linee.destroy');
    Route::post('linee/{linea}/archivia', [LineaController::class, 'archivia'])->name('linee.archivia');
    Route::get('linee/{linea}/duplica', [LineaController::class, 'formDuplica'])->name('linee.duplica.form');
    Route::post('linee/{linea}/duplica', [LineaController::class, 'duplica'])->name('linee.duplica');

    // City administrators and managers: chaperones and children of each stop.
    Route::get('assegnazioni', [AssegnazioneController::class, 'linee'])->name('assegnazioni.index');
    Route::get('assegnazioni/linee/{linea}', [AssegnazioneController::class, 'linea'])->name('assegnazioni.linea');
    Route::get('assegnazioni/fermate/{fermata}', [AssegnazioneController::class, 'fermata'])->name('assegnazioni.fermata');
    Route::put('assegnazioni/fermate/{fermata}/accompagnatori', [AssegnazioneController::class, 'aggiornaAccompagnatori'])->name('assegnazioni.accompagnatori');
    Route::post('assegnazioni/fermate/{fermata}/bambini', [AssegnazioneController::class, 'aggiungiBambino'])->name('assegnazioni.bambini.aggiungi');
    Route::delete('assegnazioni/fermate/{fermata}/bambini/{bambino}', [AssegnazioneController::class, 'rimuoviBambino'])->name('assegnazioni.bambini.rimuovi');

    // Global administrator: archived lines, from every city.
    Route::get('linee-archiviate', [LineaArchivioController::class, 'index'])->name('linee-archiviate.index');
    Route::post('linee/{linea}/ripristina', [LineaArchivioController::class, 'ripristina'])->name('linee.ripristina');

    Route::get('linee/{linea}/fermate/create', [FermataController::class, 'create'])->name('fermate.create');
    Route::post('linee/{linea}/fermate', [FermataController::class, 'store'])->name('fermate.store');
    Route::get('fermate/{fermata}/edit', [FermataController::class, 'edit'])->name('fermate.edit');
    Route::put('fermate/{fermata}', [FermataController::class, 'update'])->name('fermate.update');
    Route::delete('fermate/{fermata}', [FermataController::class, 'destroy'])->name('fermate.destroy');
});
