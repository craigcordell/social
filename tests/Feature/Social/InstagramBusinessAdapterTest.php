<?php

use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\SocialPost;
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

    Http::assertSentCount(4);
});
