<?php

use App\Http\Controllers\Api\PixelforgeWebhookController;
use App\Http\Middleware\VerifyPixelforgeWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware(VerifyPixelforgeWebhookSignature::class)
    ->name('api.webhooks.pixelforge');
