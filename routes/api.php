<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeployController;
use Illuminate\Support\Facades\Route;

Route::post('/deploy', [DeployController::class, 'deploy']);

// Telegram webhook (public, no auth required)
Route::post('/telegram/webhook', [\App\Http\Controllers\Api\TelegramWebhookController::class, 'handle']);

// Public API for OCR analysis and optimization
Route::get('/checks/problems', [\App\Http\Controllers\Api\CheckController::class, 'problems']);
Route::get('/checks/analyze/{id}', [\App\Http\Controllers\Api\CheckController::class, 'analyze']);

// File route - отдельно, поддерживает token в query string для iframe
Route::get('/checks/{id}/file', [\App\Http\Controllers\Api\CheckController::class, 'file']);

// Auth routes
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Bot routes
    Route::get('/bot', [\App\Http\Controllers\Api\BotController::class, 'index']);
    Route::post('/bot', [\App\Http\Controllers\Api\BotController::class, 'store']);
    Route::put('/bot/{id}', [\App\Http\Controllers\Api\BotController::class, 'update']);
    Route::put('/bot/{id}/settings', [\App\Http\Controllers\Api\BotController::class, 'updateSettings']);
    Route::get('/bot/{id}/description', [\App\Http\Controllers\Api\BotController::class, 'getDescription']);
    Route::put('/bot/{id}/description', [\App\Http\Controllers\Api\BotController::class, 'updateDescription']);
    Route::post('/bot/{id}/test-webhook', [\App\Http\Controllers\Api\BotController::class, 'testWebhook']);
    
    // Check routes
    Route::get('/checks', [\App\Http\Controllers\Api\CheckController::class, 'index']);
    Route::get('/checks/stats', [\App\Http\Controllers\Api\CheckController::class, 'stats']);
    Route::get('/checks/{id}', [\App\Http\Controllers\Api\CheckController::class, 'show']);
    Route::put('/checks/{id}', [\App\Http\Controllers\Api\CheckController::class, 'update']);
    Route::delete('/checks/{id}', [\App\Http\Controllers\Api\CheckController::class, 'destroy']);
});
