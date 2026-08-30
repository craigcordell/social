<?php

namespace App\Services\MetaMarketing;

final class MetaActiveBudgetResources
{
    public function __construct(
        private readonly MetaActiveAdSetResources $activeAdSets,
        private readonly MetaDeliveryWindow $deliveryWindow,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $campaigns
     * @param  list<array<string, mixed>>  $adSets
     * @param  list<array<string, mixed>>  $ads
     * @return array{campaigns: array<string, array<string, mixed>>, ad_sets: array<string, array<string, mixed>>}
     */
    public function select(
        array $campaigns,
        array $adSets,
        array $ads,
        string $timezone,
        ?string $assumedActiveAdId,
    ): array {
        $activeAdSets = $this->activeAdSets->select($adSets, $ads, $timezone, $assumedActiveAdId);
        $selectedCampaigns = [];

        foreach ($campaigns as $campaign) {
            $campaignId = (string) ($campaign['id'] ?? '');

            if (
                isset($activeAdSets['campaign_ids'][$campaignId])
                && $this->deliveryWindow->includesToday($campaign, $timezone)
            ) {
                $selectedCampaigns[$campaignId] = $campaign;
            }
        }

        return ['campaigns' => $selectedCampaigns, 'ad_sets' => $activeAdSets['ad_sets']];
    }
}
