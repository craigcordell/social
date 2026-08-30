<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class MetaAccountBudgetGuard
{
    public function __construct(
        private readonly MetaBudgetResourcesClient $resources,
        private readonly MetaBudgetSnapshotBuilder $snapshotBuilder,
        private readonly MetaMarketingConfiguration $configuration,
    ) {}

    /** @return array<string, int|string|bool> */
    public function snapshot(Owner $owner): array
    {
        return $this->snapshotBuilder->build($this->resources->get($owner));
    }

    /**
     * @param  Closure(array<string, int|string|bool|null>): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function runWithBudgetAssessment(Owner $owner, int $additionalBudgetMinor, Closure $callback): array
    {
        try {
            $result = Cache::lock(
                'meta-marketing:account-budget:'.$this->configuration->adAccountId(),
                60,
            )->block(10, function () use ($owner, $additionalBudgetMinor, $callback): array {
                return $callback($this->assess($this->snapshot($owner), $additionalBudgetMinor));
            });

            abort_unless(is_array($result), 500, 'The Meta budget assessment did not return a response.');

            /** @var array<string, mixed> $result */
            return $result;
        } catch (LockTimeoutException) {
            abort(503, 'Another Meta budget change is in progress. Try again shortly.');
        }
    }

    /**
     * @param  Closure(array<string, int|string|bool|null>): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function runWithAdResumeAssessment(Owner $owner, string $adId, Closure $callback): array
    {
        try {
            $result = Cache::lock(
                'meta-marketing:account-budget:'.$this->configuration->adAccountId(),
                60,
            )->block(10, function () use ($owner, $adId, $callback): array {
                $resources = $this->resources->get($owner);
                $current = $this->snapshotBuilder->build($resources);
                $projected = $this->snapshotBuilder->build($resources, $adId);
                $additionalExposureMinor = max(
                    0,
                    (int) $projected['protected_usage_minor'] - (int) $current['protected_usage_minor'],
                );
                $assessment = $this->assess($current, $additionalExposureMinor);
                $assessment['projected_daily_budget_after_change_minor'] = $projected['projected_daily_budget_minor'];

                return $callback($assessment);
            });

            abort_unless(is_array($result), 500, 'The Meta budget assessment did not return a response.');

            /** @var array<string, mixed> $result */
            return $result;
        } catch (LockTimeoutException) {
            abort(503, 'Another Meta budget change is in progress. Try again shortly.');
        }
    }

    /**
     * @param  array<string, int|string|bool>  $snapshot
     * @return array<string, int|string|bool|null>
     */
    protected function assess(array $snapshot, int $additionalBudgetMinor): array
    {
        abort_if(
            (int) $snapshot['account_daily_limit_minor'] <= 0,
            503,
            'Meta budget mutations are disabled until an account daily limit is configured.',
        );

        $projectedUsageAfterChangeMinor = (int) $snapshot['protected_usage_minor'] + $additionalBudgetMinor;
        $wouldExceedDailyLimit = $projectedUsageAfterChangeMinor > (int) $snapshot['account_daily_limit_minor'];

        return [
            ...$snapshot,
            'additional_budget_minor' => $additionalBudgetMinor,
            'projected_usage_after_change_minor' => $projectedUsageAfterChangeMinor,
            'would_exceed_daily_limit' => $wouldExceedDailyLimit,
            'warning' => $wouldExceedDailyLimit
                ? sprintf(
                    'This change may raise daily ad exposure to %s %0.2f, above the configured %s %0.2f advisory limit.',
                    $snapshot['currency'],
                    $projectedUsageAfterChangeMinor / 100,
                    $snapshot['currency'],
                    (int) $snapshot['account_daily_limit_minor'] / 100,
                )
                : null,
        ];
    }
}
