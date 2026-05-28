<?php

namespace App\Services\Social\Adapters;

use App\Models\ConnectedAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class FacebookPageAdapter implements SocialPlatformAdapter
{
    public function publish(ConnectedAccount $account, SocialPost $post): array
    {
        $response = $this->graph($account)->post($this->endpoint($account->provider_account_id.'/photos'), array_filter([
            'url' => $post->image_url,
            'caption' => trim($post->caption.($post->link_url ? "\n\n{$post->link_url}" : '')),
        ]))->throw()->json();

        return [
            'provider_post_id' => $response['post_id'] ?? $response['id'],
            'provider_media_id' => $response['id'] ?? null,
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
