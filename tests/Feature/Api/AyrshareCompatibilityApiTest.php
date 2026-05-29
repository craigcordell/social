<?php

use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\Adapters\SocialPlatformAdapter;
use App\Services\Social\SocialPlatformManager;

function bindAyrshareCompatibilityManager(SocialPlatformAdapter $adapter, array $supportedProviders = ['facebook']): void
{
    app()->bind(SocialPlatformManager::class, fn () => new class($adapter, $supportedProviders) extends SocialPlatformManager
    {
        public function __construct(private SocialPlatformAdapter $adapter, private array $supportedProviders) {}

        public function adapter(string $provider): SocialPlatformAdapter
        {
            return $this->adapter;
        }

        public function supportedProviders(): array
        {
            return $this->supportedProviders;
        }
    });
}

function ayrshareHeaders(Owner $owner): array
{
    $token = User::factory()->create()->createToken('POS2024');
    $token->accessToken->forceFill(['owner_id' => $owner->id])->save();

    return [
        'Authorization' => 'Bearer '.$token->plainTextToken,
        'Accept' => 'application/json',
    ];
}

function ayrshareAccount(Owner $owner, string $provider = 'facebook'): ConnectedAccount
{
    return ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => $provider,
        'provider_account_id' => "{$provider}-account-1",
        'provider_account_type' => $provider === 'facebook' ? 'page' : 'instagram_business',
        'display_name' => ucfirst($provider),
        'access_token' => 'secret',
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]);
}

function ayrshareFakeAdapter(): SocialPlatformAdapter
{
    return new class implements SocialPlatformAdapter
    {
        public function publish(ConnectedAccount $account, SocialPost $post): array
        {
            return [
                'provider_post_id' => $account->provider_account_id.'_post-1',
                'provider_media_id' => 'media-1',
                'provider_post_url' => "https://example.com/{$account->provider}/post-1",
                'provider_response' => ['ok' => true],
            ];
        }

        public function delete(ConnectedAccount $account, SocialPostTarget $target): array
        {
            return ['success' => true];
        }

        public function comment(ConnectedAccount $account, SocialPostTarget $target, string $comment): array
        {
            return ['id' => 'comment-1', 'message' => $comment];
        }

        public function postAnalytics(ConnectedAccount $account, string $providerPostId): array
        {
            return [
                'id' => $providerPostId,
                'postUrl' => "https://example.com/{$account->provider}/post-1",
                'analytics' => [
                    'likeCount' => 4,
                    'sharesCount' => 3,
                    'commentsCount' => 2,
                    'reactions' => ['total' => 1],
                ],
            ];
        }

        public function accountAnalytics(ConnectedAccount $account): array
        {
            return [
                'id' => $account->provider_account_id,
                'name' => $account->display_name,
                'followersCount' => 10,
                'pagePostEngagements' => 5,
                'pagePostsImpressions' => 20,
            ];
        }
    };
}

it('requires an owner-bound bearer token for ayrshare-compatible routes', function (): void {
    $token = User::factory()->create()->createToken('Unassigned');

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token->plainTextToken,
        'Accept' => 'application/json',
    ])->postJson('/api/post', [
        'post' => 'New item',
        'platforms' => ['facebook'],
        'mediaUrls' => ['https://example.com/item.jpg'],
    ])->assertForbidden();
});

it('publishes synchronously and returns an ayrshare-shaped success response', function (): void {
    bindAyrshareCompatibilityManager(ayrshareFakeAdapter());

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    ayrshareAccount($owner);

    $response = $this->withHeaders(ayrshareHeaders($owner))->postJson('/api/post', [
        'post' => 'New item',
        'platforms' => ['facebook'],
        'mediaUrls' => ['https://example.com/item.jpg'],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('postIds.0.platform', 'facebook')
        ->assertJsonPath('postIds.0.id', 'facebook-account-1_post-1')
        ->assertJsonPath('postIds.0.postUrl', 'https://example.com/facebook/post-1')
        ->assertJsonPath('errors', []);

    $target = SocialPostTarget::query()->firstOrFail();

    expect($target->publish_status)->toBe(SocialPostTarget::PUBLISH_STATUS_PUBLISHED)
        ->and($target->provider_post_url)->toBe('https://example.com/facebook/post-1');
});

it('returns partial failures for unsupported platforms while preserving successful post ids', function (): void {
    bindAyrshareCompatibilityManager(ayrshareFakeAdapter());

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    ayrshareAccount($owner);

    $this->withHeaders(ayrshareHeaders($owner))->postJson('/api/post', [
        'post' => 'New item',
        'platforms' => ['facebook', 'twitter'],
        'mediaUrls' => ['https://example.com/item.jpg'],
    ])
        ->assertOk()
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('postIds.0.platform', 'facebook')
        ->assertJsonPath('errors.0.platform', 'twitter')
        ->assertJsonPath('errors.0.action', 'post');
});

it('does not use connected accounts from another token owner', function (): void {
    bindAyrshareCompatibilityManager(ayrshareFakeAdapter());

    $tokenOwner = Owner::query()->create(['name' => 'Token owner', 'type' => 'internal']);
    $otherOwner = Owner::query()->create(['name' => 'Other owner', 'type' => 'vendor']);
    ayrshareAccount($otherOwner);

    $this->withHeaders(ayrshareHeaders($tokenOwner))->postJson('/api/post', [
        'post' => 'New item',
        'platforms' => ['facebook'],
        'mediaUrls' => ['https://example.com/item.jpg'],
    ])
        ->assertOk()
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('errors.0.message', 'Facebook is not linked.');

    expect(SocialPostTarget::query()->count())->toBe(0);
});

it('requires at least one usable media url', function (): void {
    bindAyrshareCompatibilityManager(ayrshareFakeAdapter());

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);

    $this->withHeaders(ayrshareHeaders($owner))->postJson('/api/post', [
        'post' => 'New item',
        'platforms' => ['facebook'],
        'mediaUrls' => [null],
    ])->assertUnprocessable();
});

it('deletes, comments, and returns post info for an ayrshare group post id', function (): void {
    bindAyrshareCompatibilityManager(ayrshareFakeAdapter());

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = ayrshareAccount($owner);
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
        'provider_post_id' => 'facebook-account-1_post-1',
        'provider_post_url' => 'https://example.com/facebook/post-1',
    ]);

    $this->withHeaders(ayrshareHeaders($owner))
        ->getJson("/api/post/{$post->id}")
        ->assertOk()
        ->assertJsonPath('postIds.0.postUrl', 'https://example.com/facebook/post-1');

    $this->withHeaders(ayrshareHeaders($owner))->postJson('/api/comments', [
        'id' => (string) $post->id,
        'comment' => 'Sorry, this item sold.',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('comments.0.id', 'comment-1');

    $this->withHeaders(ayrshareHeaders($owner))->deleteJson('/api/post', [
        'id' => (string) $post->id,
    ])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect($target->fresh()->delete_status)->toBe(SocialPostTarget::DELETE_STATUS_DELETED);
});

it('returns post and account analytics in the ayrshare-compatible shape', function (): void {
    bindAyrshareCompatibilityManager(ayrshareFakeAdapter());

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    $account = ayrshareAccount($owner);
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'status' => SocialPost::STATUS_PUBLISHED,
    ]);
    SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'connected_account_id' => $account->id,
        'provider' => 'facebook',
        'publish_status' => SocialPostTarget::PUBLISH_STATUS_PUBLISHED,
        'provider_post_id' => 'facebook-account-1_post-1',
    ]);

    $this->withHeaders(ayrshareHeaders($owner))->postJson('/api/analytics/post', [
        'id' => (string) $post->id,
    ])
        ->assertOk()
        ->assertJsonPath('facebook.analytics.likeCount', 4)
        ->assertJsonPath('facebook.analytics.reactions.total', 1);

    $this->withHeaders(ayrshareHeaders($owner))->postJson('/api/analytics/post', [
        'platforms' => ['facebook'],
        'id' => 'native-post-1',
        'searchPlatformId' => true,
    ])
        ->assertOk()
        ->assertJsonPath('facebook.id', 'native-post-1');

    $this->withHeaders(ayrshareHeaders($owner))->postJson('/api/analytics/social', [
        'platforms' => ['facebook', 'pinterest'],
        'quarters' => 1,
    ])
        ->assertOk()
        ->assertJsonPath('facebook.analytics.followersCount', 10)
        ->assertJsonPath('errors.0.platform', 'pinterest');
});
