<?php

namespace App\Services\Social\Adapters;

use App\Models\ConnectedAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class FacebookPageAdapter implements SocialPlatformAdapter
{
    public function publish(ConnectedAccount $account, SocialPost $post): array
    {
        $publishedAfter = now()->subMinutes(2);
        $caption = $this->caption($post);

        try {
            $response = $this->graph($account)->post($this->endpoint($account->provider_account_id.'/photos'), array_filter([
                'url' => $post->image_url,
                'caption' => $caption,
            ]))->throw()->json();
        } catch (RequestException $exception) {
            if (! $this->isAmbiguousPublishFailure($exception)) {
                throw $exception;
            }

            try {
                $recovered = $this->recoverPublishedPost($account, $post, $caption, $publishedAfter);
            } catch (Throwable) {
                throw $exception;
            }

            if ($recovered === null) {
                throw $exception;
            }

            return $recovered;
        }

        return [
            'provider_post_id' => $response['post_id'] ?? $response['id'],
            'provider_media_id' => $response['id'] ?? null,
            'provider_post_url' => $this->postUrl($response['post_id'] ?? $response['id']),
            'provider_response' => $response,
        ];
    }

    public function delete(ConnectedAccount $account, SocialPostTarget $target): array
    {
        $response = $this->graph($account)
            ->delete($this->endpoint($target->provider_post_id))
            ->throw()
            ->json();

        return $response ?: ['success' => true];
    }

    protected function caption(SocialPost $post): string
    {
        return trim($post->caption.($post->link_url ? "\n\n{$post->link_url}" : ''));
    }

    protected function isAmbiguousPublishFailure(RequestException $exception): bool
    {
        return $exception->response->serverError();
    }

    /**
     * @return array{provider_post_id: string, provider_media_id?: string|null, provider_response: array<string, mixed>}|null
     */
    protected function recoverPublishedPost(ConnectedAccount $account, SocialPost $post, string $caption, CarbonInterface $publishedAfter): ?array
    {
        $response = $this->graph($account)
            ->get($this->endpoint($account->provider_account_id.'/posts'), [
                'fields' => 'id,message,created_time,attachments{target}',
                'limit' => 10,
            ])
            ->throw()
            ->json();

        foreach ($response['data'] ?? [] as $candidate) {
            if (($candidate['message'] ?? '') !== $caption) {
                continue;
            }

            if (! $this->wasCreatedAfter($candidate['created_time'] ?? null, $publishedAfter)) {
                continue;
            }

            return [
                'provider_post_id' => $candidate['id'],
                'provider_media_id' => $this->attachmentTargetId($candidate),
                'provider_post_url' => $this->postUrl($candidate['id']),
                'provider_response' => [
                    'recovered_after_ambiguous_publish_failure' => true,
                    'original_image_url' => $post->image_url,
                    'recovery_response' => $candidate,
                ],
            ];
        }

        return null;
    }

    public function comment(ConnectedAccount $account, SocialPostTarget $target, string $comment): array
    {
        return $this->graph($account)
            ->post($this->endpoint($target->provider_post_id.'/comments'), [
                'message' => $comment,
            ])
            ->throw()
            ->json();
    }

    public function postAnalytics(ConnectedAccount $account, string $providerPostId): array
    {
        $response = $this->graph($account)
            ->get($this->endpoint($providerPostId), [
                'fields' => 'id,permalink_url,likes.summary(true),comments.summary(true),shares,reactions.summary(true)',
            ])
            ->throw()
            ->json();

        return [
            'id' => $response['id'] ?? $providerPostId,
            'postUrl' => $response['permalink_url'] ?? $this->postUrl($providerPostId),
            'analytics' => [
                'likeCount' => data_get($response, 'likes.summary.total_count', 0),
                'sharesCount' => data_get($response, 'shares.count', 0),
                'commentsCount' => data_get($response, 'comments.summary.total_count', 0),
                'reactions' => [
                    'total' => data_get($response, 'reactions.summary.total_count', 0),
                ],
            ],
        ];
    }

    public function accountAnalytics(ConnectedAccount $account): array
    {
        $response = $this->graph($account)
            ->get($this->endpoint($account->provider_account_id), [
                'fields' => 'id,name,followers_count,fan_count',
            ])
            ->throw()
            ->json();

        return [
            'id' => $response['id'] ?? $account->provider_account_id,
            'name' => $response['name'] ?? $account->display_name,
            'followersCount' => $response['followers_count'] ?? $response['fan_count'] ?? 0,
            'pagePostEngagements' => 0,
            'pagePostsImpressions' => 0,
        ];
    }

    protected function wasCreatedAfter(?string $createdTime, CarbonInterface $publishedAfter): bool
    {
        if (blank($createdTime)) {
            return false;
        }

        return Carbon::parse($createdTime)->greaterThanOrEqualTo($publishedAfter);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    protected function attachmentTargetId(array $candidate): ?string
    {
        return data_get($candidate, 'attachments.data.0.target.id');
    }

    protected function postUrl(string $providerPostId): string
    {
        return "https://www.facebook.com/{$providerPostId}";
    }

    protected function graph(ConnectedAccount $account): PendingRequest
    {
        return Http::acceptJson()->asForm()->withToken($account->access_token);
    }

    protected function endpoint(string $path): string
    {
        $version = config('social.providers.facebook.graph_version', 'v25.0');

        return "https://graph.facebook.com/{$version}/{$path}";
    }
}
