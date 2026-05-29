<?php

use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Social\Adapters\FacebookPageAdapter;
use Illuminate\Support\Facades\Http;

it('publishes a page photo using the configured graph version', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/page-1/photos' => Http::response([
            'id' => 'photo-1',
            'post_id' => 'page-1_post-1',
        ]),
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'facebook',
        'provider_account_id' => 'page-1',
        'provider_account_type' => 'page',
        'display_name' => 'Clayton House',
        'access_token' => 'page-token',
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]);
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'link_url' => 'https://example.com/item',
        'status' => SocialPost::STATUS_QUEUED,
    ]);

    $result = app(FacebookPageAdapter::class)->publish($account, $post);

    expect($result['provider_post_id'])->toBe('page-1_post-1')
        ->and($result['provider_media_id'])->toBe('photo-1');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer page-token')
        && $request->data()['url'] === 'https://example.com/item.jpg'
        && str_contains($request->data()['caption'], 'https://example.com/item'));
});

it('recovers a created page post after an ambiguous facebook server error', function (): void {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/v25.0/page-1/photos')) {
            return Http::response([
                'error' => [
                    'message' => "Please reduce the amount of data you're asking for, then retry your request",
                    'type' => 'OAuthException',
                ],
            ], 500);
        }

        if (str_contains($request->url(), '/v25.0/page-1/posts')) {
            return Http::response([
                'data' => [
                    [
                        'id' => 'page-1_post-1',
                        'message' => "New item\n\nhttps://example.com/item",
                        'created_time' => now()->toIso8601String(),
                        'attachments' => [
                            'data' => [
                                [
                                    'target' => [
                                        'id' => 'photo-1',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        }

        return Http::response([
            'error' => [
                'message' => 'Unexpected request.',
            ],
        ], 500);
    });

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'facebook',
        'provider_account_id' => 'page-1',
        'provider_account_type' => 'page',
        'display_name' => 'Clayton House',
        'access_token' => 'page-token',
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]);
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'link_url' => 'https://example.com/item',
        'status' => SocialPost::STATUS_QUEUED,
    ]);

    $result = app(FacebookPageAdapter::class)->publish($account, $post);

    expect($result['provider_post_id'])->toBe('page-1_post-1')
        ->and($result['provider_media_id'])->toBe('photo-1')
        ->and($result['provider_response']['recovered_after_ambiguous_publish_failure'])->toBeTrue();

    Http::assertSentCount(2);
});

it('comments and reads facebook post and account analytics', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/page-1_post-1/comments' => Http::response([
            'id' => 'comment-1',
        ]),
        'graph.facebook.com/v25.0/page-1_post-1*' => Http::response([
            'id' => 'page-1_post-1',
            'permalink_url' => 'https://www.facebook.com/page-1_post-1',
            'likes' => ['summary' => ['total_count' => 4]],
            'comments' => ['summary' => ['total_count' => 3]],
            'shares' => ['count' => 2],
            'reactions' => ['summary' => ['total_count' => 5]],
        ]),
        'graph.facebook.com/v25.0/page-1*' => Http::response([
            'id' => 'page-1',
            'name' => 'Clayton House',
            'followers_count' => 10,
        ]),
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'facebook',
        'provider_account_id' => 'page-1',
        'provider_account_type' => 'page',
        'display_name' => 'Clayton House',
        'access_token' => 'page-token',
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]);
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'status' => SocialPost::STATUS_PUBLISHED,
    ]);
    $target = SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'connected_account_id' => $account->id,
        'provider' => 'facebook',
        'publish_status' => SocialPostTarget::PUBLISH_STATUS_PUBLISHED,
        'provider_post_id' => 'page-1_post-1',
    ]);

    $adapter = app(FacebookPageAdapter::class);

    expect($adapter->comment($account, $target, 'Sold')['id'])->toBe('comment-1')
        ->and($adapter->postAnalytics($account, 'page-1_post-1')['analytics']['likeCount'])->toBe(4)
        ->and($adapter->postAnalytics($account, 'page-1_post-1')['analytics']['reactions']['total'])->toBe(5)
        ->and($adapter->accountAnalytics($account)['followersCount'])->toBe(10);
});
