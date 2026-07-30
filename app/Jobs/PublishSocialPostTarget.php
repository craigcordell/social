<?php

namespace App\Jobs;

use App\Models\SocialPostTarget;
use App\Services\Social\SocialPostTargetPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use RuntimeException;

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

    public function handle(SocialPostTargetPublisher $publisher): void
    {
        $result = $publisher->publish($this->targetId);
        $target = SocialPostTarget::query()->with('socialPost')->findOrFail($this->targetId);

        $target->socialPost->refreshAggregateStatus();

        if (! $result['successful']) {
            throw new RuntimeException($result['error'] ?? 'Social post publishing failed.');
        }
    }
}
