<?php

namespace Tests;

final class DelayedConcurrencyTask
{
    public static function facebook(): string
    {
        return self::afterDelay('facebook');
    }

    public static function instagram(): string
    {
        return self::afterDelay('instagram');
    }

    public static function googleBusiness(): string
    {
        return self::afterDelay('gmb');
    }

    private static function afterDelay(string $result): string
    {
        usleep(1_000_000);

        return $result;
    }
}
