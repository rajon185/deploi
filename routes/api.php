<?php

use App\Http\Controllers\Api\ApiController;
use Illuminate\Support\Facades\Route;

// Authenticate with a token from Account → API tokens: "Authorization: Bearer <token>"
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('/user', fn ($request) => $request->user()->only(['id', 'name', 'email']));
    Route::get('/servers', [ApiController::class, 'servers']);
    Route::get('/servers/{server}', [ApiController::class, 'server']);
    Route::get('/sites', [ApiController::class, 'sites']);
    Route::get('/sites/{site}', [ApiController::class, 'site']);
    Route::post('/sites/{site}/deploy', [ApiController::class, 'deploy']);
    Route::get('/sites/{site}/deployments', [ApiController::class, 'deployments']);
    Route::get('/sites/{site}/deployments/{deployment}', [ApiController::class, 'deployment']);
});
