<?php

use App\Models\Owner;
use App\Models\PersonalAccessToken;
use App\Models\User;

it('shows the supported api token abilities', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('api-tokens.index'))
        ->assertOk()
        ->assertSee('Read ads and paid performance')
        ->assertSee('Create and change ads and budgets')
        ->assertSee('Read Facebook and Instagram organic performance');
});

it('requires an owner when creating api tokens', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('api-tokens.store'), [
            'name' => 'POS2024',
            'abilities' => ['ads:read'],
        ])
        ->assertSessionHasErrors('owner_id');
});

it('stores the selected owner on new api tokens', function (): void {
    $user = User::factory()->create();
    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);

    $this->actingAs($user)
        ->post(route('api-tokens.store'), [
            'name' => 'POS2024',
            'owner_id' => $owner->id,
            'abilities' => ['ads:read', 'ads:manage'],
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('plainTextToken');

    $token = PersonalAccessToken::query()->firstOrFail();

    expect($token->owner_id)->toBe($owner->id)
        ->and($token->abilities)->toBe(['ads:read', 'ads:manage'])
        ->and($token->tokenable()->is($user))->toBeTrue();
});

it('requires at least one supported api token ability', function (): void {
    $user = User::factory()->create();
    $owner = Owner::query()->create(['name' => 'Internal', 'type' => 'internal']);

    $this->actingAs($user)
        ->post(route('api-tokens.store'), [
            'name' => 'Missing permissions',
            'owner_id' => $owner->id,
        ])
        ->assertSessionHasErrors('abilities');

    $this->actingAs($user)
        ->post(route('api-tokens.store'), [
            'name' => 'Invalid token',
            'owner_id' => $owner->id,
            'abilities' => ['*'],
        ])
        ->assertSessionHasErrors('abilities.0');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});
