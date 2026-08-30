<?php

namespace App\Services\MetaMarketing;

use Carbon\CarbonImmutable;

final class MetaLifetimeBudgetEstimator
{
    /**
     * @param  array<string, mixed>  $resource
     * @return null|array{remaining_minor: int, estimated_daily_minor: int}
     */
    public function estimate(array $resource, string $timezone): ?array
    {
        $lifetimeBudget = (int) ($resource['lifetime_budget'] ?? 0);
        $remainingMinor = (int) ($resource['budget_remaining'] ?? $lifetimeBudget);

        if ($lifetimeBudget <= 0 || $remainingMinor <= 0) {
            return null;
        }

        $stop = $resource['stop_time'] ?? $resource['end_time'] ?? null;

        if (! is_string($stop) || $stop === '') {
            return ['remaining_minor' => $remainingMinor, 'estimated_daily_minor' => $remainingMinor];
        }

        $now = CarbonImmutable::now($timezone);
        $stopAt = CarbonImmutable::parse($stop)->setTimezone($timezone);
        $remainingDays = max(1, (int) ceil(max(1, $stopAt->timestamp - $now->timestamp) / 86400));

        return [
            'remaining_minor' => $remainingMinor,
            'estimated_daily_minor' => (int) ceil($remainingMinor / $remainingDays),
        ];
    }
}
