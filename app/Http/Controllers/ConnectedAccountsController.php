<?php

namespace App\Http\Controllers;

use App\Models\ConnectedAccount;
use App\Models\OAuthDebugAttempt;
use App\Models\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ConnectedAccountsController extends Controller
{
    public function index(): View
    {
        return view('social.connected-accounts', [
            'owners' => Owner::query()->where('is_active', true)->orderBy('name')->get(),
            'accounts' => ConnectedAccount::query()->with('owner')->latest()->get(),
            'oauthDebugAttempts' => OAuthDebugAttempt::query()
                ->with('owner')
                ->where('provider', 'facebook')
                ->latest()
                ->limit(5)
                ->get(),
            'facebookConfig' => [
                'client_id' => config('services.facebook.client_id'),
                'has_client_secret' => filled(config('services.facebook.client_secret')),
                'redirect_uri' => config('services.facebook.redirect') ?: url('/oauth/facebook/callback'),
                'graph_version' => config('social.providers.facebook.graph_version'),
                'scopes' => config('services.facebook.scopes', []),
                'login_config_id' => config('services.facebook.login_config_id'),
            ],
        ]);
    }

    public function destroy(ConnectedAccount $connectedAccount): RedirectResponse
    {
        $connectedAccount->forceFill([
            'status' => ConnectedAccount::STATUS_DISCONNECTED,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ])->save();

        return back()->with('status', 'Connected account disconnected.');
    }
}
