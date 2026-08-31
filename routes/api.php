<?php

use App\Http\Controllers\Api\AyrshareCompatibilityController;
use App\Http\Controllers\Api\ConnectedAccountController;
use App\Http\Controllers\Api\Meta\MetaMarketingMutationController;
use App\Http\Controllers\Api\Meta\MetaMarketingReadController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::middleware('abilities:social:publish')->group(function (): void {
        Route::post('post', [AyrshareCompatibilityController::class, 'store']);
        Route::delete('post', [AyrshareCompatibilityController::class, 'destroy']);
        Route::get('post/{id}', [AyrshareCompatibilityController::class, 'show']);
        Route::post('comments', [AyrshareCompatibilityController::class, 'comments']);
        Route::post('analytics/post', [AyrshareCompatibilityController::class, 'postAnalytics']);
        Route::post('analytics/social', [AyrshareCompatibilityController::class, 'socialAnalytics']);
        Route::get('connected-accounts', ConnectedAccountController::class);
    });

    Route::prefix('v1/meta')->group(function (): void {
        Route::middleware('abilities:ads:read')->group(function (): void {
            Route::get('status', [MetaMarketingReadController::class, 'status']);
            Route::get('budget-status', [MetaMarketingReadController::class, 'budgetStatus']);
            Route::get('campaigns', [MetaMarketingReadController::class, 'campaigns']);
            Route::get('insights', [MetaMarketingReadController::class, 'insights']);
            Route::get('ads', [MetaMarketingReadController::class, 'ads']);
            Route::post('ads/resolve', [MetaMarketingReadController::class, 'resolveAd']);
            Route::get('ads/{adId}', [MetaMarketingReadController::class, 'ad'])->whereNumber('adId');
            Route::get('ads/{adId}/insights', [MetaMarketingReadController::class, 'adInsights'])->whereNumber('adId');
        });

        Route::post('organic-insights', [MetaMarketingReadController::class, 'organicInsights'])
            ->middleware('abilities:organic:read');

        Route::middleware(['abilities:ads:manage', 'throttle:meta-mutations'])->group(function (): void {
            Route::post('boosts', [MetaMarketingMutationController::class, 'createBoost']);
            Route::post('ads/pause-by-posts', [MetaMarketingMutationController::class, 'pauseAdsByPosts']);
            Route::patch('campaigns/{campaignId}/budget', [MetaMarketingMutationController::class, 'increaseCampaignBudget'])
                ->whereNumber('campaignId');
            Route::patch('ads/{adId}/status', [MetaMarketingMutationController::class, 'updateAdStatus'])->whereNumber('adId');
            Route::patch('ads/{adId}/budget', [MetaMarketingMutationController::class, 'increaseAdBudget'])->whereNumber('adId');
        });
    });
});
