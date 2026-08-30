<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;

final class MetaMarketingMutationClient
{
    public function __construct(
        private readonly MetaGraphApiClient $graph,
        private readonly MetaMarketingConfiguration $configuration,
    ) {}

    /** @return array<string, mixed> */
    public function updateCampaignBudget(Owner $owner, string $campaignId, int $dailyBudgetMinor): array
    {
        return $this->update($owner, $campaignId, ['daily_budget' => $dailyBudgetMinor]);
    }

    /** @return array<string, mixed> */
    public function updateCampaignLifetimeBudget(Owner $owner, string $campaignId, int $lifetimeBudgetMinor): array
    {
        return $this->update($owner, $campaignId, ['lifetime_budget' => $lifetimeBudgetMinor]);
    }

    /** @return array<string, mixed> */
    public function updateCampaignStatus(Owner $owner, string $campaignId, string $status): array
    {
        return $this->update($owner, $campaignId, ['status' => $status]);
    }

    /** @return array<string, mixed> */
    public function updateAdSetBudget(Owner $owner, string $adSetId, int $dailyBudgetMinor): array
    {
        return $this->update($owner, $adSetId, ['daily_budget' => $dailyBudgetMinor]);
    }

    /** @return array<string, mixed> */
    public function updateAdSetLifetimeBudget(Owner $owner, string $adSetId, int $lifetimeBudgetMinor): array
    {
        return $this->update($owner, $adSetId, ['lifetime_budget' => $lifetimeBudgetMinor]);
    }

    /** @return array<string, mixed> */
    public function updateAdStatus(Owner $owner, string $adId, string $status): array
    {
        return $this->update($owner, $adId, ['status' => $status]);
    }

    /**
     * @param  array<string, int|string>  $payload
     * @return array<string, mixed>
     */
    protected function update(Owner $owner, string $resourceId, array $payload): array
    {
        $this->configuration->assertConfiguredFor($owner);

        return $this->graph->post($resourceId, $payload);
    }
}
