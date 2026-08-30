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

class IncreaseMetaCampaignBudget
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
    public function execute(Owner $owner, string $campaignId, array $data): array
    {
        $idempotencyKey = (string) $data['idempotency_key'];
        $payload = [
            ...Arr::except($data, ['idempotency_key']),
            'campaign_id' => $campaignId,
        ];

        return $this->operations->run(
            $owner,
            MetaAdOperation::TYPE_BUDGET_INCREASE,
            $idempotencyKey,
            $payload,
            function (MetaAdOperation $operation) use ($owner, $campaignId, $data): array {
                $operation->forceFill(['meta_campaign_id' => $campaignId])->save();
                $increaseByMinor = (int) $data['increase_by_minor'];

                return $this->budgetGuard->runWithBudgetAssessment(
                    $owner,
                    $increaseByMinor,
                    function (array $budgetSnapshot) use ($owner, $campaignId, $increaseByMinor): array {
                        $campaign = $this->meta->campaign($owner, $campaignId);
                        $currentDailyBudgetMinor = (int) ($campaign['daily_budget'] ?? 0);
                        $currentLifetimeBudgetMinor = (int) ($campaign['lifetime_budget'] ?? 0);

                        if ($currentDailyBudgetMinor > 0) {
                            $budgetType = 'daily';
                            $currentBudgetMinor = $currentDailyBudgetMinor;
                            $newBudgetMinor = $currentBudgetMinor + $increaseByMinor;
                            $this->mutations->updateCampaignBudget($owner, $campaignId, $newBudgetMinor);
                        } elseif ($currentLifetimeBudgetMinor > 0) {
                            $budgetType = 'lifetime';
                            $currentBudgetMinor = $currentLifetimeBudgetMinor;
                            $newBudgetMinor = $currentBudgetMinor + $increaseByMinor;
                            $this->mutations->updateCampaignLifetimeBudget($owner, $campaignId, $newBudgetMinor);
                        } else {
                            throw ValidationException::withMessages([
                                'campaign_id' => 'This campaign does not use a campaign-level daily or lifetime budget.',
                            ]);
                        }

                        $result = [
                            'campaign_id' => $campaignId,
                            'budget_type' => $budgetType,
                            'previous_budget_minor' => $currentBudgetMinor,
                            'increase_by_minor' => $increaseByMinor,
                            'budget_minor' => $newBudgetMinor,
                            'budget_snapshot' => $budgetSnapshot,
                            'budget_warning' => $budgetSnapshot['warning'],
                        ];

                        if ($budgetType === 'daily') {
                            $result['previous_daily_budget_minor'] = $currentBudgetMinor;
                            $result['daily_budget_minor'] = $newBudgetMinor;
                        } else {
                            $result['previous_lifetime_budget_minor'] = $currentBudgetMinor;
                            $result['lifetime_budget_minor'] = $newBudgetMinor;
                        }

                        return $result;
                    },
                );
            },
        );
    }
}
