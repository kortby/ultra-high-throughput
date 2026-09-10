<?php

use App\Http\Controllers\ScalingWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('scaling')->group(function () {
    Route::get('/status', [ScalingWebhookController::class, 'status'])->name('scaling.status');
    Route::post('/evaluate', [ScalingWebhookController::class, 'evaluate'])->name('scaling.evaluate');
    Route::get('/metrics', [ScalingWebhookController::class, 'metrics'])->name('scaling.metrics');
});
