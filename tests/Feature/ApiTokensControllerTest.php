<?php

use App\Models\Owner;
use App\Models\PersonalAccessToken;
use App\Models\User;

it('requires an owner when creating api tokens', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('api-tokens.store'), [
            'name' => 'POS2024',
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
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('plainTextToken');

    $token = PersonalAccessToken::query()->firstOrFail();

    expect($token->owner_id)->toBe($owner->id)
        ->and($token->tokenable()->is($user))->toBeTrue();
});
