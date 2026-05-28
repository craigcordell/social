<?php

namespace App\Jobs;

use App\Models\SocialPostTarget;
use App\Services\Social\SocialPlatformManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class DeleteSocialPostTarget implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public int $targetId)
    {
        $this->onQueue('social-delete');
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

        if ($target->delete_status === SocialPostTarget::DELETE_STATUS_DELETED) {
            return;
        }

        if (! $target->provider_post_id) {
            $target->forceFill([
                'delete_status' => SocialPostTarget::DELETE_STATUS_DELETED,
                'deleted_at' => now(),
                'last_error' => null,
            ])->save();

            $target->socialPost->refreshAggregateStatus();

            return;
        }

        $target->forceFill([
            'delete_status' => SocialPostTarget::DELETE_STATUS_DELETING,
            'delete_attempts' => $target->delete_attempts + 1,
            'last_error' => null,
        ])->save();

        $target->socialPost->refreshAggregateStatus();

        try {
            $response = $platforms->adapter($target->provider)
                ->delete($target->connectedAccount, $target);

            if ($response['manual_delete_required'] ?? false) {
                $target->forceFill([
                    'delete_status' => SocialPostTarget::DELETE_STATUS_MANUAL_REQUIRED,
                    'provider_response' => array_merge($target->provider_response ?? [], ['delete' => $response]),
                    'last_error' => $response['message'] ?? null,
                ])->save();

                $target->socialPost->refreshAggregateStatus();

                return;
            }

            $target->forceFill([
                'delete_status' => SocialPostTarget::DELETE_STATUS_DELETED,
                'provider_response' => array_merge($target->provider_response ?? [], ['delete' => $response]),
                'deleted_at' => now(),
                'last_error' => null,
            ])->save();

            $target->socialPost->refreshAggregateStatus();
        } catch (Throwable $exception) {
            $target->forceFill([
                'delete_status' => SocialPostTarget::DELETE_STATUS_FAILED,
                'last_error' => $exception->getMessage(),
            ])->save();

            $target->socialPost->refreshAggregateStatus();

            throw $exception;
        }
    }
}
