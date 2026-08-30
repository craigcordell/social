<?php

namespace App\Actions\MetaMarketing;

use App\Models\MetaAdOperation;
use App\Models\Owner;
use App\Services\MetaMarketing\MetaAccountBudgetGuard;
use App\Services\MetaMarketing\MetaAdOperationRunner;
use App\Services\MetaMarketing\MetaMarketingApiClient;
use App\Services\MetaMarketing\MetaMarketingMutationClient;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class IncreaseMetaAdBudget
{
    public function __construct(
        private readonly MetaMarketingApiClient $meta,
        private readonly MetaMarketingMutationClient $mutations,
        private readonly MetaAccountBudgetGuard $budgetGuard,
        private readonly MetaAdOperationRunner $operations,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(Owner $owner, string $adId, array $data): array
    {
        $payload = [
            ...Arr::except($data, ['idempotency_key']),
            'ad_id' => $adId,
        ];

        return $this->operations->run(
            $owner,
            MetaAdOperation::TYPE_AD_BUDGET_INCREASE,
            (string) $data['idempotency_key'],
            $payload,
            function (MetaAdOperation $operation) use ($owner, $adId, $data): array {
                $ad = $this->meta->ad($owner, $adId);
                $campaignId = (string) ($ad['campaign_id'] ?? '');
                $adSetId = (string) ($ad['adset_id'] ?? '');

                if ($campaignId === '' || $adSetId === '') {
                    throw ValidationException::withMessages([
                        'ad_id' => 'The Meta ad does not identify its campaign and ad set.',
                    ]);
                }

                $operation->forceFill([
                    'meta_campaign_id' => $campaignId,
                    'meta_ad_set_id' => $adSetId,
                    'meta_ad_id' => $adId,
                ])->save();

                $campaign = $this->meta->campaign($owner, $campaignId);
                $adSet = $this->meta->adSet($owner, $adSetId);
                $budget = $this->resolveBudgetOwner($campaign, $adSet);
                $increaseByMinor = (int) $data['increase_by_minor'];

                return $this->budgetGuard->runWithBudgetAssessment(
                    $owner,
                    $increaseByMinor,
                    function (array $budgetSnapshot) use (
                        $owner,
                        $adId,
                        $campaignId,
                        $adSetId,
                        $budget,
                        $increaseByMinor,
                    ): array {
                        $newBudgetMinor = $budget['current_budget_minor'] + $increaseByMinor;

                        if ($budget['owner_type'] === 'campaign') {
                            if ($budget['budget_type'] === 'daily') {
                                $this->mutations->updateCampaignBudget($owner, $budget['owner_id'], $newBudgetMinor);
                            } else {
                                $this->mutations->updateCampaignLifetimeBudget(
                                    $owner,
                                    $budget['owner_id'],
                                    $newBudgetMinor,
                                );
                            }
                        } elseif ($budget['budget_type'] === 'daily') {
                            $this->mutations->updateAdSetBudget($owner, $budget['owner_id'], $newBudgetMinor);
                        } else {
                            $this->mutations->updateAdSetLifetimeBudget($owner, $budget['owner_id'], $newBudgetMinor);
                        }

                        return [
                            'ad_id' => $adId,
                            'campaign_id' => $campaignId,
                            'ad_set_id' => $adSetId,
                            'budget_owner_type' => $budget['owner_type'],
                            'budget_owner_id' => $budget['owner_id'],
                            'budget_type' => $budget['budget_type'],
                            'previous_budget_minor' => $budget['current_budget_minor'],
                            'increase_by_minor' => $increaseByMinor,
                            'budget_minor' => $newBudgetMinor,
                            'budget_snapshot' => $budgetSnapshot,
                            'budget_warning' => $budgetSnapshot['warning'],
                        ];
                    },
                );
            },
        );
    }

    /**
     * @param  array<string, mixed>  $campaign
     * @param  array<string, mixed>  $adSet
     * @return array{owner_type: string, owner_id: string, budget_type: string, current_budget_minor: int}
     */
    protected function resolveBudgetOwner(array $campaign, array $adSet): array
    {
        foreach ([
            ['owner_type' => 'campaign', 'resource' => $campaign],
            ['owner_type' => 'ad_set', 'resource' => $adSet],
        ] as $candidate) {
            foreach (['daily', 'lifetime'] as $budgetType) {
                $currentBudgetMinor = (int) ($candidate['resource'][$budgetType.'_budget'] ?? 0);

                if ($currentBudgetMinor > 0) {
                    return [
                        'owner_type' => $candidate['owner_type'],
                        'owner_id' => (string) $candidate['resource']['id'],
                        'budget_type' => $budgetType,
                        'current_budget_minor' => $currentBudgetMinor,
                    ];
                }
            }
        }

        throw ValidationException::withMessages([
            'ad_id' => 'Neither the ad campaign nor its ad set has a daily or lifetime budget that can be increased.',
        ]);
    }
}
