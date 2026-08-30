<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;

final class MetaBudgetResourcesClient
{
    public function __construct(
        private readonly MetaGraphApiClient $graph,
        private readonly MetaGraphPaginator $paginator,
        private readonly MetaMarketingConfiguration $configuration,
    ) {}

    /**
     * @return array{account: array<string, mixed>, insights: array<string, mixed>, campaigns: list<array<string, mixed>>, ad_sets: list<array<string, mixed>>, ads: list<array<string, mixed>>}
     */
    public function get(Owner $owner): array
    {
        $this->configuration->assertConfiguredFor($owner);
        $adSets = $this->paginator->getAll($this->configuration->adAccountPath().'/adsets', [
            'fields' => 'id,account_id,campaign_id,campaign{id,account_id,status,effective_status,start_time,stop_time,daily_budget,lifetime_budget,budget_remaining},status,effective_status,start_time,end_time,daily_budget,lifetime_budget,budget_remaining',
            'effective_status' => json_encode(['ACTIVE'], JSON_THROW_ON_ERROR),
            'is_completed' => false,
            'limit' => 100,
        ]);
        $campaignsById = [];

        foreach ($adSets as $adSet) {
            $campaign = $adSet['campaign'] ?? null;

            if (is_array($campaign) && is_string($campaign['id'] ?? null)) {
                /** @var array<string, mixed> $campaign */
                $campaignsById[$campaign['id']] = $campaign;
            }
        }

        return [
            'account' => $this->graph->get($this->configuration->adAccountPath(), [
                'fields' => 'id,account_id,currency,timezone_name',
            ]),
            'insights' => $this->graph->get($this->configuration->adAccountPath().'/insights', [
                'fields' => 'account_id,spend,date_start,date_stop',
                'level' => 'account',
                'date_preset' => 'today',
            ]),
            'campaigns' => array_values($campaignsById),
            'ad_sets' => $adSets,
            'ads' => $this->paginator->getAll($this->configuration->adAccountPath().'/ads', [
                'fields' => 'id,account_id,adset_id,campaign_id,status,effective_status',
                'limit' => 100,
            ]),
        ];
    }
}
