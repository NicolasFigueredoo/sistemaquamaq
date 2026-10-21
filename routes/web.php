<?php

use App\Http\Controllers\AuditorNoteController;
use App\Http\Controllers\ExemysTestController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\NetworkController;
use App\Http\Controllers\PlantController;
use App\Http\Controllers\PlantSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', NetworkController::class)->name('network');
    Route::get('/plantas/{plant}', [PlantController::class, 'show'])->name('plants.show');
    Route::get('/plantas/{plant}/cip', [PlantController::class, 'cip'])->name('plants.cip');
    Route::put('/plantas/{plant}/configuracion', [PlantSettingsController::class, 'update'])->name('plants.settings');
    Route::get('/plantas/{plant}/exemys', ExemysTestController::class)->name('plants.exemys');
    Route::post('/plantas/{plant}/notas', [AuditorNoteController::class, 'store'])->name('plants.notes');
});
