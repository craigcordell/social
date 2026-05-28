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

class InstagramOAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:owners,id'],
        ]);

        $state = Str::random(40);

        session([
            'instagram_oauth_owner_id' => $data['owner_id'],
            'instagram_oauth_state' => $state,
        ]);

        Cache::put($this->stateCacheKey($state), [
            'owner_id' => $data['owner_id'],
        ], now()->addMinutes(15));

        return redirect()->away('https://www.instagram.com/oauth/authorize?'.http_build_query([
            'enable_fb_login' => 0,
            'force_authentication' => 1,
            'client_id' => config('services.instagram.client_id'),
            'redirect_uri' => config('services.instagram.redirect'),
            'response_type' => 'code',
            'scope' => implode(',', config('services.instagram.scopes', [])),
            'state' => $state,
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');
        $cachedState = is_string($state) ? Cache::pull($this->stateCacheKey($state)) : null;
        $ownerId = $cachedState['owner_id'] ?? session()->pull('instagram_oauth_owner_id');
        $expectedState = session()->pull('instagram_oauth_state');
        $owner = $ownerId ? Owner::query()->find($ownerId) : null;

        if (! $owner || ($cachedState === null && ! hash_equals((string) $expectedState, (string) $state))) {
            OAuthDebugAttempt::query()->create([
                'provider' => 'instagram',
                'owner_id' => $owner?->id,
                'status' => 'invalid_state',
                'callback_query' => $this->sanitizePayload($request->query()),
                'error_message' => 'Instagram OAuth callback state was missing, expired, or did not match the active session.',
            ]);

            return redirect()
                ->route('connected-accounts.index')
                ->with('warning', 'Instagram login could not be verified. Please start the connection again from this page.');
        }

        $debugAttempt = OAuthDebugAttempt::query()->create([
            'provider' => 'instagram',
            'owner_id' => $owner->id,
            'status' => 'callback_received',
            'callback_query' => $this->sanitizePayload($request->query()),
        ]);

        try {
            if ($request->filled('error')) {
                return redirect()
                    ->route('connected-accounts.index')
                    ->with('warning', 'Instagram login was cancelled or denied.');
            }

            $token = $this->exchangeCodeForToken($request->string('code')->toString());
            $longLivedToken = $this->exchangeForLongLivedToken($token['access_token']) ?? $token;
            $profile = $this->profile($longLivedToken['access_token']);

            $debugAttempt->forceFill([
                'status' => 'token_received',
                'token_summary' => [
                    'instagram_user_id' => $profile['id'] ?? $token['user_id'] ?? null,
                    'username' => $profile['username'] ?? null,
                    'account_type' => $profile['account_type'] ?? null,
                    'token_expires_in' => $longLivedToken['expires_in'] ?? null,
                    'token_prefix' => $this->tokenPrefix($longLivedToken['access_token']),
                ],
                'permissions_response' => [
                    'requested_scopes' => config('services.instagram.scopes', []),
                ],
                'pages_response' => $this->sanitizePayload($profile),
            ])->save();
        } catch (Throwable $exception) {
            $debugAttempt->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        ConnectedAccount::query()->updateOrCreate(
            [
                'provider' => 'instagram',
                'provider_account_id' => $profile['id'] ?? $token['user_id'],
            ],
            [
                'owner_id' => $owner->id,
                'provider_account_type' => 'instagram_business',
                'display_name' => $profile['username'] ?? 'Instagram Account',
                'access_token' => $longLivedToken['access_token'],
                'refresh_token' => null,
                'token_expires_at' => isset($longLivedToken['expires_in']) ? now()->addSeconds($longLivedToken['expires_in']) : null,
                'scopes' => config('services.instagram.scopes', []),
                'metadata' => [
                    'account_type' => $profile['account_type'] ?? null,
                    'media_count' => $profile['media_count'] ?? null,
                ],
                'status' => ConnectedAccount::STATUS_ACTIVE,
                'last_connected_at' => now(),
            ],
        );

        $debugAttempt->forceFill(['status' => 'connected'])->save();

        return redirect()
            ->route('connected-accounts.index')
            ->with('status', 'Instagram connection saved.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function exchangeCodeForToken(string $code): array
    {
        return Http::asForm()
            ->acceptJson()
            ->post('https://api.instagram.com/oauth/access_token', [
                'client_id' => config('services.instagram.client_id'),
                'client_secret' => config('services.instagram.client_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.instagram.redirect'),
                'code' => $code,
            ])
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function exchangeForLongLivedToken(string $accessToken): ?array
    {
        try {
            return Http::acceptJson()
                ->get('https://graph.instagram.com/access_token', [
                    'grant_type' => 'ig_exchange_token',
                    'client_secret' => config('services.instagram.client_secret'),
                    'access_token' => $accessToken,
                ])
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function profile(string $accessToken): array
    {
        $version = config('social.providers.instagram.graph_version', 'v25.0');

        return Http::acceptJson()
            ->withToken($accessToken)
            ->get("https://graph.instagram.com/{$version}/me", [
                'fields' => 'id,username,account_type,media_count',
            ])
            ->throw()
            ->json();
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
        return "instagram_oauth_state:{$state}";
    }
}
