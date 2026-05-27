<?php

namespace App\Jobs;

use App\Models\SocialPostTarget;
use App\Services\Social\SocialPlatformManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class PublishSocialPostTarget implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public int $targetId)
    {
        $this->onQueue('social-publish');
    }

    /**
     * @return array<int, RateLimited>
     */
    public function middleware(): array
    {
        return [(new RateLimited('social-provider'))->releaseAfter(60)];
    }

    public function provider(): string
    {
        return SocialPostTarget::query()
            ->whereKey($this->targetId)
            ->value('provider') ?? 'unknown';
    }

    public function handle(SocialPlatformManager $platforms): void
    {
        $target = SocialPostTarget::query()
            ->with(['socialPost', 'connectedAccount'])
            ->findOrFail($this->targetId);

        if ($target->publish_status === SocialPostTarget::PUBLISH_STATUS_PUBLISHED) {
            return;
        }

        $target->forceFill([
            'publish_status' => SocialPostTarget::PUBLISH_STATUS_PUBLISHING,
            'publish_attempts' => $target->publish_attempts + 1,
            'last_error' => null,
        ])->save();

        $target->socialPost->refreshAggregateStatus();

        try {
            $result = $platforms->adapter($target->provider)
                ->publish($target->connectedAccount, $target->socialPost);

            $target->forceFill([
                'publish_status' => SocialPostTarget::PUBLISH_STATUS_PUBLISHED,
                'provider_post_id' => $result['provider_post_id'],
                'provider_media_id' => $result['provider_media_id'] ?? null,
                'provider_response' => $result['provider_response'],
                'published_at' => now(),
                'last_error' => null,
            ])->save();

            $target->socialPost->refreshAggregateStatus();
        } catch (Throwable $exception) {
            $target->forceFill([
                'publish_status' => SocialPostTarget::PUBLISH_STATUS_FAILED,
                'last_error' => $exception->getMessage(),
            ])->save();

            $target->socialPost->refreshAggregateStatus();

            throw $exception;
        }
    }
}
