<?php

use App\Http\Controllers\OAuth\FacebookOAuthController;
use Illuminate\Support\Facades\Http;

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
