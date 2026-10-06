<?php

use App\Http\Controllers\Gestione\ReportController;
use Illuminate\Support\Facades\Route;

// Attendance reports (read-only): by line and period, by child, and a CSV export.
Route::middleware('auth')->group(function () {
    Route::get('report', [ReportController::class, 'index'])->name('report.index');
    Route::get('report/bambini', [ReportController::class, 'bambini'])->name('report.bambini');
    Route::get('report/bambini/{bambino}', [ReportController::class, 'bambino'])->name('report.bambino');
    Route::get('report/esporta', [ReportController::class, 'esporta'])->name('report.esporta');
});
