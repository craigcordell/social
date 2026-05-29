<?php

use App\Http\Controllers\Api\AyrshareCompatibilityController;
use App\Http\Controllers\Api\ConnectedAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('post', [AyrshareCompatibilityController::class, 'store']);
    Route::delete('post', [AyrshareCompatibilityController::class, 'destroy']);
    Route::get('post/{id}', [AyrshareCompatibilityController::class, 'show']);
    Route::post('comments', [AyrshareCompatibilityController::class, 'comments']);
    Route::post('analytics/post', [AyrshareCompatibilityController::class, 'postAnalytics']);
    Route::post('analytics/social', [AyrshareCompatibilityController::class, 'socialAnalytics']);

    Route::get('connected-accounts', ConnectedAccountController::class);
});
