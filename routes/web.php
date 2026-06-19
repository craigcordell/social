<?php

use App\Http\Controllers\ApiTokensController;
use App\Http\Controllers\ConnectedAccountsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OAuth\FacebookOAuthController;
use App\Http\Controllers\OAuth\GoogleBusinessOAuthController;
use App\Http\Controllers\OAuth\InstagramOAuthController;
use App\Http\Controllers\OwnersController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    abort(404);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('owners', [OwnersController::class, 'index'])->name('owners.index');
    Route::post('owners', [OwnersController::class, 'store'])->name('owners.store');
    Route::get('connected-accounts', [ConnectedAccountsController::class, 'index'])->name('connected-accounts.index');
    Route::delete('connected-accounts/{connectedAccount}', [ConnectedAccountsController::class, 'destroy'])->name('connected-accounts.destroy');
    Route::get('api-tokens', [ApiTokensController::class, 'index'])->name('api-tokens.index');
    Route::post('api-tokens', [ApiTokensController::class, 'store'])->name('api-tokens.store');
    Route::delete('api-tokens/{token}', [ApiTokensController::class, 'destroy'])->name('api-tokens.destroy');
    Route::get('posts', [DashboardController::class, 'posts'])->name('posts.index');
    Route::get('oauth/facebook/redirect', [FacebookOAuthController::class, 'redirect'])->name('oauth.facebook.redirect');
    Route::get('oauth/google-business/redirect', [GoogleBusinessOAuthController::class, 'redirect'])->name('oauth.google-business.redirect');
    Route::get('oauth/instagram/redirect', [InstagramOAuthController::class, 'redirect'])->name('oauth.instagram.redirect');
});

Route::get('oauth/facebook/callback', [FacebookOAuthController::class, 'callback'])->name('oauth.facebook.callback');
Route::get('oauth/google-business/callback', [GoogleBusinessOAuthController::class, 'callback'])->name('oauth.google-business.callback');
Route::get('oauth/instagram/callback', [InstagramOAuthController::class, 'callback'])->name('oauth.instagram.callback');

require __DIR__.'/settings.php';
