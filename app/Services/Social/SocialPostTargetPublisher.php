<?php

namespace App\Services\Social;

use App\Models\SocialPostTarget;
use Throwable;

class SocialPostTargetPublisher
{
    public function __construct(private readonly SocialPlatformManager $platforms) {}

    /**
     * @return array{successful: bool, target_id: int, error: string|null}
     */
    public function publish(int $targetId): array
    {
        $target = null;

        try {
            $target = SocialPostTarget::query()
                ->with(['socialPost', 'connectedAccount'])
                ->findOrFail($targetId);

            if ($target->publish_status === SocialPostTarget::PUBLISH_STATUS_PUBLISHED) {
                return [
                    'successful' => true,
                    'target_id' => $target->id,
                    'error' => null,
                ];
            }

            $target->forceFill([
                'publish_status' => SocialPostTarget::PUBLISH_STATUS_PUBLISHING,
                'publish_attempts' => $target->publish_attempts + 1,
                'last_error' => null,
            ])->save();

            $result = $this->platforms->adapter($target->provider)
                ->publish($target->connectedAccount, $target->socialPost);

            $target->forceFill([
                'publish_status' => SocialPostTarget::PUBLISH_STATUS_PUBLISHED,
                'provider_post_id' => $result['provider_post_id'],
                'provider_media_id' => $result['provider_media_id'] ?? null,
                'provider_post_url' => $result['provider_post_url'] ?? null,
                'provider_response' => $result['provider_response'],
                'published_at' => now(),
                'last_error' => null,
            ])->save();

            return [
                'successful' => true,
                'target_id' => $target->id,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            $target?->forceFill([
                'publish_status' => SocialPostTarget::PUBLISH_STATUS_FAILED,
                'last_error' => $exception->getMessage(),
            ])->save();

            return [
                'successful' => false,
                'target_id' => $targetId,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
