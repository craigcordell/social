<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\Api\CurrentApiOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConnectedAccountController extends Controller
{
    public function __construct(
        private readonly CurrentApiOwner $currentOwner,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $owner = $this->currentOwner->resolve($request);

        $accounts = ConnectedAccount::query()
            ->with('owner:id,name,type,external_id')
            ->where('owner_id', $owner->id)
            ->where('status', ConnectedAccount::STATUS_ACTIVE)
            ->orderBy('provider')
            ->orderBy('display_name')
            ->get()
            ->map(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'owner' => $account->owner,
                'provider' => $account->provider,
                'provider_account_type' => $account->provider_account_type,
                'display_name' => $account->display_name,
                'status' => $account->status,
            ]);

        return response()->json(['data' => $accounts]);
    }
}
