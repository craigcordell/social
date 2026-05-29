<?php

use App\Models\ConnectedAccount;
use App\Models\OAuthDebugAttempt;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('redirects to google business oauth with offline access and consent prompt', function (): void {
    config([
        'services.google_business.client_id' => 'google-client-123',
        'services.google_business.redirect' => 'https://social.test/oauth/google-business/callback',
        'services.google_business.scopes' => ['https://www.googleapis.com/auth/business.manage'],
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);

    $response = $this
        ->actingAs(User::factory()->create())
        ->get(route('oauth.google-business.redirect', ['owner_id' => $owner->id]));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['client_id'])->toBe('google-client-123')
        ->and($query['redirect_uri'])->toBe('https://social.test/oauth/google-business/callback')
        ->and($query['response_type'])->toBe('code')
        ->and($query['scope'])->toBe('https://www.googleapis.com/auth/business.manage')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['prompt'])->toBe('consent')
        ->and($query['state'])->not->toBeEmpty();

    expect(Cache::get("google_business_oauth_state:{$query['state']}"))
        ->toBe(['owner_id' => (string) $owner->id]);
});

it('stores all returned google business locations from the oauth callback', function (): void {
    config([
        'services.google_business.client_id' => 'google-client-123',
        'services.google_business.client_secret' => 'google-secret-123',
        'services.google_business.redirect' => 'https://social.test/oauth/google-business/callback',
        'services.google_business.scopes' => ['https://www.googleapis.com/auth/business.manage'],
    ]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'expires_in' => 3600,
            'scope' => 'https://www.googleapis.com/auth/business.manage',
            'token_type' => 'Bearer',
        ]),
        'mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response([
            'accounts' => [
                [
                    'name' => 'accounts/123',
                    'accountName' => 'Clayton House',
                    'type' => 'PERSONAL',
                ],
            ],
        ]),
        'mybusinessbusinessinformation.googleapis.com/v1/accounts/123/locations*' => Http::response([
            'locations' => [
                [
                    'name' => 'accounts/123/locations/456',
                    'title' => 'Clayton House Marketplace',
                    'metadata' => [
                        'placeId' => 'place-456',
                    ],
                ],
                [
                    'name' => 'accounts/123/locations/789',
                    'title' => 'Clayton House Outlet',
                    'metadata' => [
                        'placeId' => 'place-789',
                    ],
                ],
            ],
        ]),
    ]);

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);

    $this->actingAs(User::factory()->create())
        ->withSession([
            'google_business_oauth_owner_id' => $owner->id,
            'google_business_oauth_state' => 'state-123',
        ])
        ->get(route('oauth.google-business.callback', ['code' => 'auth-code', 'state' => 'state-123']))
        ->assertRedirect(route('connected-accounts.index'));

    $accounts = ConnectedAccount::query()->where('provider', 'gmb')->orderBy('provider_account_id')->get();

    expect($accounts)->toHaveCount(2)
        ->and($accounts[0]->owner_id)->toBe($owner->id)
        ->and($accounts[0]->provider_account_type)->toBe('google_business_location')
        ->and($accounts[0]->provider_account_id)->toBe('accounts/123/locations/456')
        ->and($accounts[0]->display_name)->toBe('Clayton House Marketplace')
        ->and($accounts[0]->access_token)->toBe('google-access-token')
        ->and($accounts[0]->refresh_token)->toBe('google-refresh-token')
        ->and($accounts[0]->scopes)->toBe(['https://www.googleapis.com/auth/business.manage'])
        ->and($accounts[0]->metadata['account_display_name'])->toBe('Clayton House')
        ->and($accounts[0]->metadata['place_id'])->toBe('place-456')
        ->and($accounts[0]->token_expires_at)->not->toBeNull();

    expect(OAuthDebugAttempt::query()->where('provider', 'gmb')->where('status', 'connected')->exists())->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request->data()['grant_type'] === 'authorization_code');
});

it('redirects with a debug record when google business callback state is invalid', function (): void {
    $this->get(route('oauth.google-business.callback', ['code' => 'auth-code', 'state' => 'missing-state']))
        ->assertRedirect(route('connected-accounts.index'));

    $debugAttempt = OAuthDebugAttempt::query()->where('provider', 'gmb')->latest()->firstOrFail();

    expect($debugAttempt->status)->toBe('invalid_state')
        ->and($debugAttempt->error_message)->toContain('state');
});
