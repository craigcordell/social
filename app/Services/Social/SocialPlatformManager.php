<?php

namespace App\Services\Social;

use App\Services\Social\Adapters\FacebookPageAdapter;
use App\Services\Social\Adapters\InstagramBusinessAdapter;
use App\Services\Social\Adapters\SocialPlatformAdapter;
use InvalidArgumentException;

class SocialPlatformManager
{
    public function adapter(string $provider): SocialPlatformAdapter
    {
        return match ($provider) {
            'facebook' => app(FacebookPageAdapter::class),
            'instagram' => app(InstagramBusinessAdapter::class),
            default => throw new InvalidArgumentException("Social provider [{$provider}] is not implemented."),
        };
    }
}
