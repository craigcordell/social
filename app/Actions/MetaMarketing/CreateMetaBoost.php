<?php

namespace App\Actions\MetaMarketing;

use App\Models\MetaAdOperation;
use App\Models\Owner;
use App\Services\MetaMarketing\MetaAccountBudgetGuard;
use App\Services\MetaMarketing\MetaAdOperationRunner;
use App\Services\MetaMarketing\MetaMarketingApiClient;
use App\Services\MetaMarketing\MetaMarketingCreateClient;
use App\Services\MetaMarketing\MetaMarketingMutationClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class CreateMetaBoost
{
    public function __construct(
        private readonly MetaMarketingApiClient $meta,
        private readonly MetaMarketingCreateClient $creator,
        private readonly MetaMarketingMutationClient $mutations,
        private readonly MetaAccountBudgetGuard $budgetGuard,
        private readonly MetaAdOperationRunner $operations,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(Owner $owner, array $data): array
    {
        $idempotencyKey = (string) $data['idempotency_key'];

        /** @var array<string, mixed> $payload */
        $payload = Arr::except($data, ['idempotency_key']);

        return $this->operations->run(
            $owner,
            MetaAdOperation::TYPE_BOOST,
            $idempotencyKey,
            $payload,
            fn (MetaAdOperation $operation): array => $this->create($owner, $operation, $payload),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function create(Owner $owner, MetaAdOperation $operation, array $data): array
    {
        $dailyBudgetMinor = (int) $data['daily_budget_minor'];

        return $this->budgetGuard->runWithBudgetAssessment(
            $owner,
            $dailyBudgetMinor,
            function (array $budgetSnapshot) use ($owner, $operation, $data, $dailyBudgetMinor): array {
                $templateAdSetId = (string) (
                    $data['template_ad_set_id'] ?? config('services.meta_marketing.template_ad_set_id')
                );

                abort_if($templateAdSetId === '', 503, 'A Meta template ad set is not configured.');

                $template = $this->meta->adSetTemplate($owner, $templateAdSetId);
                abort_unless(
                    Arr::has($template, [
                        'billing_event',
                        'optimization_goal',
                        'destination_type',
                        'promoted_object',
                        'targeting',
                    ]),
                    422,
                    'The Meta template ad set is missing fields required to create a boost.',
                );

                $platform = (string) $data['platform'];
                $requestedStatus = (string) ($data['status'] ?? 'PAUSED');
                $durationDays = (int) ($data['duration_days'] ?? 7);
                $name = (string) (
                    $data['name'] ?? sprintf(
                        'API Boost: %s %s',
                        Str::title($platform),
                        Str::limit((string) $data['post_id'], 80, ''),
                    )
                );
                $start = now()->addMinutes(10);
                $end = $start->addDays($durationDays);
                $campaignId = null;

                try {
                    $campaign = $this->creator->createCampaign($owner, $name, $dailyBudgetMinor);
                    $campaignId = (string) ($campaign['id'] ?? '');
                    abort_if($campaignId === '', 502, 'Meta did not return a campaign ID.');
                    $this->recordIds($operation, campaignId: $campaignId);

                    $adSet = $this->creator->createAdSet($owner, [
                        'campaign_id' => $campaignId,
                        'name' => $name.' Ad Set',
                        'template' => $template,
                        'start_time' => $start->toIso8601String(),
                        'end_time' => $end->toIso8601String(),
                        'status' => $requestedStatus,
                    ]);
                    $adSetId = (string) ($adSet['id'] ?? '');
                    abort_if($adSetId === '', 502, 'Meta did not return an ad set ID.');
                    $this->recordIds($operation, adSetId: $adSetId);

                    $creative = $this->creator->createCreative(
                        $owner,
                        $name.' Creative',
                        $platform,
                        (string) $data['post_id'],
                    );
                    $creativeId = (string) ($creative['id'] ?? '');
                    abort_if($creativeId === '', 502, 'Meta did not return a creative ID.');
                    $this->recordIds($operation, creativeId: $creativeId);

                    $ad = $this->creator->createAd($owner, [
                        'ad_set_id' => $adSetId,
                        'creative_id' => $creativeId,
                        'name' => $name.' Ad',
                        'status' => $requestedStatus,
                    ]);
                    $adId = (string) ($ad['id'] ?? '');
                    abort_if($adId === '', 502, 'Meta did not return an ad ID.');
                    $this->recordIds($operation, adId: $adId);

                    if ($requestedStatus === 'ACTIVE') {
                        $this->mutations->updateCampaignStatus($owner, $campaignId, 'ACTIVE');
                    }

                    return [
                        'campaign_id' => $campaignId,
                        'ad_set_id' => $adSetId,
                        'creative_id' => $creativeId,
                        'ad_id' => $adId,
                        'status' => $requestedStatus,
                        'daily_budget_minor' => $dailyBudgetMinor,
                        'starts_at' => $start->toIso8601String(),
                        'ends_at' => $end->toIso8601String(),
                        'budget_snapshot' => $budgetSnapshot,
                        'budget_warning' => $budgetSnapshot['warning'],
                    ];
                } catch (Throwable $exception) {
                    if (is_string($campaignId) && $campaignId !== '') {
                        try {
                            $this->mutations->updateCampaignStatus($owner, $campaignId, 'PAUSED');
                        } catch (Throwable $pauseException) {
                            report($pauseException);
                        }
                    }

                    throw $exception;
                }
            },
        );
    }

    protected function recordIds(
        MetaAdOperation $operation,
        ?string $campaignId = null,
        ?string $adSetId = null,
        ?string $creativeId = null,
        ?string $adId = null,
    ): void {
        $operation
            ->forceFill(array_filter([
                'meta_campaign_id' => $campaignId,
                'meta_ad_set_id' => $adSetId,
                'meta_creative_id' => $creativeId,
                'meta_ad_id' => $adId,
            ]))
            ->save();
    }
}
