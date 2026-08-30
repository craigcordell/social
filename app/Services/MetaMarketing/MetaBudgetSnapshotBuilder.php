<?php

namespace App\Services\MetaMarketing;

final class MetaBudgetSnapshotBuilder
{
    public function __construct(
        private readonly MetaActiveBudgetResources $activeResources,
        private readonly MetaBudgetTotalsCalculator $totalsCalculator,
    ) {}

    /**
     * @param  array{account: array<string, mixed>, insights: array<string, mixed>, campaigns: list<array<string, mixed>>, ad_sets: list<array<string, mixed>>, ads: list<array<string, mixed>>}  $resources
     * @return array<string, int|string|bool>
     */
    public function build(array $resources, ?string $assumedActiveAdId = null): array
    {
        $currency = (string) ($resources['account']['currency'] ?? '');
        $expectedCurrency = (string) config('services.meta_marketing.currency', 'USD');

        abort_unless(
            $currency !== '' && hash_equals($expectedCurrency, $currency),
            503,
            'The configured Meta account currency does not match the API budget currency.',
        );

        $timezone = (string) ($resources['account']['timezone_name'] ?? config('app.timezone'));
        $active = $this->activeResources->select(
            $resources['campaigns'],
            $resources['ad_sets'],
            $resources['ads'],
            $timezone,
            $assumedActiveAdId,
        );
        $totals = $this->totalsCalculator->calculate($active['campaigns'], $active['ad_sets'], $timezone);
        $spent = data_get($resources, 'insights.data.0.spend', '0');
        $spentTodayMinor = $this->majorToMinor(is_scalar($spent) ? (string) $spent : '0');
        $limitMinor = (int) config('services.meta_marketing.account_daily_limit_minor');
        $projectedDailyBudgetMinor =
            $totals['active_daily_budget_minor'] + $totals['estimated_lifetime_daily_budget_minor'];
        $protectedUsageMinor = max($spentTodayMinor, $projectedDailyBudgetMinor);

        return [
            'currency' => $currency,
            'account_daily_limit_minor' => $limitMinor,
            'spent_today_minor' => $spentTodayMinor,
            ...$totals,
            'projected_daily_budget_minor' => $projectedDailyBudgetMinor,
            'protected_usage_minor' => $protectedUsageMinor,
            'remaining_minor' => max(0, $limitMinor - $protectedUsageMinor),
            'is_over_daily_limit' => $limitMinor > 0 && $protectedUsageMinor > $limitMinor,
            'advisory_only' => true,
            'mutations_allowed' => $limitMinor > 0,
        ];
    }

    protected function majorToMinor(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
