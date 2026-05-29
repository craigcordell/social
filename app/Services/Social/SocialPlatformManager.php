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

    /**
     * @return array<int, string>
     */
    public function supportedProviders(): array
    {
        return ['facebook', 'instagram'];
    }

    public function supports(string $provider): bool
    {
        return in_array($provider, $this->supportedProviders(), true);
    }
}
