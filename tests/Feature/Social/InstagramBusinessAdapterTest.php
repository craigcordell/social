<?php

use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Social\Adapters\InstagramBusinessAdapter;
use Illuminate\Support\Facades\Http;

it('publishes an instagram feed image through a media container', function (): void {
    Http::fake([
        'graph.instagram.com/v25.0/ig-user-1/media' => Http::response([
            'id' => 'container-1',
        ]),
        'graph.instagram.com/v25.0/container-1*' => Http::response([
            'status_code' => 'FINISHED',
        ]),
        'graph.instagram.com/v25.0/ig-user-1/media_publish' => Http::response([
            'id' => 'ig-media-1',
        ]),
        'graph.instagram.com/v25.0/ig-media-1*' => Http::response([
            'id' => 'ig-media-1',
            'permalink' => 'https://www.instagram.com/p/example/',
            'like_count' => 0,
            'comments_count' => 0,
        ]),
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'instagram',
        'provider_account_id' => 'ig-user-1',
        'provider_account_type' => 'instagram_business',
        'display_name' => 'Clayton House Instagram',
        'access_token' => 'ig-token',
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]);
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'link_url' => 'https://example.com/item',
        'status' => SocialPost::STATUS_QUEUED,
    ]);

    $result = app(InstagramBusinessAdapter::class)->publish($account, $post);

    expect($result['provider_post_id'])->toBe('ig-media-1')
        ->and($result['provider_media_id'])->toBe('container-1')
        ->and($result['provider_post_url'])->toBe('https://www.instagram.com/p/example/')
        ->and($result['provider_response']['container']['id'])->toBe('container-1')
        ->and($result['provider_response']['published']['id'])->toBe('ig-media-1');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://graph.instagram.com/v25.0/ig-user-1/media'
        && $request->hasHeader('Authorization', 'Bearer ig-token')
        && $request->data()['image_url'] === 'https://example.com/item.jpg'
        && str_contains($request->data()['caption'], 'https://example.com/item'));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://graph.instagram.com/v25.0/container-1?fields=status_code');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://graph.instagram.com/v25.0/ig-user-1/media_publish'
        && $request->data()['creation_id'] === 'container-1');
});

it('waits for instagram media containers before publishing', function (): void {
    Http::fake([
        'graph.instagram.com/v25.0/ig-user-1/media' => Http::response([
            'id' => 'container-1',
        ]),
        'graph.instagram.com/v25.0/container-1*' => Http::sequence()
            ->push(['status_code' => 'IN_PROGRESS'])
            ->push(['status_code' => 'FINISHED']),
        'graph.instagram.com/v25.0/ig-user-1/media_publish' => Http::response([
            'id' => 'ig-media-1',
        ]),
        'graph.instagram.com/v25.0/ig-media-1*' => Http::response([
            'id' => 'ig-media-1',
            'permalink' => 'https://www.instagram.com/p/example/',
            'like_count' => 0,
            'comments_count' => 0,
        ]),
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'instagram',
        'provider_account_id' => 'ig-user-1',
        'provider_account_type' => 'instagram_business',
        'display_name' => 'Clayton House Instagram',
        'access_token' => 'ig-token',
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]);
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'status' => SocialPost::STATUS_QUEUED,
    ]);

    $result = app(InstagramBusinessAdapter::class)->publish($account, $post);

    expect($result['provider_post_id'])->toBe('ig-media-1')
        ->and($result['provider_response']['container_status']['status_code'])->toBe('FINISHED');

    Http::assertSentCount(5);
});

it('marks instagram media deletes as manual delete required', function (): void {
    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'instagram',
        'provider_account_id' => 'ig-user-1',
        'provider_account_type' => 'instagram_business',
        'display_name' => 'Clayton House Instagram',
        'access_token' => 'ig-token',
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]);
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'status' => SocialPost::STATUS_PUBLISHED,
    ]);
    $target = $post->targets()->create([
        'connected_account_id' => $account->id,
        'provider' => 'instagram',
        'publish_status' => 'published',
        'provider_post_id' => 'ig-media-1',
    ]);

    $result = app(InstagramBusinessAdapter::class)->delete($account, $target);

    expect($result['manual_delete_required'])->toBeTrue()
        ->and($result['provider_post_id'])->toBe('ig-media-1')
        ->and($result['message'])->toContain('Delete this post manually');

    Http::assertNothingSent();
});

it('comments and reads instagram media and account analytics', function (): void {
    Http::fake([
        'graph.instagram.com/v25.0/ig-media-1/comments' => Http::response([
            'id' => 'comment-1',
        ]),
        'graph.instagram.com/v25.0/ig-media-1*' => Http::response([
            'id' => 'ig-media-1',
            'permalink' => 'https://www.instagram.com/p/example/',
            'like_count' => 4,
            'comments_count' => 3,
        ]),
        'graph.instagram.com/v25.0/ig-user-1*' => Http::response([
            'id' => 'ig-user-1',
            'username' => 'claytonhouse',
            'followers_count' => 10,
            'media_count' => 20,
        ]),
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'instagram',
        'provider_account_id' => 'ig-user-1',
        'provider_account_type' => 'instagram_business',
        'display_name' => 'Clayton House Instagram',
        'access_token' => 'ig-token',
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
        'provider' => 'instagram',
        'publish_status' => SocialPostTarget::PUBLISH_STATUS_PUBLISHED,
        'provider_post_id' => 'ig-media-1',
    ]);

    $adapter = app(InstagramBusinessAdapter::class);

    expect($adapter->comment($account, $target, 'Sold')['id'])->toBe('comment-1')
        ->and($adapter->postAnalytics($account, 'ig-media-1')['analytics']['likeCount'])->toBe(4)
        ->and($adapter->postAnalytics($account, 'ig-media-1')['analytics']['commentsCount'])->toBe(3)
        ->and($adapter->accountAnalytics($account)['followersCount'])->toBe(10);
});
