<?php

use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('requires sanctum authentication for social api routes', function (): void {
    $this->getJson('/api/connected-accounts')->assertUnauthorized();
});

it('lists active connected accounts', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);
    ConnectedAccount::query()->create([
        'owner_id' => $owner->id,
        'provider' => 'facebook',
        'provider_account_id' => 'page-1',
        'provider_account_type' => 'page',
        'display_name' => 'Clayton House',
        'access_token' => 'secret',
        'status' => ConnectedAccount::STATUS_ACTIVE,
    ]);

    $this->getJson('/api/connected-accounts')
        ->assertOk()
        ->assertJsonPath('data.0.display_name', 'Clayton House')
        ->assertJsonPath('data.0.provider', 'facebook');
});

it('does not expose the legacy owner-selectable posts api', function (string $method, string $uri): void {
    Sanctum::actingAs(User::factory()->create());

    $this->json($method, $uri, [
        'owner_id' => 1,
        'target_ids' => [1],
    ])->assertNotFound();
})->with([
    'create' => ['POST', '/api/posts'],
    'show' => ['GET', '/api/posts/1'],
    'delete' => ['DELETE', '/api/posts/1'],
]);
