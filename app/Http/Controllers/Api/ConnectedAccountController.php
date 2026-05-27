<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use Illuminate\Http\JsonResponse;

class ConnectedAccountController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $accounts = ConnectedAccount::query()
            ->with('owner:id,name,type,external_id')
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
