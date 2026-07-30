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
        [
            'provider' => 'facebook',
            'display_name' => 'Clayton House Marketplace',
            'post_url' => 'https://www.facebook.com/example-post',
        ],
        [
            'provider' => 'instagram',
            'display_name' => 'claytonhousemarketplace',
            'post_url' => 'https://www.instagram.com/p/example/',
        ],
        [
            'provider' => 'gmb',
            'display_name' => 'Clayton House',
            'post_url' => 'https://local.google.com/place?id=example',
        ],
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
            'provider_post_url' => $accountData['post_url'],
        ]);
    });

    $response = $this->actingAs($user)->get(route('posts.index'));

    $response->assertOk()
        ->assertSee('Broadwing Social')
        ->assertDontSee('Laravel Starter Kit')
        ->assertSee('Social sites')
        ->assertSeeText('Facebook — Clayton House Marketplace: published')
        ->assertSeeText('Instagram — claytonhousemarketplace: published')
        ->assertSeeText('Google Business Profile — Clayton House: published')
        ->assertSeeHtml('href="https://www.facebook.com/example-post"')
        ->assertSeeHtml('href="https://www.instagram.com/p/example/"')
        ->assertSeeHtml('href="https://local.google.com/place?id=example"')
        ->assertSeeHtml('target="_blank"')
        ->assertSeeHtml('rel="noopener noreferrer"');

    expect(substr_count($response->getContent(), 'Broadwing Social'))->toBeGreaterThanOrEqual(2);
});
