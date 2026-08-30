<?php

namespace App\Services\Api;

use App\Models\Owner;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\Request;

final class CurrentApiOwner
{
    public function resolve(Request $request): Owner
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        /** @var PersonalAccessToken|null $token */
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || $token->owner_id === null) {
            abort(403, 'This API token is not assigned to an owner.');
        }

        $owner = Owner::query()
            ->whereKey((int) $token->owner_id)
            ->where('is_active', true)
            ->first();

        if (! $owner instanceof Owner) {
            abort(403, 'This API token owner is not active.');
        }

        return $owner;
    }
}
