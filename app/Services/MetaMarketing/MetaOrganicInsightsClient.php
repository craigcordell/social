<?php

namespace App\Services\MetaMarketing;

use App\Models\ConnectedAccount;
use App\Models\Owner;

final class MetaOrganicInsightsClient
{
    public function __construct(
        private readonly MetaGraphApiClient $graph,
        private readonly MetaMarketingConfiguration $configuration,
    ) {}

    /** @return array<string, mixed> */
    public function get(Owner $owner, string $platform, string $postId): array
    {
        $this->configuration->assertConfiguredFor($owner);

        if ($platform !== 'facebook') {
            abort_unless(ctype_digit($postId), 422, 'The Instagram media ID must be numeric.');

            return $this->graph->get($postId.'/insights', [
                'metric' => 'reach,views,total_interactions,likes,comments,saved,shares',
            ]);
        }

        $pageId = (string) config('services.meta_marketing.page_id');
        abort_unless(
            str_starts_with($postId, $pageId.'_'),
            422,
            'The Facebook post does not belong to the configured Page.',
        );

        $accounts = ConnectedAccount::query()
            ->whereBelongsTo($owner)
            ->where('provider', 'facebook')
            ->where('provider_account_id', $pageId)
            ->where('status', ConnectedAccount::STATUS_ACTIVE)
            ->limit(2)
            ->get();

        abort_unless(
            $accounts->count() === 1,
            503,
            'An active Facebook Page connection is required for organic insights.',
        );

        $account = $accounts->first();
        if (! $account instanceof ConnectedAccount) {
            abort(503, 'An active Facebook Page connection is required for organic insights.');
        }

        $pageAccessToken = (string) $account->access_token;
        abort_if(blank($pageAccessToken), 503, 'The Facebook Page connection does not have an access token.');

        return $this->graph->getWithToken($pageAccessToken, $postId.'/insights', [
            'metric' => 'post_impressions,post_impressions_unique,post_engaged_users,post_clicks',
        ]);
    }
}
