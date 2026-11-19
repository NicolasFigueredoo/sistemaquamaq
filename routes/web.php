<?php

use App\Http\Controllers\AuditorNoteController;
use App\Http\Controllers\ExemysTestController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\NetworkController;
use App\Http\Controllers\PlantController;
use App\Http\Controllers\PlantSettingsController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    Route::get('/olvide-contrasena', [ForgotPasswordController::class, 'show'])->name('password.forgot');
    Route::post('/olvide-contrasena/codigo', [ForgotPasswordController::class, 'sendCode'])->name('password.forgot.code');
    Route::post('/olvide-contrasena', [ForgotPasswordController::class, 'reset'])->name('password.forgot.reset');
    Route::post('/olvide-contrasena/reiniciar', [ForgotPasswordController::class, 'restart'])->name('password.forgot.restart');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', NetworkController::class)->name('network');

    Route::get('/perfil', [ProfileController::class, 'show'])->name('profile');
    Route::post('/perfil/mail/codigo', [ProfileController::class, 'sendEmailCode'])->name('profile.email.code');
    Route::put('/perfil/mail', [ProfileController::class, 'updateEmail'])->name('profile.email');
    Route::post('/perfil/contrasena/codigo', [ProfileController::class, 'sendPasswordCode'])->name('profile.password.code');
    Route::put('/perfil/contrasena', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // La planta del prototipo Exemys antes se llamaba "Quamaq · Prueba Exemys": la dirección vieja sigue funcionando.
    Route::get('/plantas/quamaq-prueba-exemys/{rest?}', fn (?string $rest = null) => redirect('/plantas/szykula-aguas-escobar-sa'.($rest ? "/{$rest}" : '')))
        ->where('rest', '.*');
    Route::get('/plantas/{plant}', [PlantController::class, 'show'])->name('plants.show');
    Route::get('/plantas/{plant}/cip', [PlantController::class, 'cip'])->name('plants.cip');
    Route::put('/plantas/{plant}/configuracion', [PlantSettingsController::class, 'update'])->name('plants.settings');
    Route::get('/plantas/{plant}/exemys', ExemysTestController::class)->name('plants.exemys');
    Route::post('/plantas/{plant}/notas', [AuditorNoteController::class, 'store'])->name('plants.notes');
});
