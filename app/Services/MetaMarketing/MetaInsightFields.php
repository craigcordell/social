<?php

namespace App\Services\MetaMarketing;

final class MetaInsightFields
{
    /** @return list<string> */
    public function forLevel(string $level): array
    {
        return match ($level) {
            'campaign' => ['account_id', 'account_name', 'campaign_id', 'campaign_name'],
            'adset' => ['account_id', 'account_name', 'campaign_id', 'campaign_name', 'adset_id', 'adset_name'],
            'ad' => [
                'account_id',
                'account_name',
                'campaign_id',
                'campaign_name',
                'adset_id',
                'adset_name',
                'ad_id',
                'ad_name',
            ],
            default => ['account_id', 'account_name'],
        };
    }
}
