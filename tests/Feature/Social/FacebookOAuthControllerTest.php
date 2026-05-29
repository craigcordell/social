<?php

use App\Http\Controllers\OAuth\FacebookOAuthController;
use App\Models\ConnectedAccount;
use App\Models\OAuthDebugAttempt;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

it('redirects to facebook oauth with cached state and ayrshare-compatible scopes', function (): void {
    config([
        'services.facebook.client_id' => 'app-123',
        'services.facebook.redirect' => 'https://social.test/oauth/facebook/callback',
        'services.facebook.scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement', 'pages_read_user_content'],
        'services.facebook.login_config_id' => null,
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);

    $response = $this
        ->actingAs(User::factory()->create())
        ->get(route('oauth.facebook.redirect', ['owner_id' => $owner->id]));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://www.facebook.com/v23.0/dialog/oauth?')
        ->and($query['client_id'])->toBe('app-123')
        ->and($query['redirect_uri'])->toBe('https://social.test/oauth/facebook/callback')
        ->and($query['response_type'])->toBe('code')
        ->and($query['auth_type'])->toBe('rerequest')
        ->and($query['scope'])->toBe('pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement,pages_read_user_content')
        ->and($query['state'])->not->toBeEmpty();

    expect(Cache::get("facebook_oauth_state:{$query['state']}"))
        ->toBe(['owner_id' => (string) $owner->id]);
});

it('redirects to facebook oauth with a login configuration when configured', function (): void {
    config([
        'services.facebook.client_id' => 'app-123',
        'services.facebook.redirect' => 'https://social.test/oauth/facebook/callback',
        'services.facebook.scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement', 'pages_read_user_content'],
        'services.facebook.login_config_id' => 'config-123',
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);

    $response = $this
        ->actingAs(User::factory()->create())
        ->get(route('oauth.facebook.redirect', ['owner_id' => $owner->id]));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);

    expect($query['config_id'])->toBe('config-123')
        ->and($query['override_default_response_type'])->toBe('1')
        ->and($query)->not->toHaveKey('scope');
});

it('stores a facebook page when the callback session is not present', function (): void {
    config([
        'services.facebook.scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement', 'pages_read_user_content'],
        'social.providers.facebook.graph_version' => 'v25.0',
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);

    Cache::put('facebook_oauth_state:state-123', ['owner_id' => $owner->id], now()->addMinutes(15));

    $provider = Mockery::mock();
    $provider->shouldReceive('stateless')->once()->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn(tap(new SocialiteUser, function (SocialiteUser $user): void {
        $user->id = 'facebook-user-1';
        $user->token = 'user-token';
        $user->expiresIn = null;
        $user->approvedScopes = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement', 'pages_read_user_content'];
    }));

    Socialite::shouldReceive('driver')->with('facebook')->once()->andReturn($provider);

    Http::fake([
        'graph.facebook.com/v25.0/me/permissions*' => Http::response([
            'data' => [
                ['permission' => 'pages_show_list', 'status' => 'granted'],
                ['permission' => 'pages_read_engagement', 'status' => 'granted'],
                ['permission' => 'pages_manage_posts', 'status' => 'granted'],
                ['permission' => 'pages_manage_engagement', 'status' => 'granted'],
                ['permission' => 'pages_read_user_content', 'status' => 'granted'],
            ],
        ]),
        'graph.facebook.com/v25.0/me/accounts*' => Http::response([
            'data' => [
                [
                    'id' => '358179240887925',
                    'name' => 'Clayton House Marketplace',
                    'access_token' => 'page-token',
                    'category' => 'Furniture store',
                    'tasks' => ['CREATE_CONTENT', 'MANAGE'],
                ],
            ],
        ]),
    ]);

    $this->get(route('oauth.facebook.callback', ['code' => 'auth-code', 'state' => 'state-123']))
        ->assertRedirect(route('connected-accounts.index'));

    $account = ConnectedAccount::query()->where('provider', 'facebook')->firstOrFail();

    expect($account->owner_id)->toBe($owner->id)
        ->and($account->provider_account_id)->toBe('358179240887925')
        ->and($account->provider_account_type)->toBe('page')
        ->and($account->display_name)->toBe('Clayton House Marketplace')
        ->and($account->access_token)->toBe('page-token')
        ->and($account->scopes)->toBe(['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement', 'pages_read_user_content']);

    expect(OAuthDebugAttempt::query()->where('provider', 'facebook')->where('status', 'connected')->exists())->toBeTrue();
});

it('redirects with a debug record when facebook callback state is invalid', function (): void {
    $this->get(route('oauth.facebook.callback', ['code' => 'auth-code', 'state' => 'missing-state']))
        ->assertRedirect(route('connected-accounts.index'));

    $debugAttempt = OAuthDebugAttempt::query()->where('provider', 'facebook')->latest()->firstOrFail();

    expect($debugAttempt->status)->toBe('invalid_state')
        ->and($debugAttempt->error_message)->toContain('state');
});

it('redirects with a debug record when facebook returns an oauth error', function (): void {
    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);

    Cache::put('facebook_oauth_state:state-123', ['owner_id' => $owner->id], now()->addMinutes(15));

    $this->get(route('oauth.facebook.callback', [
        'error_code' => '1349048',
        'error_message' => 'Cannot load URL.',
        'state' => 'state-123',
    ]))->assertRedirect(route('connected-accounts.index'));

    $debugAttempt = OAuthDebugAttempt::query()->where('provider', 'facebook')->latest()->firstOrFail();

    expect($debugAttempt->status)->toBe('denied_or_failed')
        ->and($debugAttempt->error_message)->toBe('Cannot load URL.');
});

it('discovers facebook pages through the narrow me accounts edge only', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/v25.0/me/accounts*' => Http::response([
            'data' => [
                [
                    'id' => '358179240887925',
                    'name' => 'Clayton House Marketplace',
                    'access_token' => 'page-token',
                    'category' => 'Furniture store',
                    'tasks' => ['CREATE_CONTENT', 'MANAGE'],
                ],
            ],
        ]),
    ]);

    $controller = new class extends FacebookOAuthController
    {
        public function callDiscoverPages(): array
        {
            return $this->discoverPages('user-token', 'v25.0');
        }
    };

    [$pages, $raw] = $controller->callDiscoverPages();

    expect($pages)->toHaveCount(1)
        ->and($pages[0]['id'])->toBe('358179240887925')
        ->and($pages[0]['_source'])->toBe('me/accounts')
        ->and($raw)->toHaveKey('me_accounts')
        ->and($raw)->not->toHaveKeys(['me_assigned_pages', 'me_businesses', 'business_pages']);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v25.0/me/accounts'));
});
