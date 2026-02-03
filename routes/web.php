<?php

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\Route;

// Serve React app - let React handle authentication
// Exclude API routes from catch-all
Route::get('/{any?}', [AppController::class, 'index'])
    ->where('any', '^(?!api/).*');
