<?php

use App\Http\Controllers\Api\IngestController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:ingest')->prefix('ingest')->group(function () {
    Route::post('/telemetry', [IngestController::class, 'telemetry']);
    Route::post('/cip', [IngestController::class, 'cip']);
});
