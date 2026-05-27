<?php

namespace App\Services\Social\Adapters;

use App\Models\ConnectedAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;

interface SocialPlatformAdapter
{
    /**
     * @return array{provider_post_id: string, provider_media_id?: string|null, provider_response: array<string, mixed>}
     */
    public function publish(ConnectedAccount $account, SocialPost $post): array;

    /**
     * @return array<string, mixed>
     */
    public function delete(ConnectedAccount $account, SocialPostTarget $target): array;
}
