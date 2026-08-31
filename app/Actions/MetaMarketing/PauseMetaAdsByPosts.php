<?php

namespace App\Actions\MetaMarketing;

use App\Models\Owner;
use App\Services\MetaMarketing\MetaPostAdFinder;
use Throwable;

class PauseMetaAdsByPosts
{
    public function __construct(
        private readonly MetaPostAdFinder $finder,
        private readonly UpdateMetaAdStatus $updateAdStatus,
    ) {}

    /**
     * @param  array{idempotency_key: string, posts: list<array{client_reference?: ?string, platform: string, post_url: string}>}  $data
     * @return array<string, mixed>
     */
    public function execute(Owner $owner, array $data): array
    {
        $matches = $this->finder->find($owner, $data['posts']);
        $ads = [];
        $errors = [];

        foreach ($matches['ad_ids'] as $adId) {
            try {
                $ads[] = $this->updateAdStatus->execute($owner, $adId, [
                    'status' => 'PAUSED',
                    'idempotency_key' => $this->adIdempotencyKey($data['idempotency_key'], $adId),
                ]);
            } catch (Throwable $exception) {
                report($exception);
                $errors[] = [
                    'ad_id' => $adId,
                    'message' => 'Meta could not pause this ad.',
                ];
            }
        }

        $matchedPosts = collect($matches['posts'])
            ->filter(static fn (array $post): bool => $post['matched_ad_ids'] !== [])
            ->count();

        return [
            'complete' => $errors === [],
            'summary' => [
                'submitted_posts' => count($matches['posts']),
                'matched_posts' => $matchedPosts,
                'unmatched_posts' => count($matches['posts']) - $matchedPosts,
                'matched_ads' => count($matches['ad_ids']),
                'successful_ads' => count($ads),
                'failed_ads' => count($errors),
            ],
            'posts' => $matches['posts'],
            'ads' => $ads,
            'errors' => $errors,
        ];
    }

    protected function adIdempotencyKey(string $batchKey, string $adId): string
    {
        return 'post-pause:'.hash('sha256', $batchKey.':'.$adId);
    }
}
