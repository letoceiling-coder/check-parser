<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeployController;
use Illuminate\Support\Facades\Route;

Route::post('/deploy', [DeployController::class, 'deploy']);

// Auth routes
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Bot routes
    Route::get('/bot', [\App\Http\Controllers\Api\BotController::class, 'index']);
    Route::post('/bot', [\App\Http\Controllers\Api\BotController::class, 'store']);
    Route::put('/bot/{id}', [\App\Http\Controllers\Api\BotController::class, 'update']);
    Route::post('/bot/{id}/test-webhook', [\App\Http\Controllers\Api\BotController::class, 'testWebhook']);
});
