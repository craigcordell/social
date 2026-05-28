<?php

use App\Models\ConnectedAccount;
use App\Models\OAuthDebugAttempt;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('redirects to instagram business oauth with the configured scopes', function (): void {
    config([
        'services.instagram.client_id' => 'app-123',
        'services.instagram.redirect' => 'http://localhost/oauth/instagram/callback',
        'services.instagram.scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);

    $response = $this
        ->actingAs(User::factory()->create())
        ->get(route('oauth.instagram.redirect', ['owner_id' => $owner->id]));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://www.instagram.com/oauth/authorize?')
        ->and($query['client_id'])->toBe('app-123')
        ->and($query['redirect_uri'])->toBe('http://localhost/oauth/instagram/callback')
        ->and($query['response_type'])->toBe('code')
        ->and($query['scope'])->toBe('instagram_business_basic,instagram_business_content_publish')
        ->and($query['state'])->not->toBeEmpty();

    expect(Cache::get("instagram_oauth_state:{$query['state']}"))
        ->toBe(['owner_id' => (string) $owner->id]);
});

it('stores an instagram connected account from the oauth callback', function (): void {
    config([
        'services.instagram.client_id' => 'app-123',
        'services.instagram.client_secret' => 'secret-123',
        'services.instagram.redirect' => 'http://localhost/oauth/instagram/callback',
        'services.instagram.scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
    ]);

    Http::fake([
        'api.instagram.com/oauth/access_token' => Http::response([
            'access_token' => 'short-token',
            'user_id' => 'ig-user-1',
        ]),
        'graph.instagram.com/access_token*' => Http::response([
            'access_token' => 'long-token',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ]),
        'graph.instagram.com/v25.0/me*' => Http::response([
            'id' => 'ig-user-1',
            'username' => 'claytonhouse',
            'account_type' => 'BUSINESS',
            'media_count' => 42,
        ]),
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);

    $this->actingAs(User::factory()->create())
        ->withSession([
            'instagram_oauth_owner_id' => $owner->id,
            'instagram_oauth_state' => 'state-123',
        ])
        ->get(route('oauth.instagram.callback', ['code' => 'auth-code', 'state' => 'state-123']))
        ->assertRedirect(route('connected-accounts.index'));

    $account = ConnectedAccount::query()->where('provider', 'instagram')->firstOrFail();

    expect($account->owner_id)->toBe($owner->id)
        ->and($account->provider_account_id)->toBe('ig-user-1')
        ->and($account->provider_account_type)->toBe('instagram_business')
        ->and($account->display_name)->toBe('claytonhouse')
        ->and($account->access_token)->toBe('long-token')
        ->and($account->scopes)->toBe(['instagram_business_basic', 'instagram_business_content_publish'])
        ->and($account->metadata['account_type'])->toBe('BUSINESS')
        ->and($account->token_expires_at)->not->toBeNull();

    expect(OAuthDebugAttempt::query()->where('provider', 'instagram')->where('status', 'connected')->exists())->toBeTrue();
});

it('stores an instagram connected account when the callback session is not present', function (): void {
    config([
        'services.instagram.client_id' => 'app-123',
        'services.instagram.client_secret' => 'secret-123',
        'services.instagram.redirect' => 'http://localhost/oauth/instagram/callback',
        'services.instagram.scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
    ]);

    Http::fake([
        'api.instagram.com/oauth/access_token' => Http::response([
            'access_token' => 'short-token',
            'user_id' => 'ig-user-1',
        ]),
        'graph.instagram.com/access_token*' => Http::response([
            'access_token' => 'long-token',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ]),
        'graph.instagram.com/v25.0/me*' => Http::response([
            'id' => 'ig-user-1',
            'username' => 'claytonhouse',
            'account_type' => 'BUSINESS',
            'media_count' => 42,
        ]),
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);

    Cache::put('instagram_oauth_state:state-123', ['owner_id' => $owner->id], now()->addMinutes(15));

    $this->get(route('oauth.instagram.callback', ['code' => 'auth-code', 'state' => 'state-123']))
        ->assertRedirect(route('connected-accounts.index'));

    expect(ConnectedAccount::query()->where('provider', 'instagram')->where('provider_account_id', 'ig-user-1')->exists())
        ->toBeTrue();
});

it('redirects with a debug record when instagram callback state is invalid', function (): void {
    $this->get(route('oauth.instagram.callback', ['code' => 'auth-code', 'state' => 'missing-state']))
        ->assertRedirect(route('connected-accounts.index'));

    $debugAttempt = OAuthDebugAttempt::query()->where('provider', 'instagram')->latest()->firstOrFail();

    expect($debugAttempt->status)->toBe('invalid_state')
        ->and($debugAttempt->error_message)->toContain('state');
});
