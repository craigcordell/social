<?php

namespace App\Services\Social\Adapters;

use App\Models\ConnectedAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InstagramBusinessAdapter implements SocialPlatformAdapter
{
    public function publish(ConnectedAccount $account, SocialPost $post): array
    {
        $container = $this->graph($account)
            ->post($this->endpoint($account->provider_account_id.'/media'), array_filter([
                'image_url' => $post->image_url,
                'caption' => $this->caption($post),
            ]))
            ->throw()
            ->json();

        $containerStatus = $this->waitForContainer($account, $container['id']);

        $published = $this->graph($account)
            ->post($this->endpoint($account->provider_account_id.'/media_publish'), [
                'creation_id' => $container['id'],
            ])
            ->throw()
            ->json();

        return [
            'provider_post_id' => $published['id'],
            'provider_media_id' => $container['id'],
            'provider_response' => [
                'container' => $container,
                'container_status' => $containerStatus,
                'published' => $published,
            ],
        ];
    }

    public function delete(ConnectedAccount $account, SocialPostTarget $target): array
    {
        return [
            'manual_delete_required' => true,
            'message' => 'Instagram does not support deleting published feed media through the current Instagram API. Delete this post manually in Instagram.',
            'provider_post_id' => $target->provider_post_id,
        ];
    }

    protected function caption(SocialPost $post): string
    {
        return trim($post->caption.($post->link_url ? "\n\n{$post->link_url}" : ''));
    }

    protected function graph(ConnectedAccount $account): PendingRequest
    {
        return Http::acceptJson()->asForm()->withToken($account->access_token);
    }

    protected function waitForContainer(ConnectedAccount $account, string $containerId): array
    {
        $status = [];

        foreach (range(1, 5) as $attempt) {
            $status = $this->graph($account)
                ->get($this->endpoint($containerId), [
                    'fields' => 'status_code',
                ])
                ->throw()
                ->json();

            if (($status['status_code'] ?? null) === 'FINISHED') {
                return $status;
            }

            if (in_array($status['status_code'] ?? null, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException('Instagram media container is not publishable: '.($status['status_code'] ?? 'unknown'));
            }

            if ($attempt < 5) {
                sleep(2);
            }
        }

        throw new RuntimeException('Instagram media container was not ready for publishing: '.($status['status_code'] ?? 'unknown'));
    }

    protected function endpoint(string $path): string
    {
        $version = config('social.providers.instagram.graph_version', 'v25.0');

        return "https://graph.instagram.com/{$version}/{$path}";
    }
}
