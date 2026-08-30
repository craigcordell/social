<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;

final class MetaMarketingCreateClient
{
    public function __construct(
        private readonly MetaGraphApiClient $graph,
        private readonly MetaMarketingConfiguration $configuration,
    ) {}

    /** @return array<string, mixed> */
    public function createCampaign(Owner $owner, string $name, int $dailyBudgetMinor): array
    {
        $this->configuration->assertConfiguredFor($owner);

        return $this->graph->post($this->configuration->adAccountPath().'/campaigns', [
            'name' => $name,
            'objective' => 'OUTCOME_ENGAGEMENT',
            'buying_type' => 'AUCTION',
            'daily_budget' => $dailyBudgetMinor,
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'special_ad_categories' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 'PAUSED',
        ]);
    }

    /**
     * @param  array{campaign_id: string, name: string, template: array<string, mixed>, start_time: string, end_time: string, status: string}  $attributes
     * @return array<string, mixed>
     */
    public function createAdSet(Owner $owner, array $attributes): array
    {
        $this->configuration->assertConfiguredFor($owner);
        $template = $attributes['template'];
        $promotedObject = is_array($template['promoted_object'] ?? null) ? $template['promoted_object'] : [];
        $targeting = is_array($template['targeting'] ?? null) ? $template['targeting'] : [];

        return $this->graph->post($this->configuration->adAccountPath().'/adsets', [
            'campaign_id' => $attributes['campaign_id'],
            'name' => $attributes['name'],
            'billing_event' => (string) ($template['billing_event'] ?? ''),
            'optimization_goal' => (string) ($template['optimization_goal'] ?? ''),
            'destination_type' => (string) ($template['destination_type'] ?? ''),
            'promoted_object' => json_encode($promotedObject, JSON_THROW_ON_ERROR),
            'targeting' => json_encode($targeting, JSON_THROW_ON_ERROR),
            'start_time' => $attributes['start_time'],
            'end_time' => $attributes['end_time'],
            'status' => $attributes['status'],
        ]);
    }

    /** @return array<string, mixed> */
    public function createCreative(Owner $owner, string $name, string $platform, string $postId): array
    {
        $this->configuration->assertConfiguredFor($owner);
        $creative = ['name' => $name];

        if ($platform === 'facebook') {
            abort_unless(
                str_starts_with($postId, (string) config('services.meta_marketing.page_id').'_'),
                422,
                'The Facebook post does not belong to the configured Page.',
            );
            $creative['object_story_id'] = $postId;
        } else {
            abort_unless(ctype_digit($postId), 422, 'The Instagram media ID must be numeric.');
            $creative['source_instagram_media_id'] = $postId;
            $creative['instagram_user_id'] = (string) config('services.meta_marketing.instagram_account_id');
        }

        return $this->graph->post($this->configuration->adAccountPath().'/adcreatives', $creative);
    }

    /**
     * @param  array{ad_set_id: string, creative_id: string, name: string, status: string}  $attributes
     * @return array<string, mixed>
     */
    public function createAd(Owner $owner, array $attributes): array
    {
        $this->configuration->assertConfiguredFor($owner);

        return $this->graph->post($this->configuration->adAccountPath().'/ads', [
            'name' => $attributes['name'],
            'adset_id' => $attributes['ad_set_id'],
            'creative' => json_encode(['creative_id' => $attributes['creative_id']], JSON_THROW_ON_ERROR),
            'status' => $attributes['status'],
        ]);
    }
}
