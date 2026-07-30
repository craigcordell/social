<?php

use App\Jobs\DeleteSocialPostTarget;
use App\Jobs\PublishSocialPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Social\Adapters\SocialPlatformAdapter;
use App\Services\Social\SocialPlatformManager;
use App\Services\Social\SocialPostTargetPublisher;

function bindSocialAdapter(SocialPlatformAdapter $adapter): void
{
    app()->bind(SocialPlatformManager::class, fn () => new class($adapter) extends SocialPlatformManager
    {
        public function __construct(private SocialPlatformAdapter $adapter) {}

        public function adapter(string $provider): SocialPlatformAdapter
        {
            return $this->adapter;
        }
    });
}

function socialTarget(): SocialPostTarget
{
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
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'New item',
        'image_url' => 'https://example.com/item.jpg',
        'status' => SocialPost::STATUS_QUEUED,
    ]);

    return SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'connected_account_id' => $account->id,
        'provider' => 'facebook',
        'publish_status' => SocialPostTarget::PUBLISH_STATUS_QUEUED,
    ]);
}

it('marks a target published when the adapter succeeds', function (): void {
    bindSocialAdapter(new class implements SocialPlatformAdapter
    {
        public function publish(ConnectedAccount $account, SocialPost $post): array
        {
            return [
                'provider_post_id' => 'page-1_post-1',
                'provider_media_id' => 'photo-1',
                'provider_response' => ['id' => 'photo-1', 'post_id' => 'page-1_post-1'],
            ];
        }

        public function delete(ConnectedAccount $account, SocialPostTarget $target): array
        {
            return ['success' => true];
        }

        public function comment(ConnectedAccount $account, SocialPostTarget $target, string $comment): array
        {
            return ['id' => 'comment-1'];
        }

        public function postAnalytics(ConnectedAccount $account, string $providerPostId): array
        {
            return [];
        }

        public function accountAnalytics(ConnectedAccount $account): array
        {
            return [];
        }
    });

    $target = socialTarget();

    (new PublishSocialPostTarget($target->id))->handle(app(SocialPostTargetPublisher::class));

    expect($target->fresh()->publish_status)->toBe(SocialPostTarget::PUBLISH_STATUS_PUBLISHED)
        ->and($target->fresh()->provider_post_id)->toBe('page-1_post-1')
        ->and($target->socialPost->fresh()->status)->toBe(SocialPost::STATUS_PUBLISHED);
});

it('records publish failure before rethrowing for queue retry', function (): void {
    bindSocialAdapter(new class implements SocialPlatformAdapter
    {
        public function publish(ConnectedAccount $account, SocialPost $post): array
        {
            throw new RuntimeException('Facebook rejected the image URL.');
        }

        public function delete(ConnectedAccount $account, SocialPostTarget $target): array
        {
            return ['success' => true];
        }

        public function comment(ConnectedAccount $account, SocialPostTarget $target, string $comment): array
        {
            return ['id' => 'comment-1'];
        }

        public function postAnalytics(ConnectedAccount $account, string $providerPostId): array
        {
            return [];
        }

        public function accountAnalytics(ConnectedAccount $account): array
        {
            return [];
        }
    });

    $target = socialTarget();

    expect(fn () => (new PublishSocialPostTarget($target->id))->handle(app(SocialPostTargetPublisher::class)))
        ->toThrow(RuntimeException::class);

    expect($target->fresh()->publish_status)->toBe(SocialPostTarget::PUBLISH_STATUS_FAILED)
        ->and($target->fresh()->last_error)->toContain('Facebook rejected');
});

it('marks a published target deleted when the adapter succeeds', function (): void {
    bindSocialAdapter(new class implements SocialPlatformAdapter
    {
        public function publish(ConnectedAccount $account, SocialPost $post): array
        {
            return [];
        }

        public function delete(ConnectedAccount $account, SocialPostTarget $target): array
        {
            return ['success' => true];
        }

        public function comment(ConnectedAccount $account, SocialPostTarget $target, string $comment): array
        {
            return ['id' => 'comment-1'];
        }

        public function postAnalytics(ConnectedAccount $account, string $providerPostId): array
        {
            return [];
        }

        public function accountAnalytics(ConnectedAccount $account): array
        {
            return [];
        }
    });

    $target = socialTarget();
    $target->forceFill([
        'publish_status' => SocialPostTarget::PUBLISH_STATUS_PUBLISHED,
        'provider_post_id' => 'page-1_post-1',
        'delete_status' => SocialPostTarget::DELETE_STATUS_QUEUED,
    ])->save();

    (new DeleteSocialPostTarget($target->id))->handle(app(SocialPlatformManager::class));

    expect($target->fresh()->delete_status)->toBe(SocialPostTarget::DELETE_STATUS_DELETED)
        ->and($target->socialPost->fresh()->status)->toBe(SocialPost::STATUS_DELETED);
});

it('marks a published target as manual delete required without failing the job', function (): void {
    bindSocialAdapter(new class implements SocialPlatformAdapter
    {
        public function publish(ConnectedAccount $account, SocialPost $post): array
        {
            return [];
        }

        public function delete(ConnectedAccount $account, SocialPostTarget $target): array
        {
            return [
                'manual_delete_required' => true,
                'message' => 'Delete this post manually.',
                'provider_post_id' => $target->provider_post_id,
            ];
        }

        public function comment(ConnectedAccount $account, SocialPostTarget $target, string $comment): array
        {
            return ['id' => 'comment-1'];
        }

        public function postAnalytics(ConnectedAccount $account, string $providerPostId): array
        {
            return [];
        }

        public function accountAnalytics(ConnectedAccount $account): array
        {
            return [];
        }
    });

    $target = socialTarget();
    $target->forceFill([
        'publish_status' => SocialPostTarget::PUBLISH_STATUS_PUBLISHED,
        'provider_post_id' => 'ig-media-1',
        'delete_status' => SocialPostTarget::DELETE_STATUS_QUEUED,
    ])->save();

    (new DeleteSocialPostTarget($target->id))->handle(app(SocialPlatformManager::class));

    expect($target->fresh()->delete_status)->toBe(SocialPostTarget::DELETE_STATUS_MANUAL_REQUIRED)
        ->and($target->fresh()->delete_attempts)->toBe(1)
        ->and($target->fresh()->last_error)->toContain('Delete this post manually')
        ->and($target->fresh()->provider_response['delete']['manual_delete_required'])->toBeTrue()
        ->and($target->socialPost->fresh()->status)->toBe(SocialPost::STATUS_PUBLISHED);
});
