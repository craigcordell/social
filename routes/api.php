<?php

use App\Http\Controllers\Api\AyrshareCompatibilityController;
use App\Http\Controllers\Api\ConnectedAccountController;
use App\Http\Controllers\Api\SocialPostController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('post', [AyrshareCompatibilityController::class, 'store']);
    Route::delete('post', [AyrshareCompatibilityController::class, 'destroy']);
    Route::get('post/{id}', [AyrshareCompatibilityController::class, 'show']);
    Route::post('comments', [AyrshareCompatibilityController::class, 'comments']);
    Route::post('analytics/post', [AyrshareCompatibilityController::class, 'postAnalytics']);
    Route::post('analytics/social', [AyrshareCompatibilityController::class, 'socialAnalytics']);

    Route::get('connected-accounts', ConnectedAccountController::class);

    Route::post('posts', [SocialPostController::class, 'store']);
    Route::get('posts/{socialPost}', [SocialPostController::class, 'show']);
    Route::delete('posts/{socialPost}', [SocialPostController::class, 'destroy']);
});
