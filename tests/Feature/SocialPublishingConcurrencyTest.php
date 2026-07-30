<?php

use Illuminate\Support\Facades\Concurrency;
use Tests\DelayedConcurrencyTask;

it('runs social publishing tasks concurrently with the process driver', function (): void {
    $startedAt = hrtime(true);

    $results = Concurrency::driver('process')->run([
        'facebook' => Closure::fromCallable([DelayedConcurrencyTask::class, 'facebook']),
        'instagram' => Closure::fromCallable([DelayedConcurrencyTask::class, 'instagram']),
        'gmb' => Closure::fromCallable([DelayedConcurrencyTask::class, 'googleBusiness']),
    ], timeout: 5);

    $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

    expect($results)->toBe([
        'facebook' => 'facebook',
        'instagram' => 'instagram',
        'gmb' => 'gmb',
    ])->and($elapsedSeconds)->toBeLessThan(2.5);
});
