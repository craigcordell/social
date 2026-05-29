<?php

namespace App\Http\Controllers;

use App\Models\Owner;
use App\Models\PersonalAccessToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiTokensController extends Controller
{
    public function index(Request $request): View
    {
        return view('social.api-tokens', [
            'owners' => Owner::query()->where('is_active', true)->orderBy('name')->get(),
            'tokens' => $request->user()->tokens()->with('owner')->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'owner_id' => ['required', 'integer', 'exists:owners,id'],
        ]);

        $token = $request->user()->createToken($data['name']);
        $token->accessToken->forceFill([
            'owner_id' => $data['owner_id'],
        ])->save();

        return back()->with('plainTextToken', $token->plainTextToken);
    }

    public function destroy(Request $request, PersonalAccessToken $token): RedirectResponse
    {
        abort_unless($token->tokenable()->is($request->user()), 404);

        $token->delete();

        return back()->with('status', 'API token revoked.');
    }
}
