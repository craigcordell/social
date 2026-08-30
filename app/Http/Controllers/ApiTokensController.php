<?php

namespace App\Http\Controllers;

use App\Models\Owner;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\NewAccessToken;
use SensitiveParameter;

final class ApiTokensController extends Controller
{
    public const ABILITY_OPTIONS = [
        'ads:read' => 'Read ads and paid performance',
        'ads:manage' => 'Create and change ads and budgets',
        'organic:read' => 'Read Facebook and Instagram organic performance',
    ];

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return view('social.api-tokens', [
            'owners' => Owner::query()->where('is_active', true)->orderBy('name')->get(),
            'tokens' => PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())
                ->with('owner')
                ->latest()
                ->get(),
            'abilityOptions' => self::ABILITY_OPTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        /** @var array{name: string, owner_id: int, abilities: list<string>} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'owner_id' => ['required', 'integer', 'exists:owners,id'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', Rule::in(array_keys(self::ABILITY_OPTIONS))],
        ]);

        /** @var NewAccessToken $token */
        $token = $user->createToken(
            $data['name'],
            array_values(array_unique($data['abilities'])),
        );
        $token
            ->accessToken
            ->forceFill([
                'owner_id' => $data['owner_id'],
            ])
            ->save();

        return back()->with('plainTextToken', $token->plainTextToken);
    }

    public function destroy(Request $request, #[SensitiveParameter] PersonalAccessToken $token): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($token->tokenable_type !== $user->getMorphClass() || (int) $token->tokenable_id !== (int) $user->getKey()) {
            abort(404);
        }

        PersonalAccessToken::query()->whereKey($token->getKey())->delete();

        return back()->with('status', 'API token revoked.');
    }
}
