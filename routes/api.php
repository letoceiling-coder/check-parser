<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeployController;
use Illuminate\Support\Facades\Route;

Route::post('/deploy', [DeployController::class, 'deploy']);

// Telegram webhooks (public, no auth required)
Route::post('/telegram/webhook', [\App\Http\Controllers\Api\TelegramWebhookController::class, 'handle']);
Route::post('/raffle/webhook', [\App\Http\Controllers\Api\RaffleWebhookController::class, 'handle']);

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
    
    // Raffle settings routes
    Route::get('/bot/{id}/raffle-settings', [\App\Http\Controllers\Api\RaffleSettingsController::class, 'show']);
    Route::put('/bot/{id}/raffle-settings', [\App\Http\Controllers\Api\RaffleSettingsController::class, 'update']);
    Route::post('/bot/{id}/raffle-settings/upload-qr', [\App\Http\Controllers\Api\RaffleSettingsController::class, 'uploadQr']);
    Route::post('/bot/{id}/raffle-settings/initialize-tickets', [\App\Http\Controllers\Api\RaffleSettingsController::class, 'initializeTickets']);
    
    // Check routes
    Route::get('/checks', [\App\Http\Controllers\Api\CheckController::class, 'index']);
    Route::get('/checks/stats', [\App\Http\Controllers\Api\CheckController::class, 'stats']);
    Route::post('/checks/reparse-failed', [\App\Http\Controllers\Api\CheckController::class, 'reparseFailed']);
    Route::get('/checks/{id}', [\App\Http\Controllers\Api\CheckController::class, 'show']);
    Route::put('/checks/{id}', [\App\Http\Controllers\Api\CheckController::class, 'update']);
    Route::post('/checks/{id}/reparse', [\App\Http\Controllers\Api\CheckController::class, 'reparse']);
    Route::delete('/checks/{id}', [\App\Http\Controllers\Api\CheckController::class, 'destroy']);
    Route::put('/checks/{id}/approve', [\App\Http\Controllers\Api\CheckController::class, 'approve']);
    Route::put('/checks/{id}/reject', [\App\Http\Controllers\Api\CheckController::class, 'reject']);
    
    // Admin requests routes
    Route::get('/admin-requests', [\App\Http\Controllers\Api\AdminRequestController::class, 'index']);
    Route::put('/admin-requests/{id}/approve', [\App\Http\Controllers\Api\AdminRequestController::class, 'approve']);
    Route::put('/admin-requests/{id}/reject', [\App\Http\Controllers\Api\AdminRequestController::class, 'reject']);
    
    // Tickets routes
    Route::get('/tickets', [\App\Http\Controllers\Api\TicketController::class, 'index']);
    Route::get('/tickets/stats', [\App\Http\Controllers\Api\TicketController::class, 'stats']);
    
    // Bot users routes
    Route::get('/bot-users', [\App\Http\Controllers\Api\BotUserController::class, 'index']);
    Route::get('/bot-users/{id}', [\App\Http\Controllers\Api\BotUserController::class, 'show']);
    
    // Admin actions log
    Route::get('/admin-actions', [\App\Http\Controllers\Api\AdminActionLogController::class, 'index']);

    // Broadcast (рассылка)
    Route::get('/broadcasts', [\App\Http\Controllers\Api\BroadcastController::class, 'index']);
    Route::post('/broadcasts', [\App\Http\Controllers\Api\BroadcastController::class, 'store']);
    
    // Raffles routes
    Route::get('/bot/{botId}/raffles', [\App\Http\Controllers\Api\RaffleController::class, 'index']);
    Route::get('/bot/{botId}/raffles/current', [\App\Http\Controllers\Api\RaffleController::class, 'current']);
    Route::get('/bot/{botId}/raffles/participants', [\App\Http\Controllers\Api\RaffleController::class, 'getParticipants']);
    Route::get('/bot/{botId}/raffles/{raffleId}', [\App\Http\Controllers\Api\RaffleController::class, 'show']);
    Route::put('/bot/{botId}/raffles/{raffleId}', [\App\Http\Controllers\Api\RaffleController::class, 'update']);
    Route::post('/bot/{botId}/raffles/complete', [\App\Http\Controllers\Api\RaffleController::class, 'complete']);
    Route::post('/bot/{botId}/raffles/reset', [\App\Http\Controllers\Api\RaffleController::class, 'reset']);
    Route::post('/bot/{botId}/raffles/cancel', [\App\Http\Controllers\Api\RaffleController::class, 'cancel']);
});
