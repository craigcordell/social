<?php

namespace App\Services\Social\Adapters;

use App\Models\ConnectedAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleBusinessProfileAdapter implements SocialPlatformAdapter
{
    private const POST_METRICS = [
        'LOCAL_POST_VIEWS_SEARCH',
        'LOCAL_POST_ACTIONS_CALL_TO_ACTION',
    ];

    private const DAILY_METRICS = [
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
        'CALL_CLICKS',
        'WEBSITE_CLICKS',
        'BUSINESS_DIRECTION_REQUESTS',
    ];

    public function publish(ConnectedAccount $account, SocialPost $post): array
    {
        $payload = [
            'languageCode' => 'en-US',
            'summary' => $post->caption,
            'topicType' => 'STANDARD',
            'media' => [
                [
                    'mediaFormat' => 'PHOTO',
                    'sourceUrl' => $post->image_url,
                ],
            ],
        ];

        if (filled($post->link_url)) {
            $payload['callToAction'] = [
                'actionType' => 'LEARN_MORE',
                'url' => $post->link_url,
            ];
        }

        $response = $this->google($account)
            ->post($this->localPostsUrl($account->provider_account_id), $payload)
            ->throw()
            ->json();

        return [
            'provider_post_id' => $response['name'],
            'provider_media_id' => null,
            'provider_post_url' => $response['searchUrl'] ?? null,
            'provider_response' => $response,
        ];
    }

    public function delete(ConnectedAccount $account, SocialPostTarget $target): array
    {
        $response = $this->google($account)
            ->delete($this->postUrl((string) $target->provider_post_id))
            ->throw()
            ->json();

        return $response ?: ['success' => true];
    }

    public function comment(ConnectedAccount $account, SocialPostTarget $target, string $comment): array
    {
        throw new RuntimeException('Google Business Profile local posts do not support comments through this adapter.');
    }

    public function postAnalytics(ConnectedAccount $account, string $providerPostId): array
    {
        $response = $this->google($account)
            ->post($this->postInsightsUrl($providerPostId), [
                'localPostNames' => [$providerPostId],
                'basicRequest' => [
                    'metricRequests' => collect(self::POST_METRICS)
                        ->map(fn (string $metric): array => ['metric' => $metric])
                        ->all(),
                ],
            ])
            ->throw()
            ->json();

        $metricTotals = $this->localPostMetricTotals($response);

        return [
            'id' => $providerPostId,
            'postUrl' => data_get($response, 'localPostMetrics.0.localPost.searchUrl'),
            'analytics' => [
                'viewsCount' => $metricTotals['LOCAL_POST_VIEWS_SEARCH'] ?? 0,
                'callToActionCount' => $metricTotals['LOCAL_POST_ACTIONS_CALL_TO_ACTION'] ?? 0,
                'raw' => $response,
            ],
        ];
    }

    public function accountAnalytics(ConnectedAccount $account): array
    {
        $end = now();
        $start = now()->subMonths(3);

        $response = $this->google($account)
            ->get($this->performanceUrl($account->provider_account_id).'?'.$this->performanceQueryString($start, $end))
            ->throw()
            ->json();

        $metricTotals = $this->dailyMetricTotals($response);

        return [
            'id' => $account->provider_account_id,
            'name' => $account->display_name,
            'callClicks' => $metricTotals['CALL_CLICKS'] ?? 0,
            'websiteClicks' => $metricTotals['WEBSITE_CLICKS'] ?? 0,
            'businessDirectionRequests' => $metricTotals['BUSINESS_DIRECTION_REQUESTS'] ?? 0,
            'businessImpressionsMobileMaps' => $metricTotals['BUSINESS_IMPRESSIONS_MOBILE_MAPS'] ?? 0,
            'businessImpressionsDesktopMaps' => $metricTotals['BUSINESS_IMPRESSIONS_DESKTOP_MAPS'] ?? 0,
            'businessImpressionsMobileSearch' => $metricTotals['BUSINESS_IMPRESSIONS_MOBILE_SEARCH'] ?? 0,
            'businessImpressionsDesktopSearch' => $metricTotals['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'] ?? 0,
            'raw' => $response,
        ];
    }

    protected function google(ConnectedAccount $account): PendingRequest
    {
        $this->refreshTokenIfNeeded($account);

        return Http::acceptJson()
            ->asJson()
            ->timeout(60)
            ->connectTimeout(10)
            ->withToken((string) $account->access_token);
    }

    protected function refreshTokenIfNeeded(ConnectedAccount $account): void
    {
        if (! $account->token_expires_at || $account->token_expires_at->greaterThan(now()->addMinute())) {
            return;
        }

        if (blank($account->refresh_token)) {
            throw new RuntimeException('Google Business Profile token has expired and no refresh token is stored.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_business.client_id'),
                'client_secret' => config('services.google_business.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ])
            ->throw()
            ->json();

        $account->forceFill([
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => isset($response['expires_in']) ? now()->addSeconds((int) $response['expires_in']) : null,
        ])->save();

        $account->refresh();
    }

    protected function performanceQueryString(CarbonInterface $start, CarbonInterface $end): string
    {
        $metricQuery = collect(self::DAILY_METRICS)
            ->map(fn (string $metric): string => 'dailyMetrics='.rawurlencode($metric))
            ->implode('&');

        $dateQuery = http_build_query([
            'dailyRange.startDate.year' => $start->year,
            'dailyRange.startDate.month' => $start->month,
            'dailyRange.startDate.day' => $start->day,
            'dailyRange.endDate.year' => $end->year,
            'dailyRange.endDate.month' => $end->month,
            'dailyRange.endDate.day' => $end->day,
        ], '', '&', PHP_QUERY_RFC3986);

        return "{$metricQuery}&{$dateQuery}";
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, int>
     */
    protected function localPostMetricTotals(array $response): array
    {
        return collect(data_get($response, 'localPostMetrics.0.metricValues', []))
            ->mapWithKeys(fn (array $metric): array => [
                (string) ($metric['metric'] ?? '') => $this->sumMetricValue($metric),
            ])
            ->filter(fn (int $value, string $metric): bool => $metric !== '')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, int>
     */
    protected function dailyMetricTotals(array $response): array
    {
        return collect($response['multiDailyMetricTimeSeries'] ?? [])
            ->flatMap(fn (array $metricSet): array => $metricSet['dailyMetricTimeSeries'] ?? [])
            ->mapWithKeys(fn (array $metric): array => [
                (string) ($metric['dailyMetric'] ?? '') => $this->sumDatedValues($metric),
            ])
            ->filter(fn (int $value, string $metric): bool => $metric !== '')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $metric
     */
    protected function sumMetricValue(array $metric): int
    {
        if (isset($metric['totalValue']['value'])) {
            return (int) $metric['totalValue']['value'];
        }

        if (isset($metric['value'])) {
            return (int) $metric['value'];
        }

        return $this->sumDatedValues($metric);
    }

    /**
     * @param  array<string, mixed>  $metric
     */
    protected function sumDatedValues(array $metric): int
    {
        return collect(data_get($metric, 'timeSeries.datedValues', []))
            ->sum(fn (array $datedValue): int => (int) ($datedValue['value'] ?? 0));
    }

    protected function localPostsUrl(string $locationName): string
    {
        return "https://mybusiness.googleapis.com/v4/{$locationName}/localPosts";
    }

    protected function postUrl(string $localPostName): string
    {
        return "https://mybusiness.googleapis.com/v4/{$localPostName}";
    }

    protected function postInsightsUrl(string $localPostName): string
    {
        $locationName = str($localPostName)->before('/localPosts/')->toString();

        return "https://mybusiness.googleapis.com/v4/{$locationName}/localPosts:reportInsights";
    }

    protected function performanceUrl(string $locationName): string
    {
        return "https://businessprofileperformance.googleapis.com/v1/{$locationName}:fetchMultiDailyMetricsTimeSeries";
    }
}
