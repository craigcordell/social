<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Social\SocialPlatformManager;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SocialPlatformManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null);

        RateLimiter::for('social-provider', function (object $job): Limit {
            $provider = method_exists($job, 'provider') ? $job->provider() : 'unknown';
            $maxAttempts = (int) config("social.providers.{$provider}.rate_limit_per_minute", 10);

            return Limit::perMinute($maxAttempts)->by($provider);
        });

        RateLimiter::for('meta-mutations', function (Request $request): Limit {
            $user = $request->user();
            $token = $user instanceof User ? $user->currentAccessToken() : null;
            $key = $token instanceof PersonalAccessToken
                ? (string) $token->getKey()
                : $request->ip();

            return Limit::perMinute(6)->by('meta-mutations:'.$key);
        });
    }
}
