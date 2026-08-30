<?php

namespace App\Actions\MetaMarketing;

use App\Models\MetaAdOperation;
use App\Models\Owner;
use App\Services\MetaMarketing\MetaAccountBudgetGuard;
use App\Services\MetaMarketing\MetaAdOperationRunner;
use App\Services\MetaMarketing\MetaMarketingApiClient;
use App\Services\MetaMarketing\MetaMarketingMutationClient;
use Closure;
use Illuminate\Support\Arr;

class UpdateMetaAdStatus
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
            MetaAdOperation::TYPE_STATUS_UPDATE,
            (string) $data['idempotency_key'],
            $payload,
            function (MetaAdOperation $operation) use ($owner, $adId, $data): array {
                $ad = $this->meta->ad($owner, $adId);
                $operation->forceFill([
                    'meta_campaign_id' => $ad['campaign_id'] ?? null,
                    'meta_ad_set_id' => $ad['adset_id'] ?? null,
                    'meta_ad_id' => $adId,
                ])->save();

                $previousStatus = (string) ($ad['status'] ?? 'UNKNOWN');
                $requestedStatus = (string) $data['status'];

                if ($previousStatus === $requestedStatus) {
                    return $this->response($ad, $previousStatus, $requestedStatus, true, null);
                }

                /** @var Closure(null|array<string, int|string|bool|null>): array<string, mixed> $update */
                $update = function (?array $budgetSnapshot = null) use (
                    $owner,
                    $adId,
                    $previousStatus,
                    $requestedStatus,
                ): array {
                    $this->mutations->updateAdStatus($owner, $adId, $requestedStatus);
                    $updatedAd = $this->meta->ad($owner, $adId);

                    return $this->response($updatedAd, $previousStatus, $requestedStatus, false, $budgetSnapshot);
                };

                if ($requestedStatus === 'ACTIVE') {
                    return $this->budgetGuard->runWithAdResumeAssessment(
                        $owner,
                        $adId,
                        fn (array $budgetSnapshot): array => $update($budgetSnapshot),
                    );
                }

                return $update(null);
            },
        );
    }

    /**
     * @param  array<string, mixed>  $ad
     * @param  null|array<array-key, mixed>  $budgetSnapshot
     * @return array<string, mixed>
     */
    protected function response(
        array $ad,
        string $previousStatus,
        string $requestedStatus,
        bool $unchanged,
        ?array $budgetSnapshot,
    ): array {
        return [
            'ad_id' => (string) ($ad['id'] ?? ''),
            'campaign_id' => $ad['campaign_id'] ?? null,
            'ad_set_id' => $ad['adset_id'] ?? null,
            'previous_status' => $previousStatus,
            'requested_status' => $requestedStatus,
            'status' => (string) ($ad['status'] ?? $requestedStatus),
            'effective_status' => $ad['effective_status'] ?? null,
            'unchanged' => $unchanged,
            'budget_snapshot' => $budgetSnapshot,
            'budget_warning' => $budgetSnapshot['warning'] ?? null,
        ];
    }
}
