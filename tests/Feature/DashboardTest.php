<?php

use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('posts identify social sites and use the configured application name', function () {
    config(['app.name' => 'Broadwing Social']);

    $user = User::factory()->create();
    $owner = Owner::query()->create([
        'name' => 'clayton_house',
    ]);
    $post = SocialPost::query()->create([
        'owner_id' => $owner->id,
        'caption' => 'A post for every connected social site.',
        'image_url' => 'https://example.com/image.jpg',
        'status' => SocialPost::STATUS_PUBLISHED,
    ]);

    collect([
        ['provider' => 'facebook', 'display_name' => 'Clayton House Marketplace'],
        ['provider' => 'instagram', 'display_name' => 'claytonhousemarketplace'],
        ['provider' => 'gmb', 'display_name' => 'Clayton House'],
    ])->each(function (array $accountData) use ($owner, $post): void {
        $account = ConnectedAccount::query()->create([
            'owner_id' => $owner->id,
            'provider' => $accountData['provider'],
            'provider_account_id' => "{$accountData['provider']}-account",
            'display_name' => $accountData['display_name'],
        ]);

        SocialPostTarget::query()->create([
            'social_post_id' => $post->id,
            'connected_account_id' => $account->id,
            'provider' => $accountData['provider'],
            'publish_status' => SocialPostTarget::PUBLISH_STATUS_PUBLISHED,
        ]);
    });

    $response = $this->actingAs($user)->get(route('posts.index'));

    $response->assertOk()
        ->assertSee('Broadwing Social')
        ->assertDontSee('Laravel Starter Kit')
        ->assertSee('Social sites')
        ->assertSee('Facebook — Clayton House Marketplace: published')
        ->assertSee('Instagram — claytonhousemarketplace: published')
        ->assertSee('Google Business Profile — Clayton House: published');

    expect(substr_count($response->getContent(), 'Broadwing Social'))->toBeGreaterThanOrEqual(2);
});
