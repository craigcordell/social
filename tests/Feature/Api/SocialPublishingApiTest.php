<?php

use App\Jobs\DeleteSocialPostTarget;
use App\Jobs\PublishSocialPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

it('requires sanctum authentication for social api routes', function (): void {
    $this->getJson('/api/connected-accounts')->assertUnauthorized();
});

it('lists active connected accounts', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'facebook',
        'provider_account_id' => 'page-1',
        'provider_account_type' => 'page',
        'display_name' => 'Clayton House',
        'access_token' => 'secret',
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]);

    $this->getJson('/api/connected-accounts')
        ->assertOk()
        ->assertJsonPath('data.0.display_name', 'Clayton House')
        ->assertJsonPath('data.0.provider', 'facebook');
});

it('queues a scheduled post for explicit targets', function (): void {
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'facebook',
        'provider_account_id' => 'page-1',
        'provider_account_type' => 'page',
        'display_name' => 'Clayton House',
        'access_token' => 'secret',
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]);

    $response = $this->postJson('/api/posts', [
        'owner_id' => $owner->id,
        'target_ids' => [$account->id],
        'caption' => 'New item in the shop',
        'image_url' => 'https://example.com/item.jpg',
        'scheduled_at' => now()->addHour()->toJSON(),
        'idempotency_key' => 'item-123',
    ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('data.status', SocialPost::STATUS_SCHEDULED)
        ->assertJsonPath('data.targets.0.publish_status', SocialPostTarget::PUBLISH_STATUS_QUEUED);

    expect(SocialPost::query()->count())->toBe(1);

    Queue::assertPushed(PublishSocialPostTarget::class, fn (PublishSocialPostTarget $job): bool => $job->queue === 'social-publish');
});

it('returns the existing post for a repeated idempotency key', function (): void {
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'facebook',
        'provider_account_id' => 'page-1',
        'provider_account_type' => 'page',
        'display_name' => 'Clayton House',
        'access_token' => 'secret',
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]);

    $payload = [
        'owner_id' => $owner->id,
        'target_ids' => [$account->id],
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'idempotency_key' => 'item-123',
    ];

    $first = $this->postJson('/api/posts', $payload)->assertAccepted()->json('data.id');
    $second = $this->postJson('/api/posts', $payload)->assertOk()->json('data.id');

    expect($second)->toBe($first)
        ->and(SocialPost::query()->count())->toBe(1)
        ->and(SocialPostTarget::query()->count())->toBe(1);
});

it('queues deletes for selected post targets', function (): void {
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'status' => SocialPost::STATUS_PUBLISHED,
    ]);

    $accounts = collect([1, 2])->map(fn (int $number) => ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'facebook',
        'provider_account_id' => "page-{$number}",
        'provider_account_type' => 'page',
        'display_name' => "Page {$number}",
        'access_token' => 'secret',
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]));

    $targets = $accounts->map(fn (ConnectedAccount $account) => SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'connected_account_id' => $account->id,
        'provider' => 'facebook',
        'publish_status' => SocialPostTarget::PUBLISH_STATUS_PUBLISHED,
        'provider_post_id' => $account->provider_account_id.'_post',
    ]));

    $this->deleteJson("/api/posts/{$post->id}", [
        'target_ids' => [$targets->first()->id],
    ])->assertAccepted();

    expect($targets->first()->fresh()->delete_status)->toBe(SocialPostTarget::DELETE_STATUS_QUEUED)
        ->and($targets->last()->fresh()->delete_status)->toBeNull();

    Queue::assertPushed(DeleteSocialPostTarget::class, fn (DeleteSocialPostTarget $job): bool => $job->queue === 'social-delete');
});
