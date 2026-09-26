<?php

use App\Domain\Telegram\Http\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', WebhookController::class);
