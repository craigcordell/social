<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;

final class MetaMarketingApiClient
{
    public const AD_FIELDS = 'id,account_id,name,status,effective_status,adset_id,campaign_id,source_ad_id,created_time,updated_time,creative{id,name,source_instagram_media_id,instagram_permalink_url,effective_instagram_media_id,effective_instagram_story_id,effective_object_story_id,object_story_id}';

    public function __construct(
        private readonly MetaGraphApiClient $graph,
        private readonly MetaGraphPaginator $paginator,
        private readonly MetaMarketingConfiguration $configuration,
        private readonly MetaInsightFields $insightFields,
    ) {}

    /**
     * @param  list<string>  $effectiveStatuses
     * @return array<string, mixed>
     */
    public function campaigns(Owner $owner, int $limit, array $effectiveStatuses, ?string $after): array
    {
        $this->configuration->assertConfiguredFor($owner);

        return $this->graph->get($this->configuration->adAccountPath().'/campaigns', [
            'fields' => 'id,name,status,effective_status,objective,buying_type,daily_budget,lifetime_budget,budget_remaining,bid_strategy,start_time,stop_time,created_time,updated_time',
            'effective_status' => $effectiveStatuses === []
                ? null
                : json_encode($effectiveStatuses, JSON_THROW_ON_ERROR),
            'limit' => $limit,
            'after' => $after,
        ]);
    }

    /**
     * @param  list<string>  $effectiveStatuses
     * @return array<string, mixed>
     */
    public function ads(Owner $owner, int $limit, array $effectiveStatuses, ?string $after): array
    {
        $this->configuration->assertConfiguredFor($owner);

        return $this->graph->get($this->configuration->adAccountPath().'/ads', [
            'fields' => self::AD_FIELDS,
            'effective_status' => $effectiveStatuses === []
                ? null
                : json_encode($effectiveStatuses, JSON_THROW_ON_ERROR),
            'limit' => $limit,
            'after' => $after,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function allAds(Owner $owner): array
    {
        $this->configuration->assertConfiguredFor($owner);

        return $this->paginator->getAll($this->configuration->adAccountPath().'/ads', [
            'fields' => self::AD_FIELDS,
            'limit' => 100,
        ]);
    }

    /** @return array<string, mixed> */
    public function ad(Owner $owner, string $adId): array
    {
        $this->configuration->assertConfiguredFor($owner);
        $ad = $this->graph->get($adId, ['fields' => self::AD_FIELDS]);
        $this->configuration->assertResourceBelongsToAdAccount($ad);

        return $ad;
    }

    /** @return array<string, mixed> */
    public function adInsights(Owner $owner, string $adId, string $since, string $until): array
    {
        $ad = $this->ad($owner, $adId);
        $insights = $this->graph->get($adId.'/insights', [
            'fields' => implode(',', [
                ...$this->insightFields->forLevel('ad'),
                'spend',
                'impressions',
                'reach',
                'clicks',
                'ctr',
                'cpc',
                'cpm',
                'frequency',
                'actions',
                'action_values',
                'cost_per_action_type',
                'date_start',
                'date_stop',
            ]),
            'time_range' => json_encode(['since' => $since, 'until' => $until], JSON_THROW_ON_ERROR),
        ]);

        return ['ad' => $ad, 'insights' => $insights];
    }

    /**
     * @param  array{level: string, since: string, until: string, limit: int, after: ?string}  $filters
     * @return array<string, mixed>
     */
    public function insights(Owner $owner, array $filters): array
    {
        $this->configuration->assertConfiguredFor($owner);

        return $this->graph->get($this->configuration->adAccountPath().'/insights', [
            'fields' => implode(',', [
                ...$this->insightFields->forLevel($filters['level']),
                'spend',
                'impressions',
                'reach',
                'clicks',
                'ctr',
                'cpc',
                'cpm',
                'frequency',
                'actions',
                'action_values',
                'cost_per_action_type',
                'date_start',
                'date_stop',
            ]),
            'level' => $filters['level'],
            'time_range' => json_encode([
                'since' => $filters['since'],
                'until' => $filters['until'],
            ], JSON_THROW_ON_ERROR),
            'limit' => $filters['limit'],
            'after' => $filters['after'],
        ]);
    }

    /** @return array<string, mixed> */
    public function adSetTemplate(Owner $owner, string $adSetId): array
    {
        $this->configuration->assertConfiguredFor($owner);
        $adSet = $this->graph->get($adSetId, [
            'fields' => 'id,account_id,campaign_id,billing_event,optimization_goal,destination_type,promoted_object,targeting',
        ]);
        $this->configuration->assertResourceBelongsToAdAccount($adSet);

        return $adSet;
    }

    /** @return array<string, mixed> */
    public function campaign(Owner $owner, string $campaignId): array
    {
        $this->configuration->assertConfiguredFor($owner);
        $campaign = $this->graph->get($campaignId, [
            'fields' => 'id,account_id,name,status,effective_status,daily_budget,lifetime_budget,budget_remaining,start_time,stop_time',
        ]);
        $this->configuration->assertResourceBelongsToAdAccount($campaign);

        return $campaign;
    }

    /** @return array<string, mixed> */
    public function adSet(Owner $owner, string $adSetId): array
    {
        $this->configuration->assertConfiguredFor($owner);
        $adSet = $this->graph->get($adSetId, [
            'fields' => 'id,account_id,campaign_id,name,status,effective_status,daily_budget,lifetime_budget,budget_remaining,start_time,end_time',
        ]);
        $this->configuration->assertResourceBelongsToAdAccount($adSet);

        return $adSet;
    }
}
