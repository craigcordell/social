<?php

use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function metaApiTokenFor(Owner $owner, array $abilities): string
{
    $token = User::factory()->create()->createToken('Meta API test', $abilities);
    $token->accessToken->forceFill(['owner_id' => $owner->id])->save();

    return $token->plainTextToken;
}

function metaInternalOwner(): Owner
{
    return Owner::query()->create([
        'name' => 'Internal',
        'type' => 'internal',
        'external_id' => 'default',
    ]);
}

function metaBudgetFixtureResponse(
    Request $request,
    array $campaigns = [],
    array $adSets = [],
    string $spend = '0.00',
    ?array $ads = null,
): mixed {
    if ($request->method() !== 'GET') {
        return null;
    }

    if (str_contains($request->url(), '/insights')) {
        return Http::response([
            'data' => $spend === '0.00' ? [] : [['spend' => $spend]],
        ]);
    }

    if (str_contains($request->url(), '/campaigns')) {
        return Http::response(['data' => $campaigns]);
    }

    if (str_contains($request->url(), '/adsets')) {
        $campaignsById = collect($campaigns)->keyBy('id');
        $adSets = collect($adSets)
            ->map(function (array $adSet) use ($campaignsById): array {
                $campaign = $campaignsById->get((string) ($adSet['campaign_id'] ?? ''));

                return is_array($campaign)
                    ? [...$adSet, 'campaign' => $campaign]
                    : $adSet;
            })
            ->all();

        return Http::response(['data' => $adSets]);
    }

    if (str_contains($request->url(), '/act_58438981/ads')) {
        $ads ??= collect($adSets)
            ->map(fn (array $adSet, int $index): array => [
                'id' => 'fixture-ad-'.($index + 1),
                'account_id' => '58438981',
                'campaign_id' => $adSet['campaign_id'] ?? null,
                'adset_id' => $adSet['id'] ?? null,
                'status' => 'ACTIVE',
                'effective_status' => 'ACTIVE',
            ])
            ->all();

        return Http::response(['data' => $ads]);
    }

    if (str_contains($request->url(), '/act_58438981')) {
        return Http::response([
            'id' => 'act_58438981',
            'account_id' => '58438981',
            'currency' => 'USD',
            'timezone_name' => 'America/Chicago',
        ]);
    }

    return null;
}

function metaCreateBoostFixtureResponse(Request $request): mixed
{
    $budgetResponse = metaBudgetFixtureResponse($request);

    if ($budgetResponse) {
        return $budgetResponse;
    }

    if ($request->method() === 'GET' && str_contains($request->url(), '/template-ad-set-1')) {
        return Http::response([
            'id' => 'template-ad-set-1',
            'account_id' => '58438981',
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'POST_ENGAGEMENT',
            'destination_type' => 'ON_POST',
            'promoted_object' => ['page_id' => '358179240887925'],
            'targeting' => ['geo_locations' => ['countries' => ['US']]],
        ]);
    }

    return match (true) {
        $request->method() === 'POST' && str_ends_with($request->url(), '/campaigns') => Http::response(['id' => 'campaign-created']),
        $request->method() === 'POST' && str_ends_with($request->url(), '/adsets') => Http::response(['id' => 'ad-set-created']),
        $request->method() === 'POST' && str_ends_with($request->url(), '/adcreatives') => Http::response(['id' => 'creative-created']),
        $request->method() === 'POST' && str_ends_with($request->url(), '/ads') => Http::response(['id' => 'ad-created']),
        default => Http::response([], 404),
    };
}

beforeEach(function (): void {
    config()->set([
        'services.meta_marketing.base_url' => 'https://graph.facebook.com',
        'services.meta_marketing.graph_version' => 'v25.0',
        'services.meta_marketing.access_token' => Str::random(64),
        'services.meta_marketing.app_secret' => Str::random(64),
        'services.meta_marketing.business_id' => '501093076959946',
        'services.meta_marketing.ad_account_id' => '58438981',
        'services.meta_marketing.page_id' => '358179240887925',
        'services.meta_marketing.instagram_account_id' => '17841402997966896',
        'services.meta_marketing.owner_external_id' => 'default',
        'services.meta_marketing.currency' => 'USD',
        'services.meta_marketing.account_daily_limit_minor' => 15000,
        'services.meta_marketing.template_ad_set_id' => 'template-ad-set-1',
        'services.meta_marketing.timeout' => 15,
        'services.meta_marketing.connect_timeout' => 5,
    ]);

    Http::preventStrayRequests();
});

it('requires authentication and the ads read ability', function (): void {
    $this->getJson('/api/v1/meta/status')->assertUnauthorized();

    $owner = Owner::query()->create([
        'name' => 'Internal',
        'type' => 'internal',
        'external_id' => 'default',
    ]);
    $this->withToken(metaApiTokenFor($owner, ['organic:read']))
        ->getJson('/api/v1/meta/status')
        ->assertForbidden();
});

it('returns the configured system user and ad account status', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/me/permissions*' => Http::response([
            'data' => [
                ['permission' => 'ads_management', 'status' => 'granted'],
                ['permission' => 'ads_read', 'status' => 'granted'],
            ],
        ]),
        'graph.facebook.com/v25.0/me*' => Http::response([
            'id' => 'system-user-1',
            'name' => 'SocialClayton',
        ]),
        'graph.facebook.com/v25.0/act_58438981*' => Http::response([
            'id' => 'act_58438981',
            'account_status' => 1,
            'currency' => 'USD',
        ]),
        'graph.facebook.com/v25.0/17841402997966896*' => Http::response([
            'id' => '17841402997966896',
            'username' => 'claytonhousemarketplace',
        ]),
    ]);

    $owner = Owner::query()->create([
        'name' => 'Internal',
        'type' => 'internal',
        'external_id' => 'default',
    ]);
    $systemUserAccessToken = (string) config('services.meta_marketing.access_token');
    $appSecret = (string) config('services.meta_marketing.app_secret');

    $response = $this->withToken(metaApiTokenFor($owner, ['ads:read']))
        ->getJson('/api/v1/meta/status')
        ->assertOk()
        ->assertJsonPath('data.system_user.name', 'SocialClayton')
        ->assertJsonPath('data.ad_account.id', 'act_58438981')
        ->assertJsonPath('data.instagram_account.username', 'claytonhousemarketplace')
        ->assertJsonPath('data.budget_mutations_enabled', true)
        ->assertJsonPath('data.account_daily_limit_minor', 15000);

    expect($response->getContent())->not->toContain($systemUserAccessToken);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$systemUserAccessToken)
        && $request->data()['appsecret_proof'] === hash_hmac('sha256', $systemUserAccessToken, $appSecret));
    Http::assertSentCount(4);
});

it('lists campaigns and removes token-bearing paging urls', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/act_58438981/campaigns*' => Http::response([
            'data' => [[
                'id' => 'campaign-1',
                'name' => 'Post: Example',
                'effective_status' => 'ACTIVE',
                'daily_budget' => '500',
            ]],
            'paging' => [
                'cursors' => ['after' => 'cursor-1'],
                'next' => 'https://graph.facebook.com/v25.0/example?access_token=system-user-token',
            ],
        ]),
    ]);

    $owner = Owner::query()->create([
        'name' => 'Internal',
        'type' => 'internal',
        'external_id' => 'default',
    ]);

    $response = $this->withToken(metaApiTokenFor($owner, ['ads:read']))
        ->getJson('/api/v1/meta/campaigns?limit=10&effective_status[]=ACTIVE')
        ->assertOk()
        ->assertJsonPath('data.data.0.id', 'campaign-1')
        ->assertJsonPath('data.paging.cursors.after', 'cursor-1');

    expect($response->getContent())
        ->not->toContain('system-user-token')
        ->not->toContain('paging.next');

    Http::assertSent(fn (Request $request): bool => $request->data()['effective_status'] === '["ACTIVE"]'
        && $request->data()['limit'] === 10);
});

it('retrieves paid campaign performance for a validated date range', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/act_58438981/insights*' => Http::response([
            'data' => [[
                'campaign_id' => 'campaign-1',
                'spend' => '25.00',
                'impressions' => '1000',
                'clicks' => '20',
            ]],
        ]),
    ]);

    $owner = Owner::query()->create([
        'name' => 'Internal',
        'type' => 'internal',
        'external_id' => 'default',
    ]);

    $this->withToken(metaApiTokenFor($owner, ['ads:read']))
        ->getJson('/api/v1/meta/insights?level=campaign&since=2026-08-01&until=2026-08-29')
        ->assertOk()
        ->assertJsonPath('data.data.0.spend', '25.00');

    Http::assertSent(fn (Request $request): bool => $request->data()['level'] === 'campaign'
        && $request->data()['time_range'] === '{"since":"2026-08-01","until":"2026-08-29"}');
});

it('retrieves instagram organic media insights', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/18127844407740068/insights*' => Http::response([
            'data' => [
                ['name' => 'reach', 'values' => [['value' => 323]]],
                ['name' => 'views', 'values' => [['value' => 525]]],
            ],
        ]),
    ]);

    $owner = Owner::query()->create([
        'name' => 'Internal',
        'type' => 'internal',
        'external_id' => 'default',
    ]);

    $this->withToken(metaApiTokenFor($owner, ['organic:read']))
        ->postJson('/api/v1/meta/organic-insights', [
            'platform' => 'instagram',
            'post_id' => '18127844407740068',
        ])
        ->assertOk()
        ->assertJsonPath('data.data.0.name', 'reach');
});

it('rejects facebook post ids outside the configured page', function (): void {
    $owner = Owner::query()->create([
        'name' => 'Internal',
        'type' => 'internal',
        'external_id' => 'default',
    ]);

    $this->withToken(metaApiTokenFor($owner, ['organic:read']))
        ->postJson('/api/v1/meta/organic-insights', [
            'platform' => 'facebook',
            'post_id' => 'other-page_post-1',
        ])
        ->assertUnprocessable();

    Http::assertNothingSent();
});

it('does not allow another owner to use the configured meta account', function (): void {
    $owner = Owner::query()->create([
        'name' => 'Other',
        'type' => 'vendor',
        'external_id' => 'other',
    ]);

    $this->withToken(metaApiTokenFor($owner, ['ads:read']))
        ->getJson('/api/v1/meta/status')
        ->assertForbidden();

    Http::assertNothingSent();
});

it('scopes connected accounts to the api token owner', function (): void {
    $owner = Owner::query()->create([
        'name' => 'Internal',
        'type' => 'internal',
        'external_id' => 'default',
    ]);
    $otherOwner = Owner::query()->create([
        'name' => 'Other',
        'type' => 'vendor',
        'external_id' => 'other',
    ]);

    foreach ([$owner, $otherOwner] as $index => $accountOwner) {
        ConnectedAccount::query()->create([
            'owner_id' => $accountOwner->id,
            'provider' => 'facebook',
            'provider_account_id' => 'page-'.$index,
            'provider_account_type' => 'page',
            'display_name' => $accountOwner->name,
            'access_token' => 'secret-'.$index,
            'status' => ConnectedAccount::STATUS_ACTIVE,
        ]);
    }

    $this->withToken(metaApiTokenFor($owner, ['*']))
        ->getJson('/api/connected-accounts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.owner.external_id', 'default');
});

it('requires the ads manage ability for spend mutations', function (): void {
    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:read']))
        ->postJson('/api/v1/meta/boosts', [], ['Idempotency-Key' => 'boost-ability-test'])
        ->assertForbidden();

    Http::assertNothingSent();
});

it('reports the account wide daily budget position across campaign and ad set budgets', function (): void {
    $campaigns = [
        [
            'id' => 'campaign-budgeted',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'daily_budget' => '8000',
        ],
        [
            'id' => 'campaign-adset-budgeted',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
        ],
    ];
    $adSets = [[
        'id' => 'ad-set-under-campaign-budget',
        'campaign_id' => 'campaign-budgeted',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
    ], [
        'id' => 'ad-set-budgeted',
        'campaign_id' => 'campaign-adset-budgeted',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
        'daily_budget' => '2000',
    ]];

    Http::fake(fn (Request $request): mixed => metaBudgetFixtureResponse($request, $campaigns, $adSets, '10.00')
        ?? Http::response([], 404));

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:read']))
        ->getJson('/api/v1/meta/budget-status')
        ->assertOk()
        ->assertJsonPath('data.account_daily_limit_minor', 15000)
        ->assertJsonPath('data.spent_today_minor', 1000)
        ->assertJsonPath('data.active_daily_budget_minor', 10000)
        ->assertJsonPath('data.estimated_lifetime_daily_budget_minor', 0)
        ->assertJsonPath('data.projected_daily_budget_minor', 10000)
        ->assertJsonPath('data.protected_usage_minor', 10000)
        ->assertJsonPath('data.remaining_minor', 5000)
        ->assertJsonPath('data.advisory_only', true)
        ->assertJsonPath('data.mutations_allowed', true);

    Http::assertSentCount(4);
});

it('warns but creates a boost when the account advisory limit might be exceeded', function (): void {
    $campaigns = [[
        'id' => 'campaign-existing',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
        'daily_budget' => '14500',
    ]];
    $adSets = [[
        'id' => 'ad-set-existing',
        'campaign_id' => 'campaign-existing',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
    ]];

    Http::fake(function (Request $request) use ($campaigns, $adSets): mixed {
        $budgetResponse = metaBudgetFixtureResponse($request, $campaigns, $adSets);

        if ($budgetResponse) {
            return $budgetResponse;
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/template-ad-set-1')) {
            return Http::response([
                'id' => 'template-ad-set-1',
                'account_id' => '58438981',
                'billing_event' => 'IMPRESSIONS',
                'optimization_goal' => 'POST_ENGAGEMENT',
                'destination_type' => 'ON_POST',
                'promoted_object' => ['page_id' => '358179240887925'],
                'targeting' => ['geo_locations' => ['countries' => ['US']]],
            ]);
        }

        return match (true) {
            str_ends_with($request->url(), '/campaigns') => Http::response(['id' => 'campaign-over-limit']),
            str_ends_with($request->url(), '/adsets') => Http::response(['id' => 'ad-set-over-limit']),
            str_ends_with($request->url(), '/adcreatives') => Http::response(['id' => 'creative-over-limit']),
            str_ends_with($request->url(), '/ads') => Http::response(['id' => 'ad-over-limit']),
            default => Http::response([], 404),
        };
    });

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:manage']))
        ->postJson('/api/v1/meta/boosts', [
            'platform' => 'facebook',
            'post_id' => '358179240887925_123456789',
            'daily_budget_minor' => 1000,
        ], ['Idempotency-Key' => 'boost-over-limit'])
        ->assertCreated()
        ->assertJsonPath('data.campaign_id', 'campaign-over-limit')
        ->assertJsonPath('data.budget_snapshot.projected_usage_after_change_minor', 15500)
        ->assertJsonPath('data.budget_snapshot.would_exceed_daily_limit', true)
        ->assertJsonPath('data.budget_warning', fn (mixed $warning): bool => is_string($warning) && str_contains($warning, 'USD 150.00'));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/campaigns')
        && $request->data()['daily_budget'] === 1000);

    $this->assertDatabaseHas('meta_ad_operations', [
        'idempotency_key' => 'boost-over-limit',
        'status' => 'succeeded',
    ]);
});

it('creates a paused facebook boost from the configured template and replays it idempotently', function (): void {
    Http::fake(metaCreateBoostFixtureResponse(...));

    $owner = metaInternalOwner();
    $token = metaApiTokenFor($owner, ['ads:manage']);
    $payload = [
        'platform' => 'facebook',
        'post_id' => '358179240887925_123456789',
        'name' => 'API Test Boost',
        'daily_budget_minor' => 15000,
        'duration_days' => 7,
    ];

    $this->withToken($token)
        ->postJson('/api/v1/meta/boosts', $payload, ['Idempotency-Key' => 'boost-create-1'])
        ->assertCreated()
        ->assertJsonPath('data.campaign_id', 'campaign-created')
        ->assertJsonPath('data.ad_set_id', 'ad-set-created')
        ->assertJsonPath('data.creative_id', 'creative-created')
        ->assertJsonPath('data.ad_id', 'ad-created')
        ->assertJsonPath('data.status', 'PAUSED')
        ->assertJsonPath('data.budget_snapshot.would_exceed_daily_limit', false)
        ->assertJsonPath('data.budget_warning', null)
        ->assertJsonPath('data.idempotent_replay', false);

    Http::assertSentCount(9);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/campaigns')
        && $request->data()['daily_budget'] === 15000
        && $request->data()['status'] === 'PAUSED');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/adcreatives')
        && $request->data()['object_story_id'] === '358179240887925_123456789');

    $this->withToken($token)
        ->postJson('/api/v1/meta/boosts', $payload, ['Idempotency-Key' => 'boost-create-1'])
        ->assertCreated()
        ->assertJsonPath('data.idempotent_replay', true)
        ->assertJsonPath('data.campaign_id', 'campaign-created');

    Http::assertSentCount(9);

    $this->assertDatabaseHas('meta_ad_operations', [
        'idempotency_key' => 'boost-create-1',
        'status' => 'succeeded',
        'meta_campaign_id' => 'campaign-created',
        'meta_ad_id' => 'ad-created',
    ]);
});

it('uses the instagram media and actor when creating an instagram boost', function (): void {
    Http::fake(function (Request $request): mixed {
        $budgetResponse = metaBudgetFixtureResponse($request);

        if ($budgetResponse) {
            return $budgetResponse;
        }

        if ($request->method() === 'GET') {
            return Http::response([
                'id' => 'template-ad-set-1',
                'account_id' => '58438981',
                'billing_event' => 'IMPRESSIONS',
                'optimization_goal' => 'POST_ENGAGEMENT',
                'destination_type' => 'ON_POST',
                'promoted_object' => ['page_id' => '358179240887925'],
                'targeting' => ['geo_locations' => ['countries' => ['US']]],
            ]);
        }

        return match (true) {
            str_ends_with($request->url(), '/campaigns') => Http::response(['id' => 'campaign-ig']),
            str_ends_with($request->url(), '/adsets') => Http::response(['id' => 'ad-set-ig']),
            str_ends_with($request->url(), '/adcreatives') => Http::response(['id' => 'creative-ig']),
            str_ends_with($request->url(), '/ads') => Http::response(['id' => 'ad-ig']),
            default => Http::response([], 404),
        };
    });

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:manage']))
        ->postJson('/api/v1/meta/boosts', [
            'platform' => 'instagram',
            'post_id' => '18127844407740068',
            'daily_budget_minor' => 1000,
        ], ['Idempotency-Key' => 'boost-instagram-1'])
        ->assertCreated();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/adcreatives')
        && $request->data()['source_instagram_media_id'] === '18127844407740068'
        && $request->data()['instagram_user_id'] === '17841402997966896');
});

it('increases a campaign budget within the remaining account limit', function (): void {
    $campaigns = [[
        'id' => '52588901914077',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
        'daily_budget' => '5000',
    ]];
    $adSets = [[
        'id' => 'ad-set-budget-update',
        'campaign_id' => '52588901914077',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
    ]];

    Http::fake(function (Request $request) use ($campaigns, $adSets): mixed {
        $budgetResponse = metaBudgetFixtureResponse($request, $campaigns, $adSets, '20.00');

        if ($budgetResponse) {
            return $budgetResponse;
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/52588901914077')) {
            return Http::response([
                'id' => '52588901914077',
                'account_id' => '58438981',
                'daily_budget' => '5000',
                'status' => 'ACTIVE',
                'effective_status' => 'ACTIVE',
            ]);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), '/52588901914077')) {
            return Http::response(['success' => true]);
        }

        return Http::response([], 404);
    });

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:manage']))
        ->patchJson('/api/v1/meta/campaigns/52588901914077/budget', [
            'increase_by_minor' => 2000,
        ], ['Idempotency-Key' => 'budget-increase-1'])
        ->assertOk()
        ->assertJsonPath('data.previous_daily_budget_minor', 5000)
        ->assertJsonPath('data.daily_budget_minor', 7000)
        ->assertJsonPath('data.budget_type', 'daily')
        ->assertJsonPath('data.budget_snapshot.would_exceed_daily_limit', false)
        ->assertJsonPath('data.budget_warning', null);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/52588901914077')
        && $request->data()['daily_budget'] === 7000);
});

it('increases a lifetime campaign budget and returns an advisory warning', function (): void {
    $stopTime = now()->addDays(7)->toIso8601String();
    $campaigns = [[
        'id' => '120252528577560216',
        'account_id' => '58438981',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
        'lifetime_budget' => '50000',
        'budget_remaining' => '25000',
        'stop_time' => $stopTime,
    ]];
    $adSets = [[
        'id' => 'ad-set-lifetime',
        'campaign_id' => '120252528577560216',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
    ]];

    Http::fake(function (Request $request) use ($campaigns, $adSets): mixed {
        $budgetResponse = metaBudgetFixtureResponse($request, $campaigns, $adSets);

        if ($budgetResponse) {
            return $budgetResponse;
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/120252528577560216')) {
            return Http::response($campaigns[0]);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), '/120252528577560216')) {
            return Http::response(['success' => true]);
        }

        return Http::response([], 404);
    });

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:manage']))
        ->patchJson('/api/v1/meta/campaigns/120252528577560216/budget', [
            'increase_by_minor' => 12000,
        ], ['Idempotency-Key' => 'lifetime-budget-increase'])
        ->assertOk()
        ->assertJsonPath('data.budget_type', 'lifetime')
        ->assertJsonPath('data.previous_budget_minor', 50000)
        ->assertJsonPath('data.budget_minor', 62000)
        ->assertJsonPath('data.budget_snapshot.active_lifetime_budget_count', 1)
        ->assertJsonPath('data.budget_snapshot.would_exceed_daily_limit', true)
        ->assertJsonPath('data.budget_warning', fn (mixed $warning): bool => is_string($warning) && str_contains($warning, 'USD 150.00'));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/120252528577560216')
        && $request->data()['lifetime_budget'] === 62000);
});

it('rejects a campaign budget change for another ad account', function (): void {
    Http::fake(function (Request $request): mixed {
        $budgetResponse = metaBudgetFixtureResponse($request);

        if ($budgetResponse) {
            return $budgetResponse;
        }

        if ($request->method() === 'GET') {
            return Http::response([
                'id' => '99999999999999',
                'account_id' => '99999999',
                'daily_budget' => '1000',
            ]);
        }

        return Http::response(['success' => true]);
    });

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:manage']))
        ->patchJson('/api/v1/meta/campaigns/99999999999999/budget', [
            'increase_by_minor' => 100,
        ], ['Idempotency-Key' => 'wrong-account-budget'])
        ->assertUnprocessable();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('leaves a partially created boost paused and does not retry failed creation posts', function (): void {
    $adSetAttempts = 0;

    Http::fake(function (Request $request) use (&$adSetAttempts): mixed {
        $budgetResponse = metaBudgetFixtureResponse($request);

        if ($budgetResponse) {
            return $budgetResponse;
        }

        if ($request->method() === 'GET') {
            return Http::response([
                'id' => 'template-ad-set-1',
                'account_id' => '58438981',
                'billing_event' => 'IMPRESSIONS',
                'optimization_goal' => 'POST_ENGAGEMENT',
                'destination_type' => 'ON_POST',
                'promoted_object' => ['page_id' => '358179240887925'],
                'targeting' => ['geo_locations' => ['countries' => ['US']]],
            ]);
        }

        if (str_ends_with($request->url(), '/campaigns')) {
            return Http::response(['id' => 'campaign-partial']);
        }

        if (str_ends_with($request->url(), '/adsets')) {
            $adSetAttempts++;

            return Http::response(['error' => ['message' => 'Rejected ad set']], 500);
        }

        if (str_contains($request->url(), '/campaign-partial')) {
            return Http::response(['success' => true]);
        }

        return Http::response([], 404);
    });

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:manage']))
        ->postJson('/api/v1/meta/boosts', [
            'platform' => 'facebook',
            'post_id' => '358179240887925_123456789',
            'daily_budget_minor' => 1000,
            'status' => 'ACTIVE',
        ], ['Idempotency-Key' => 'boost-partial'])
        ->assertServerError();

    expect($adSetAttempts)->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/campaign-partial')
        && $request->data()['status'] === 'PAUSED');

    $this->assertDatabaseHas('meta_ad_operations', [
        'idempotency_key' => 'boost-partial',
        'status' => 'failed',
        'meta_campaign_id' => 'campaign-partial',
    ]);
});

it('lists ads and removes token-bearing paging urls', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/act_58438981/ads*' => Http::response([
            'data' => [[
                'id' => '120252515140480216',
                'account_id' => '58438981',
                'name' => 'Instagram boost',
                'status' => 'PAUSED',
                'effective_status' => 'PAUSED',
            ]],
            'paging' => [
                'cursors' => ['after' => 'ad-cursor-1'],
                'next' => 'https://graph.facebook.com/v25.0/example?access_token=system-user-token',
            ],
        ]),
    ]);

    $owner = metaInternalOwner();
    $response = $this->withToken(metaApiTokenFor($owner, ['ads:read']))
        ->getJson('/api/v1/meta/ads?limit=10&effective_status[]=PAUSED')
        ->assertOk()
        ->assertJsonPath('data.data.0.id', '120252515140480216')
        ->assertJsonPath('data.paging.cursors.after', 'ad-cursor-1');

    expect($response->getContent())
        ->not->toContain('system-user-token')
        ->not->toContain('paging.next');

    Http::assertSent(fn (Request $request): bool => $request->data()['effective_status'] === '["PAUSED"]'
        && $request->data()['limit'] === 10);
});

it('rejects exact ads from another ad account', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/120252515140480216*' => Http::response([
            'id' => '120252515140480216',
            'account_id' => 'other-account',
        ]),
    ]);

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:read']))
        ->getJson('/api/v1/meta/ads/120252515140480216')
        ->assertUnprocessable();
});

it('resolves an instagram edit url to its exact marketing ad', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/act_58438981/ads*' => Http::response([
            'data' => [[
                'id' => '120252515140480216',
                'creative' => [
                    'source_instagram_media_id' => '18336011737284162',
                    'instagram_permalink_url' => 'https://www.instagram.com/p/DifferentAdPermalink/',
                ],
            ], [
                'id' => 'unrelated-ad',
                'creative' => [
                    'instagram_permalink_url' => 'https://www.instagram.com/p/OtherShortcode/',
                ],
            ]],
        ]),
        'graph.facebook.com/v25.0/?ids=*' => Http::response([
            '18336011737284162' => [
                'id' => '18336011737284162',
                'shortcode' => 'DcjHi6rDQEP',
                'permalink' => 'https://www.instagram.com/p/DcjHi6rDQEP/',
                'boost_ads_list' => [
                    'data' => [
                        ['ad_id' => '120252515140480216', 'ad_status' => 'off'],
                        ['ad_id' => '120252515140480216', 'ad_status' => 'off'],
                    ],
                ],
            ],
        ]),
        'graph.facebook.com/v25.0/120252515140480216*' => Http::response([
            'id' => '120252515140480216',
            'account_id' => '58438981',
            'campaign_id' => '120252515139420216',
            'adset_id' => '120252515139640216',
            'status' => 'PAUSED',
            'creative' => [
                'source_instagram_media_id' => '18336011737284162',
                'instagram_permalink_url' => 'https://www.instagram.com/p/DifferentAdPermalink/',
            ],
        ]),
    ]);

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:read']))
        ->postJson('/api/v1/meta/ads/resolve', [
            'instagram_edit_url' => 'edit_boosted_ad/?boosted_id=28418680794411060&context=ads_manager_edit_promote&media_id=3973052482057994511',
        ])
        ->assertOk()
        ->assertJsonPath('data.ad.id', '120252515140480216')
        ->assertJsonPath('data.instagram_media.id', '18336011737284162')
        ->assertJsonPath('data.reference.instagram_shortcode', 'DcjHi6rDQEP')
        ->assertJsonPath('data.reference.instagram_boosted_id', '28418680794411060');

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v25.0/?ids=')
        && $request->data()['ids'] === '18336011737284162');
});

it('uses a graph media boost list when resolving a graph instagram media id', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/18336011737284162*' => Http::response([
            'id' => '18336011737284162',
            'shortcode' => 'DcjHi6rDQEP',
            'permalink' => 'https://www.instagram.com/p/DcjHi6rDQEP/',
            'boost_ads_list' => [
                'data' => [
                    ['ad_id' => '120252515140480216', 'ad_status' => 'off'],
                    ['ad_id' => '120252515140480216', 'ad_status' => 'off'],
                ],
            ],
        ]),
        'graph.facebook.com/v25.0/120252515140480216*' => Http::response([
            'id' => '120252515140480216',
            'account_id' => '58438981',
            'campaign_id' => '120252515139420216',
            'adset_id' => '120252515139640216',
            'status' => 'PAUSED',
        ]),
    ]);

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:read']))
        ->postJson('/api/v1/meta/ads/resolve', [
            'instagram_media_id' => '18336011737284162',
        ])
        ->assertOk()
        ->assertJsonPath('data.ad.id', '120252515140480216')
        ->assertJsonPath('data.instagram_media.id', '18336011737284162');

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/act_58438981/ads'));
});

it('retrieves performance for one exact ad', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/120252515140480216/insights*' => Http::response([
            'data' => [[
                'ad_id' => '120252515140480216',
                'spend' => '23.17',
                'impressions' => '4200',
                'reach' => '3100',
            ]],
        ]),
        'graph.facebook.com/v25.0/120252515140480216*' => Http::response([
            'id' => '120252515140480216',
            'account_id' => '58438981',
            'campaign_id' => '120252515139420216',
            'adset_id' => '120252515139640216',
            'status' => 'PAUSED',
        ]),
    ]);

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:read']))
        ->getJson('/api/v1/meta/ads/120252515140480216/insights?since=2026-08-01&until=2026-08-29')
        ->assertOk()
        ->assertJsonPath('data.ad.id', '120252515140480216')
        ->assertJsonPath('data.insights.data.0.spend', '23.17');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/insights')
        && $request->data()['time_range'] === '{"since":"2026-08-01","until":"2026-08-29"}');
});

it('pauses an exact ad once and replays the audited result idempotently', function (): void {
    $adReads = 0;

    Http::fake(function (Request $request) use (&$adReads): mixed {
        if ($request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === '/v25.0/120252515140480216') {
            $adReads++;

            return Http::response([
                'id' => '120252515140480216',
                'account_id' => '58438981',
                'campaign_id' => '120252515139420216',
                'adset_id' => '120252515139640216',
                'status' => $adReads === 1 ? 'ACTIVE' : 'PAUSED',
                'effective_status' => $adReads === 1 ? 'ACTIVE' : 'PAUSED',
            ]);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), '/120252515140480216')) {
            return Http::response(['success' => true]);
        }

        return Http::response([], 404);
    });

    $owner = metaInternalOwner();
    $token = metaApiTokenFor($owner, ['ads:manage']);
    $headers = ['Idempotency-Key' => 'pause-ad-1'];

    $this->withToken($token)
        ->patchJson('/api/v1/meta/ads/120252515140480216/status', ['status' => 'PAUSED'], $headers)
        ->assertOk()
        ->assertJsonPath('data.previous_status', 'ACTIVE')
        ->assertJsonPath('data.status', 'PAUSED')
        ->assertJsonPath('data.idempotent_replay', false);

    $this->withToken($token)
        ->patchJson('/api/v1/meta/ads/120252515140480216/status', ['status' => 'PAUSED'], $headers)
        ->assertOk()
        ->assertJsonPath('data.idempotent_replay', true);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['status'] === 'PAUSED');
    $this->assertDatabaseHas('meta_ad_operations', [
        'idempotency_key' => 'pause-ad-1',
        'type' => 'status_update',
        'status' => 'succeeded',
        'meta_ad_id' => '120252515140480216',
    ]);
});

it('warns when resuming a paused ad may exceed the account daily limit', function (): void {
    $adReads = 0;
    $campaigns = [[
        'id' => '120252515139420216',
        'account_id' => '58438981',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
        'daily_budget' => '16000',
    ]];
    $adSets = [[
        'id' => '120252515139640216',
        'campaign_id' => '120252515139420216',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
    ]];
    $ads = [[
        'id' => '120252515140480216',
        'campaign_id' => '120252515139420216',
        'adset_id' => '120252515139640216',
        'status' => 'PAUSED',
        'effective_status' => 'PAUSED',
    ]];

    Http::fake(function (Request $request) use (&$adReads, $campaigns, $adSets, $ads): mixed {
        if ($request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === '/v25.0/120252515140480216') {
            $adReads++;

            return Http::response([
                ...$ads[0],
                'account_id' => '58438981',
                'status' => $adReads === 1 ? 'PAUSED' : 'ACTIVE',
                'effective_status' => $adReads === 1 ? 'PAUSED' : 'ACTIVE',
            ]);
        }

        $budgetResponse = metaBudgetFixtureResponse($request, $campaigns, $adSets, '0.00', $ads);

        if ($budgetResponse) {
            return $budgetResponse;
        }

        if ($request->method() === 'POST') {
            return Http::response(['success' => true]);
        }

        return Http::response([], 404);
    });

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:manage']))
        ->patchJson('/api/v1/meta/ads/120252515140480216/status', [
            'status' => 'ACTIVE',
        ], ['Idempotency-Key' => 'resume-ad-over-limit'])
        ->assertOk()
        ->assertJsonPath('data.status', 'ACTIVE')
        ->assertJsonPath('data.budget_snapshot.active_daily_budget_minor', 0)
        ->assertJsonPath('data.budget_snapshot.projected_daily_budget_after_change_minor', 16000)
        ->assertJsonPath('data.budget_snapshot.would_exceed_daily_limit', true)
        ->assertJsonPath('data.budget_warning', fn (mixed $warning): bool => is_string($warning) && str_contains($warning, 'USD 150.00'));
});

it('does not count an active campaign whose only ad is paused', function (): void {
    $campaigns = [[
        'id' => 'campaign-paused-ad',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
        'daily_budget' => '12000',
    ]];
    $adSets = [[
        'id' => 'ad-set-paused-ad',
        'campaign_id' => 'campaign-paused-ad',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
    ]];
    $ads = [[
        'id' => 'paused-ad',
        'campaign_id' => 'campaign-paused-ad',
        'adset_id' => 'ad-set-paused-ad',
        'status' => 'PAUSED',
        'effective_status' => 'PAUSED',
    ]];

    Http::fake(fn (Request $request): mixed => metaBudgetFixtureResponse($request, $campaigns, $adSets, '0.00', $ads)
        ?? Http::response([], 404));

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:read']))
        ->getJson('/api/v1/meta/budget-status')
        ->assertOk()
        ->assertJsonPath('data.active_daily_budget_minor', 0)
        ->assertJsonPath('data.projected_daily_budget_minor', 0);
});

it('increases the campaign budget that owns an exact ads budget', function (): void {
    $campaign = [
        'id' => '120252515139420216',
        'account_id' => '58438981',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
        'daily_budget' => '5000',
    ];
    $adSet = [
        'id' => '120252515139640216',
        'account_id' => '58438981',
        'campaign_id' => '120252515139420216',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
    ];
    $ad = [
        'id' => '120252515140480216',
        'account_id' => '58438981',
        'campaign_id' => '120252515139420216',
        'adset_id' => '120252515139640216',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
    ];

    Http::fake(function (Request $request) use ($campaign, $adSet, $ad): mixed {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && $path === '/v25.0/'.$ad['id']) {
            return Http::response($ad);
        }

        if ($request->method() === 'GET' && $path === '/v25.0/'.$campaign['id']) {
            return Http::response($campaign);
        }

        if ($request->method() === 'GET' && $path === '/v25.0/'.$adSet['id']) {
            return Http::response($adSet);
        }

        $budgetResponse = metaBudgetFixtureResponse($request, [$campaign], [$adSet], '10.00', [$ad]);

        if ($budgetResponse) {
            return $budgetResponse;
        }

        return Http::response(['success' => true]);
    });

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:manage']))
        ->patchJson('/api/v1/meta/ads/120252515140480216/budget', [
            'increase_by_minor' => 2000,
        ], ['Idempotency-Key' => 'ad-campaign-budget-1'])
        ->assertOk()
        ->assertJsonPath('data.budget_owner_type', 'campaign')
        ->assertJsonPath('data.budget_owner_id', '120252515139420216')
        ->assertJsonPath('data.previous_budget_minor', 5000)
        ->assertJsonPath('data.budget_minor', 7000);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && parse_url($request->url(), PHP_URL_PATH) === '/v25.0/120252515139420216'
        && $request->data()['daily_budget'] === 7000);
});

it('increases the ad set budget when the campaign does not own one', function (): void {
    $campaign = [
        'id' => '120252515139420216',
        'account_id' => '58438981',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
    ];
    $adSet = [
        'id' => '120252515139640216',
        'account_id' => '58438981',
        'campaign_id' => '120252515139420216',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
        'daily_budget' => '2000',
    ];
    $ad = [
        'id' => '120252515140480216',
        'account_id' => '58438981',
        'campaign_id' => '120252515139420216',
        'adset_id' => '120252515139640216',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
    ];

    Http::fake(function (Request $request) use ($campaign, $adSet, $ad): mixed {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && $path === '/v25.0/'.$ad['id']) {
            return Http::response($ad);
        }

        if ($request->method() === 'GET' && $path === '/v25.0/'.$campaign['id']) {
            return Http::response($campaign);
        }

        if ($request->method() === 'GET' && $path === '/v25.0/'.$adSet['id']) {
            return Http::response($adSet);
        }

        $budgetResponse = metaBudgetFixtureResponse($request, [$campaign], [$adSet], '0.00', [$ad]);

        if ($budgetResponse) {
            return $budgetResponse;
        }

        return Http::response(['success' => true]);
    });

    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:manage']))
        ->patchJson('/api/v1/meta/ads/120252515140480216/budget', [
            'increase_by_minor' => 1000,
        ], ['Idempotency-Key' => 'ad-set-budget-1'])
        ->assertOk()
        ->assertJsonPath('data.budget_owner_type', 'ad_set')
        ->assertJsonPath('data.budget_owner_id', '120252515139640216')
        ->assertJsonPath('data.previous_budget_minor', 2000)
        ->assertJsonPath('data.budget_minor', 3000);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && parse_url($request->url(), PHP_URL_PATH) === '/v25.0/120252515139640216'
        && $request->data()['daily_budget'] === 3000);
});

it('uses the stored facebook page token for facebook organic insights', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/358179240887925_123456789/insights*' => Http::response([
            'data' => [[
                'name' => 'post_impressions',
                'values' => [['value' => 750]],
            ]],
        ]),
    ]);

    $owner = metaInternalOwner();
    $pageAccessToken = Str::random(64);
    ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'facebook',
        'provider_account_id' => '358179240887925',
        'provider_account_type' => 'page',
        'display_name' => 'Clayton House Marketplace',
        'access_token' => $pageAccessToken,
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]);

    $this->withToken(metaApiTokenFor($owner, ['organic:read']))
        ->postJson('/api/v1/meta/organic-insights', [
            'platform' => 'facebook',
            'post_id' => '358179240887925_123456789',
        ])
        ->assertOk()
        ->assertJsonPath('data.data.0.name', 'post_impressions');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$pageAccessToken)
        && $request->data()['appsecret_proof'] === hash_hmac(
            'sha256',
            $pageAccessToken,
            (string) config('services.meta_marketing.app_secret'),
        ));
    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader(
        'Authorization',
        'Bearer '.config('services.meta_marketing.access_token'),
    ));
});

it('does not let an ads only token access legacy publishing routes', function (): void {
    $owner = metaInternalOwner();

    $this->withToken(metaApiTokenFor($owner, ['ads:manage']))
        ->postJson('/api/post', [])
        ->assertForbidden();

    Http::assertNothingSent();
});
