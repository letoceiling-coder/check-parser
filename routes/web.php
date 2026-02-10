<?php

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\Route;
// Раздача файлов из storage (QR и др.) — чтобы работало без symlink public/storage
Route::get('/storage/{path}', function (string $path) {
    $path = str_replace('..', '', $path);
    $fullPath = storage_path('app/public/' . $path);
    if (!is_file($fullPath) || !str_starts_with(realpath($fullPath), realpath(storage_path('app/public')))) {
        abort(404);
    }
    return response()->file($fullPath);
})->where('path', '.*');

// Serve React app - let React handle authentication
// Exclude API routes from catch-all
Route::get('/{any?}', [AppController::class, 'index'])
    ->where('any', '^(?!api/).*');
