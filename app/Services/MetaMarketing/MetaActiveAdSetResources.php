<?php

namespace App\Services\MetaMarketing;

final class MetaActiveAdSetResources
{
    public function __construct(
        private readonly MetaDeliveryWindow $deliveryWindow,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $adSets
     * @param  list<array<string, mixed>>  $ads
     * @return array{ad_sets: array<string, array<string, mixed>>, campaign_ids: array<string, true>}
     */
    public function select(array $adSets, array $ads, string $timezone, ?string $assumedActiveAdId): array
    {
        $activeAdSetIds = [];

        foreach ($ads as $ad) {
            $isAssumedActive = $assumedActiveAdId !== null && (string) ($ad['id'] ?? '') === $assumedActiveAdId;
            $isActive = ($ad['status'] ?? null) === 'ACTIVE' && ($ad['effective_status'] ?? null) === 'ACTIVE';

            if (($isAssumedActive || $isActive) && is_scalar($ad['adset_id'] ?? null)) {
                $activeAdSetIds[(string) $ad['adset_id']] = true;
            }
        }

        $selectedAdSets = [];
        $campaignIds = [];

        foreach ($adSets as $adSet) {
            $adSetId = (string) ($adSet['id'] ?? '');

            if (! isset($activeAdSetIds[$adSetId]) || ! $this->deliveryWindow->includesToday($adSet, $timezone)) {
                continue;
            }

            $selectedAdSets[$adSetId] = $adSet;
            $campaignIds[(string) ($adSet['campaign_id'] ?? '')] = true;
        }

        return ['ad_sets' => $selectedAdSets, 'campaign_ids' => $campaignIds];
    }
}
