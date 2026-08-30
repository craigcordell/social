<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;
use SensitiveParameter;

final class MetaMarketingConfiguration
{
    public function assertConfiguredFor(Owner $owner): void
    {
        abort_unless(
            hash_equals((string) config('services.meta_marketing.owner_external_id'), (string) $owner->external_id),
            403,
            'This API token owner cannot access the configured Meta ad account.',
        );

        foreach (['access_token', 'ad_account_id', 'page_id', 'instagram_account_id'] as $key) {
            abort_if(blank(config("services.meta_marketing.{$key}")), 503, 'The Meta Marketing API is not configured.');
        }
    }

    public function accessToken(): string
    {
        return (string) config('services.meta_marketing.access_token');
    }

    public function adAccountId(): string
    {
        $adAccountId = (string) config('services.meta_marketing.ad_account_id');

        return str_starts_with($adAccountId, 'act_') ? substr($adAccountId, 4) : $adAccountId;
    }

    public function adAccountPath(): string
    {
        return 'act_'.$this->adAccountId();
    }

    public function endpoint(string $path): string
    {
        $baseUrl = rtrim((string) config('services.meta_marketing.base_url'), '/');
        $version = trim((string) config('services.meta_marketing.graph_version'), '/');

        return "{$baseUrl}/{$version}/".ltrim($path, '/');
    }

    public function appSecretProof(#[SensitiveParameter] string $accessToken): ?string
    {
        $appSecret = (string) config('services.meta_marketing.app_secret');

        return $appSecret === '' ? null : hash_hmac('sha256', $accessToken, $appSecret);
    }

    /** @param array<string, mixed> $resource */
    public function assertResourceBelongsToAdAccount(array $resource): void
    {
        $resourceAccountId = (string) ($resource['account_id'] ?? '');
        $resourceAccountId = str_starts_with($resourceAccountId, 'act_')
            ? substr($resourceAccountId, 4)
            : $resourceAccountId;

        abort_unless(
            $resourceAccountId !== '' && hash_equals($this->adAccountId(), $resourceAccountId),
            422,
            'The Meta resource does not belong to the configured ad account.',
        );
    }
}
