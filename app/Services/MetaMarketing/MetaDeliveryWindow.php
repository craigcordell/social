<?php

namespace App\Services\MetaMarketing;

use Carbon\CarbonImmutable;

final class MetaDeliveryWindow
{
    /** @param array<string, mixed> $resource */
    public function includesToday(array $resource, string $timezone): bool
    {
        if (($resource['status'] ?? null) !== 'ACTIVE' || ($resource['effective_status'] ?? null) !== 'ACTIVE') {
            return false;
        }

        $today = CarbonImmutable::now($timezone);
        $start = $resource['start_time'] ?? null;
        $end = $resource['stop_time'] ?? $resource['end_time'] ?? null;

        return (
            (! is_string($start) || CarbonImmutable::parse($start)->lessThanOrEqualTo($today->endOfDay()))
            && (! is_string($end) || CarbonImmutable::parse($end)->greaterThanOrEqualTo($today->startOfDay()))
        );
    }
}
