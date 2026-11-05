<?php

use App\Http\Controllers\Api\EntriesController;
use App\Http\Controllers\Api\LanguagesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v2')->middleware('throttle:api-v2')->group(function (): void {
    Route::get('/languages', LanguagesController::class);
    Route::get('/entries/{type}', [EntriesController::class, 'index']);
    Route::get('/entries/{type}/{slug}', [EntriesController::class, 'show']);
});
