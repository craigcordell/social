<?php

use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Social\Adapters\GoogleBusinessProfileAdapter;
use Illuminate\Support\Facades\Http;

it('publishes a standard google business local post with photo media and optional cta', function (): void {
    Http::fake([
        'mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts' => Http::response([
            'name' => 'accounts/123/locations/456/localPosts/post-1',
            'searchUrl' => 'https://business.google.com/posts/post-1',
        ]),
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = googleBusinessAccount($owner);
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'link_url' => 'https://example.com/item',
        'status' => SocialPost::STATUS_QUEUED,
    ]);

    $result = app(GoogleBusinessProfileAdapter::class)->publish($account, $post);

    expect($result['provider_post_id'])->toBe('accounts/123/locations/456/localPosts/post-1')
        ->and($result['provider_post_url'])->toBe('https://business.google.com/posts/post-1');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts'
        && $request->hasHeader('Authorization', 'Bearer google-access-token')
        && $request->data()['languageCode'] === 'en-US'
        && $request->data()['summary'] === 'New item'
        && $request->data()['topicType'] === 'STANDARD'
        && $request->data()['media'][0]['mediaFormat'] === 'PHOTO'
        && $request->data()['media'][0]['sourceUrl'] === 'https://example.com/item.jpg'
        && $request->data()['callToAction']['actionType'] === 'LEARN_MORE'
        && $request->data()['callToAction']['url'] === 'https://example.com/item');
});

it('deletes a google business local post and rejects comments', function (): void {
    Http::fake([
        'mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts/post-1' => Http::response([]),
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = googleBusinessAccount($owner);
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'status' => SocialPost::STATUS_PUBLISHED,
    ]);
    $target = SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'connected_account_id' => $account->id,
        'provider' => 'gmb',
        'publish_status' => SocialPostTarget::PUBLISH_STATUS_PUBLISHED,
        'provider_post_id' => 'accounts/123/locations/456/localPosts/post-1',
    ]);

    $adapter = app(GoogleBusinessProfileAdapter::class);

    expect($adapter->delete($account, $target))->toBe(['success' => true]);

    expect(fn () => $adapter->comment($account, $target, 'Sold'))
        ->toThrow(RuntimeException::class, 'do not support comments');
});

it('reads google business local post and account analytics', function (): void {
    Http::fake([
        'mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts:reportInsights' => Http::response([
            'localPostMetrics' => [
                [
                    'localPostName' => 'accounts/123/locations/456/localPosts/post-1',
                    'localPost' => [
                        'searchUrl' => 'https://business.google.com/posts/post-1',
                    ],
                    'metricValues' => [
                        [
                            'metric' => 'LOCAL_POST_VIEWS_SEARCH',
                            'totalValue' => ['value' => '12'],
                        ],
                        [
                            'metric' => 'LOCAL_POST_ACTIONS_CALL_TO_ACTION',
                            'totalValue' => ['value' => '3'],
                        ],
                    ],
                ],
            ],
        ]),
        'businessprofileperformance.googleapis.com/v1/accounts/123/locations/456:fetchMultiDailyMetricsTimeSeries*' => Http::response([
            'multiDailyMetricTimeSeries' => [
                [
                    'dailyMetricTimeSeries' => [
                        [
                            'dailyMetric' => 'CALL_CLICKS',
                            'timeSeries' => [
                                'datedValues' => [
                                    ['value' => '2'],
                                    ['value' => '4'],
                                ],
                            ],
                        ],
                        [
                            'dailyMetric' => 'WEBSITE_CLICKS',
                            'timeSeries' => [
                                'datedValues' => [
                                    ['value' => '7'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = googleBusinessAccount($owner);
    $adapter = app(GoogleBusinessProfileAdapter::class);

    expect($adapter->postAnalytics($account, 'accounts/123/locations/456/localPosts/post-1')['analytics']['viewsCount'])->toBe(12)
        ->and($adapter->postAnalytics($account, 'accounts/123/locations/456/localPosts/post-1')['analytics']['callToActionCount'])->toBe(3)
        ->and($adapter->accountAnalytics($account)['callClicks'])->toBe(6)
        ->and($adapter->accountAnalytics($account)['websiteClicks'])->toBe(7);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'dailyMetrics=BUSINESS_IMPRESSIONS_DESKTOP_MAPS')
        && str_contains($request->url(), 'dailyMetrics=CALL_CLICKS')
        && ! str_contains($request->url(), 'dailyMetrics%5B0%5D'));
});

it('refreshes expired google business tokens before calling the api', function (): void {
    config([
        'services.google_business.client_id' => 'google-client-123',
        'services.google_business.client_secret' => 'google-secret-123',
    ]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'fresh-google-access-token',
            'expires_in' => 3600,
        ]),
        'mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts' => Http::response([
            'name' => 'accounts/123/locations/456/localPosts/post-1',
        ]),
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = googleBusinessAccount($owner, [
        'access_token' => 'expired-google-access-token',
        'refresh_token' => 'google-refresh-token',
        'token_expires_at' => now()->subMinute(),
    ]);
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'status' => SocialPost::STATUS_QUEUED,
    ]);

    app(GoogleBusinessProfileAdapter::class)->publish($account, $post);

    expect($account->fresh()->access_token)->toBe('fresh-google-access-token');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request->data()['grant_type'] === 'refresh_token'
        && $request->data()['refresh_token'] === 'google-refresh-token');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function googleBusinessAccount(Owner $owner, array $overrides = []): ConnectedAccount
{
    return ConnectedAccount::query()->create(array_merge([
        'owner_id' => $owner->id,
        'provider' => 'gmb',
        'provider_account_id' => 'accounts/123/locations/456',
        'provider_account_type' => 'google_business_location',
        'display_name' => 'Clayton House',
        'access_token' => 'google-access-token',
        'refresh_token' => 'google-refresh-token',
        'token_expires_at' => now()->addHour(),
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ], $overrides));
}
