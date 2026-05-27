<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokensController extends Controller
{
    public function index(Request $request): View
    {
        return view('social.api-tokens', [
            'tokens' => $request->user()->tokens()->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $token = $request->user()->createToken($data['name']);

        return back()->with('plainTextToken', $token->plainTextToken);
    }

    public function destroy(Request $request, PersonalAccessToken $token): RedirectResponse
    {
        abort_unless($token->tokenable()->is($request->user()), 404);

        $token->delete();

        return back()->with('status', 'API token revoked.');
    }
}
