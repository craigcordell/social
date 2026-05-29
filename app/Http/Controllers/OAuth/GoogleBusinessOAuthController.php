<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\OAuthDebugAttempt;
use App\Models\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GoogleBusinessOAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:owners,id'],
        ]);

        $state = Str::random(40);

        session([
            'google_business_oauth_owner_id' => $data['owner_id'],
            'google_business_oauth_state' => $state,
        ]);

        Cache::put($this->stateCacheKey($state), [
            'owner_id' => $data['owner_id'],
        ], now()->addMinutes(15));

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_business.client_id'),
            'redirect_uri' => config('services.google_business.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', config('services.google_business.scopes', [])),
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');
        $cachedState = is_string($state) ? Cache::pull($this->stateCacheKey($state)) : null;
        $ownerId = $cachedState['owner_id'] ?? session()->pull('google_business_oauth_owner_id');
        $expectedState = session()->pull('google_business_oauth_state');
        $owner = $ownerId ? Owner::query()->find($ownerId) : null;

        if (! $owner || ($cachedState === null && ! hash_equals((string) $expectedState, (string) $state))) {
            OAuthDebugAttempt::query()->create([
                'provider' => 'gmb',
                'owner_id' => $owner?->id,
                'status' => 'invalid_state',
                'callback_query' => $this->sanitizePayload($request->query()),
                'error_message' => 'Google Business OAuth callback state was missing, expired, or did not match the active session.',
            ]);

            return redirect()
                ->route('connected-accounts.index')
                ->with('warning', 'Google Business login could not be verified. Please start the connection again from this page.');
        }

        $debugAttempt = OAuthDebugAttempt::query()->create([
            'provider' => 'gmb',
            'owner_id' => $owner->id,
            'status' => 'callback_received',
            'callback_query' => $this->sanitizePayload($request->query()),
        ]);

        if ($request->filled('error') || ! $request->filled('code')) {
            $debugAttempt->forceFill([
                'status' => 'denied_or_failed',
                'error_message' => $request->string('error_description')->toString() ?: 'Google Business login was cancelled, denied, or did not return an authorization code.',
            ])->save();

            return redirect()
                ->route('connected-accounts.index')
                ->with('warning', 'Google Business login was cancelled or denied.');
        }

        try {
            $token = $this->exchangeCodeForToken($request->string('code')->toString());

            $debugAttempt->forceFill([
                'status' => 'token_received',
                'token_summary' => [
                    'token_prefix' => $this->tokenPrefix($token['access_token'] ?? null),
                    'refresh_token_returned' => filled($token['refresh_token'] ?? null),
                    'expires_in' => $token['expires_in'] ?? null,
                    'scope' => $token['scope'] ?? null,
                ],
                'permissions_response' => [
                    'requested_scopes' => config('services.google_business.scopes', []),
                    'granted_scope' => $token['scope'] ?? null,
                ],
            ])->save();

            [$accounts, $locations, $rawResponses] = $this->discoverLocations($token['access_token']);

            $debugAttempt->forceFill([
                'status' => 'graph_responses_received',
                'pages_response' => $this->sanitizePayload($rawResponses),
            ])->save();
        } catch (Throwable $exception) {
            $debugAttempt->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        if ($locations === []) {
            $debugAttempt->forceFill(['status' => 'no_locations_returned'])->save();

            return redirect()
                ->route('connected-accounts.index')
                ->with('warning', 'Google Business login succeeded, but no Business Profile locations were returned. Confirm the Google user manages at least one profile and the app has business.manage.');
        }

        $connectedCount = 0;

        foreach ($locations as $location) {
            $existingAccount = ConnectedAccount::query()
                ->where('provider', 'gmb')
                ->where('provider_account_id', $location['name'])
                ->first();

            ConnectedAccount::query()->updateOrCreate(
                [
                    'provider' => 'gmb',
                    'provider_account_id' => $location['name'],
                ],
                [
                    'owner_id' => $owner->id,
                    'provider_account_type' => 'google_business_location',
                    'display_name' => $location['title'] ?? $location['name'],
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? $existingAccount?->refresh_token,
                    'token_expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null,
                    'scopes' => $this->scopes($token),
                    'metadata' => [
                        'account_name' => $location['_account']['name'] ?? null,
                        'account_display_name' => $location['_account']['accountName'] ?? null,
                        'account_type' => $location['_account']['type'] ?? null,
                        'location_title' => $location['title'] ?? null,
                        'place_id' => data_get($location, 'metadata.placeId'),
                    ],
                    'status' => ConnectedAccount::STATUS_ACTIVE,
                    'last_connected_at' => now(),
                ],
            );

            $connectedCount++;
        }

        $debugAttempt->forceFill(['status' => 'connected'])->save();

        session()->flash('google_business_oauth_debug', [
            'account_count' => count($accounts),
            'location_count' => count($locations),
            'locations' => collect($locations)->map(fn (array $location): array => [
                'name' => $location['name'] ?? null,
                'title' => $location['title'] ?? null,
                'place_id' => data_get($location, 'metadata.placeId'),
                'account_name' => $location['_account']['accountName'] ?? $location['_account']['name'] ?? null,
            ])->all(),
        ]);

        return redirect()
            ->route('connected-accounts.index')
            ->with('status', "{$connectedCount} Google Business location connection(s) saved.");
    }

    /**
     * @return array<string, mixed>
     */
    protected function exchangeCodeForToken(string $code): array
    {
        return Http::asForm()
            ->acceptJson()
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_business.client_id'),
                'client_secret' => config('services.google_business.client_secret'),
                'redirect_uri' => config('services.google_business.redirect'),
                'grant_type' => 'authorization_code',
                'code' => $code,
            ])
            ->throw()
            ->json();
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<string, mixed>}
     */
    protected function discoverLocations(string $accessToken): array
    {
        $accountsRaw = Http::acceptJson()
            ->withToken($accessToken)
            ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts')
            ->throw()
            ->json();

        $accounts = $accountsRaw['accounts'] ?? [];
        $locations = [];
        $locationResponses = [];

        foreach ($accounts as $account) {
            if (blank($account['name'] ?? null)) {
                continue;
            }

            $locationsRaw = $this->businessInfoLocations($accessToken, $account['name']);
            $locationResponses[$account['name']] = $locationsRaw;

            foreach ($locationsRaw['locations'] ?? [] as $location) {
                if (blank($location['name'] ?? null)) {
                    continue;
                }

                $location['_account'] = $account;
                $locations[] = $location;
            }
        }

        return [
            $accounts,
            $locations,
            [
                'accounts' => $accountsRaw,
                'locations' => $locationResponses,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function businessInfoLocations(string $accessToken, string $accountName): array
    {
        $locations = [];
        $nextPageToken = null;
        $rawResponses = [];

        do {
            $query = [
                'readMask' => 'name,title,metadata',
            ];

            if ($nextPageToken) {
                $query['pageToken'] = $nextPageToken;
            }

            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->get("https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations", $query)
                ->throw()
                ->json();

            $rawResponses[] = $response;
            $locations = array_merge($locations, $response['locations'] ?? []);
            $nextPageToken = $response['nextPageToken'] ?? null;
        } while ($nextPageToken);

        return [
            'locations' => $locations,
            'raw' => $rawResponses,
        ];
    }

    /**
     * @param  array<string, mixed>  $token
     * @return array<int, string>
     */
    protected function scopes(array $token): array
    {
        if (filled($token['scope'] ?? null)) {
            return preg_split('/\s+/', trim((string) $token['scope'])) ?: [];
        }

        return config('services.google_business.scopes', []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function sanitizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sanitizePayload($value);

                continue;
            }

            if (str_contains(strtolower((string) $key), 'token')) {
                $payload[$key] = $this->tokenPrefix((string) $value);
            }
        }

        return $payload;
    }

    protected function tokenPrefix(?string $token): ?string
    {
        if (blank($token)) {
            return null;
        }

        return substr($token, 0, 8).'...masked';
    }

    protected function stateCacheKey(string $state): string
    {
        return "google_business_oauth_state:{$state}";
    }
}
