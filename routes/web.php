<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationHistoryController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'customer'])->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');

    Route::prefix('generations')
        ->name('generations.')
        ->group(function () {
            Route::get('/history', [GenerationHistoryController::class, 'index'])->name('history');
            Route::post('/', [GenerationController::class, 'store'])->name('store');
        });

    Route::prefix('wallet')
        ->name('wallet.')
        ->group(function () {
            Route::get('/', [WalletController::class, 'index'])->name('index');
            Route::post('/', [WalletController::class, 'store'])->name('store');
        });
});

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('reports', [AdminReportController::class, 'index'])->name('reports');
    });

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
