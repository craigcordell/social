<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\OAuthDebugAttempt;
use App\Models\Owner;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class FacebookOAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:owners,id'],
        ]);

        session(['facebook_oauth_owner_id' => $data['owner_id']]);

        $driver = Socialite::driver('facebook');
        $loginConfigId = config('services.facebook.login_config_id');

        if (filled($loginConfigId)) {
            return $driver
                ->setScopes([])
                ->with([
                    'config_id' => $loginConfigId,
                    'auth_type' => 'rerequest',
                    'override_default_response_type' => true,
                ])
                ->redirect();
        }

        return $driver
            ->setScopes(config('services.facebook.scopes', []))
            ->with(['auth_type' => 'rerequest'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $ownerId = session()->pull('facebook_oauth_owner_id');
        $owner = $ownerId ? Owner::query()->find($ownerId) : null;

        abort_unless($owner, 403);

        $debugAttempt = OAuthDebugAttempt::query()->create([
            'provider' => 'facebook',
            'owner_id' => $owner->id,
            'status' => 'callback_received',
            'callback_query' => $this->sanitizePayload($request->query()),
        ]);

        try {
            $facebookUser = Socialite::driver('facebook')->user();
            $version = config('social.providers.facebook.graph_version', 'v25.0');

            $debugAttempt->forceFill([
                'status' => 'token_received',
                'token_summary' => [
                    'facebook_user_id' => $facebookUser->id,
                    'approved_scopes' => $facebookUser->approvedScopes ?? [],
                    'token_expires_in' => $facebookUser->expiresIn ?? null,
                    'token_prefix' => $this->tokenPrefix($facebookUser->token),
                ],
            ])->save();

            $permissionsRaw = Http::acceptJson()
                ->withToken($facebookUser->token)
                ->get("https://graph.facebook.com/{$version}/me/permissions")
                ->throw()
                ->json();

            $permissions = $permissionsRaw['data'] ?? [];

            [$pages, $pagesRaw] = $this->discoverPages($facebookUser->token, $version);

            $debugAttempt->forceFill([
                'status' => 'graph_responses_received',
                'permissions_response' => $this->sanitizePayload($permissionsRaw),
                'pages_response' => $this->sanitizePayload($pagesRaw),
            ])->save();
        } catch (Throwable $exception) {
            $debugAttempt->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        Log::info('Facebook OAuth pages response.', [
            'owner_id' => $owner->id,
            'facebook_user_id' => $facebookUser->id,
            'page_count' => count($pages),
            'permissions' => $permissions,
            'pages' => collect($pages)->map(fn (array $page): array => [
                'id' => $page['id'] ?? null,
                'name' => $page['name'] ?? null,
                'has_access_token' => filled($page['access_token'] ?? null),
                'source' => $page['_source'] ?? null,
                'tasks' => $page['tasks'] ?? [],
            ])->all(),
        ]);

        session()->flash('facebook_oauth_debug', [
            'facebook_user_id' => $facebookUser->id,
            'requested_scopes' => config('services.facebook.scopes', []),
            'login_config_id' => config('services.facebook.login_config_id'),
            'permissions' => $permissions,
            'page_count' => count($pages),
            'pages' => collect($pages)->map(fn (array $page): array => [
                'id' => $page['id'] ?? null,
                'name' => $page['name'] ?? null,
                'has_access_token' => filled($page['access_token'] ?? null),
                'source' => $page['_source'] ?? null,
                'tasks' => $page['tasks'] ?? [],
            ])->all(),
        ]);

        if ($pages === []) {
            $debugAttempt->forceFill(['status' => 'no_pages_returned'])->save();

            return redirect()
                ->route('connected-accounts.index')
                ->with('warning', 'Facebook login succeeded, but Meta did not return any Pages. If /me/accounts is empty and business edges show missing permissions, add business_management or use a Business Login configuration that grants Page assets.');
        }

        $connectedCount = 0;

        foreach ($pages as $page) {
            if (blank($page['access_token'] ?? null)) {
                Log::warning('Facebook Page skipped because no Page access token was returned.', [
                    'owner_id' => $owner->id,
                    'page_id' => $page['id'] ?? null,
                    'page_name' => $page['name'] ?? null,
                    'tasks' => $page['tasks'] ?? [],
                ]);

                continue;
            }

            ConnectedAccount::query()->updateOrCreate(
                [
                    'provider' => 'facebook',
                    'provider_account_id' => $page['id'],
                ],
                [
                    'owner_id' => $owner->id,
                    'provider_account_type' => 'page',
                    'display_name' => $page['name'],
                    'access_token' => $page['access_token'] ?? null,
                    'refresh_token' => null,
                    'token_expires_at' => null,
                    'scopes' => config('services.facebook.scopes', []),
                    'metadata' => [
                        'category' => $page['category'] ?? null,
                        'tasks' => $page['tasks'] ?? [],
                        'source' => $page['_source'] ?? null,
                    ],
                    'status' => ConnectedAccount::STATUS_ACTIVE,
                    'last_connected_at' => now(),
                ],
            );

            $connectedCount++;
        }

        if ($connectedCount === 0) {
            $debugAttempt->forceFill(['status' => 'no_page_tokens_returned'])->save();

            return redirect()
                ->route('connected-accounts.index')
                ->with('warning', 'Facebook returned Pages, but none included a Page access token. Add Page management permissions and confirm your Facebook user has sufficient Page access.');
        }

        $debugAttempt->forceFill(['status' => 'connected'])->save();

        return redirect()
            ->route('connected-accounts.index')
            ->with('status', "{$connectedCount} Facebook Page connection(s) saved.");
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    protected function discoverPages(string $token, string $version): array
    {
        $raw = [];
        $pages = [];

        $meAccounts = $this->graphGet($token, $version, 'me/accounts', [
            'fields' => 'id,name,access_token,category,tasks',
        ]);
        $raw['me_accounts'] = $meAccounts;
        $pages = array_merge($pages, $this->tagPages($meAccounts['data'] ?? [], 'me/accounts'));

        $assignedPages = $this->graphGet($token, $version, 'me/assigned_pages', [
            'fields' => 'id,name,access_token,category,tasks',
        ]);
        $raw['me_assigned_pages'] = $assignedPages;
        $pages = array_merge($pages, $this->tagPages($assignedPages['data'] ?? [], 'me/assigned_pages'));

        $businesses = $this->graphGet($token, $version, 'me/businesses', [
            'fields' => 'id,name',
        ]);
        $raw['me_businesses'] = $businesses;
        $raw['business_pages'] = [];

        foreach ($businesses['data'] ?? [] as $business) {
            foreach (['owned_pages', 'client_pages'] as $edge) {
                $businessPages = $this->graphGet($token, $version, $business['id'].'/'.$edge, [
                    'fields' => 'id,name,access_token,category,tasks',
                ]);

                $raw['business_pages'][] = [
                    'business_id' => $business['id'] ?? null,
                    'business_name' => $business['name'] ?? null,
                    'edge' => $edge,
                    'response' => $businessPages,
                ];

                $pages = array_merge($pages, $this->tagPages($businessPages['data'] ?? [], "business/{$edge}"));
            }
        }

        return [$this->uniquePages($pages), $raw];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function graphGet(string $token, string $version, string $path, array $query = []): array
    {
        try {
            return Http::acceptJson()
                ->withToken($token)
                ->get("https://graph.facebook.com/{$version}/{$path}", $query)
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            return [
                '_error' => $exception->response->json() ?: [
                    'message' => $exception->getMessage(),
                    'body' => $exception->response->body(),
                ],
                '_status' => $exception->response->status(),
            ];
        } catch (Throwable $exception) {
            return [
                '_error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, mixed>>
     */
    protected function tagPages(array $pages, string $source): array
    {
        return collect($pages)
            ->map(function (array $page) use ($source): array {
                $page['_source'] = $source;

                return $page;
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, mixed>>
     */
    protected function uniquePages(array $pages): array
    {
        return collect($pages)
            ->filter(fn (array $page): bool => filled($page['id'] ?? null))
            ->unique('id')
            ->values()
            ->all();
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
}
