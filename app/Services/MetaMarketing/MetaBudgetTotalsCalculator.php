<?php

namespace App\Services\MetaMarketing;

final class MetaBudgetTotalsCalculator
{
    public function __construct(
        private readonly MetaLifetimeBudgetEstimator $lifetimeEstimator,
    ) {}

    /**
     * @param  array<array-key, array<string, mixed>>  $campaigns
     * @param  array<array-key, array<string, mixed>>  $adSets
     * @return array{active_daily_budget_minor: int, active_lifetime_budget_remaining_minor: int, estimated_lifetime_daily_budget_minor: int, active_lifetime_budget_count: int}
     */
    public function calculate(array $campaigns, array $adSets, string $timezone): array
    {
        $totals = $this->campaignTotals($campaigns, $timezone);

        foreach ($adSets as $adSet) {
            $campaignId = (string) ($adSet['campaign_id'] ?? '');
            $campaign = $campaigns[$campaignId] ?? null;

            if (
                ! is_array($campaign)
                || (int) ($campaign['daily_budget'] ?? 0) > 0
                || (int) ($campaign['lifetime_budget'] ?? 0) > 0
            ) {
                continue;
            }

            $dailyBudget = (int) ($adSet['daily_budget'] ?? 0);

            if ($dailyBudget > 0) {
                $totals['active_daily_budget_minor'] += $dailyBudget;
            } else {
                $this->addLifetimeBudget($totals, $adSet, $timezone);
            }
        }

        return $totals;
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $campaigns
     * @return array{active_daily_budget_minor: int, active_lifetime_budget_remaining_minor: int, estimated_lifetime_daily_budget_minor: int, active_lifetime_budget_count: int}
     */
    protected function campaignTotals(array $campaigns, string $timezone): array
    {
        $totals = [
            'active_daily_budget_minor' => 0,
            'active_lifetime_budget_remaining_minor' => 0,
            'estimated_lifetime_daily_budget_minor' => 0,
            'active_lifetime_budget_count' => 0,
        ];

        foreach ($campaigns as $campaign) {
            $dailyBudget = (int) ($campaign['daily_budget'] ?? 0);

            if ($dailyBudget > 0) {
                $totals['active_daily_budget_minor'] += $dailyBudget;

                continue;
            }

            $this->addLifetimeBudget($totals, $campaign, $timezone);
        }

        return $totals;
    }

    /**
     * @param  array{active_daily_budget_minor: int, active_lifetime_budget_remaining_minor: int, estimated_lifetime_daily_budget_minor: int, active_lifetime_budget_count: int}  $totals
     * @param  array<string, mixed>  $resource
     */
    protected function addLifetimeBudget(array &$totals, array $resource, string $timezone): void
    {
        $estimate = $this->lifetimeEstimator->estimate($resource, $timezone);

        if ($estimate === null) {
            return;
        }

        $totals['active_lifetime_budget_count']++;
        $totals['active_lifetime_budget_remaining_minor'] += $estimate['remaining_minor'];
        $totals['estimated_lifetime_daily_budget_minor'] += $estimate['estimated_daily_minor'];
    }
}
