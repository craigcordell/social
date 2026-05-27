<?php

namespace App\Http\Controllers;

use App\Models\ConnectedAccount;
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
