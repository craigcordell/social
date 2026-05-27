<?php

use App\Http\Controllers\Api\ConnectedAccountController;
use App\Http\Controllers\Api\SocialPostController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('connected-accounts', ConnectedAccountController::class);

    Route::post('posts', [SocialPostController::class, 'store']);
    Route::get('posts/{socialPost}', [SocialPostController::class, 'show']);
    Route::delete('posts/{socialPost}', [SocialPostController::class, 'destroy']);
});
