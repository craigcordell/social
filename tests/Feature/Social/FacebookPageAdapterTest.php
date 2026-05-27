<?php

use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\SocialPost;
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
