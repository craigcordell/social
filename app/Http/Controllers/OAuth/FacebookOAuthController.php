<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

class FacebookOAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:owners,id'],
        ]);

        session(['facebook_oauth_owner_id' => $data['owner_id']]);

        return Socialite::driver('facebook')
            ->scopes([
                'pages_show_list',
                'pages_read_engagement',
                'pages_manage_posts',
                'pages_manage_engagement',
            ])
            ->with(['auth_type' => 'rerequest'])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        $ownerId = session()->pull('facebook_oauth_owner_id');
        $owner = $ownerId ? Owner::query()->find($ownerId) : null;

        abort_unless($owner, 403);

        $facebookUser = Socialite::driver('facebook')->user();
        $version = config('social.providers.facebook.graph_version', 'v25.0');

        $pages = Http::acceptJson()
            ->withToken($facebookUser->token)
            ->get("https://graph.facebook.com/{$version}/me/accounts", [
                'fields' => 'id,name,access_token,category,tasks',
            ])
            ->throw()
            ->json('data', []);

        foreach ($pages as $page) {
            ConnectedAccount::query()->updateOrCreate(
                [
                    'provider' => 'facebook',
                    'provider_account_id' => $page['id'],
                ],
                [
                    'owner_id' => $owner->id,
                    'provider_account_type' => 'page',
                    'display_name' => $page['name'],
                    'access_token' => $page['access_token'] ?? null,
                    'refresh_token' => null,
                    'token_expires_at' => null,
                    'scopes' => [
                        'pages_show_list',
                        'pages_read_engagement',
                        'pages_manage_posts',
                        'pages_manage_engagement',
                    ],
                    'metadata' => [
                        'category' => $page['category'] ?? null,
                        'tasks' => $page['tasks'] ?? [],
                    ],
                    'status' => ConnectedAccount::STATUS_ACTIVE,
                    'last_connected_at' => now(),
                ],
            );
        }

        return redirect()
            ->route('connected-accounts.index')
            ->with('status', 'Facebook Pages connected.');
    }
}
