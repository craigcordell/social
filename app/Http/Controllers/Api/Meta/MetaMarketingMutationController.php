<?php

namespace App\Http\Controllers\Api\Meta;

use App\Actions\MetaMarketing\CreateMetaBoost;
use App\Actions\MetaMarketing\IncreaseMetaAdBudget;
use App\Actions\MetaMarketing\IncreaseMetaCampaignBudget;
use App\Actions\MetaMarketing\PauseMetaAdsByPosts;
use App\Actions\MetaMarketing\UpdateMetaAdStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Meta\CreateBoostRequest;
use App\Http\Requests\Api\Meta\IncreaseAdBudgetRequest;
use App\Http\Requests\Api\Meta\IncreaseCampaignBudgetRequest;
use App\Http\Requests\Api\Meta\PauseAdsByPostsRequest;
use App\Http\Requests\Api\Meta\UpdateAdStatusRequest;
use App\Services\Api\CurrentApiOwner;
use Illuminate\Http\JsonResponse;

final class MetaMarketingMutationController extends Controller
{
    public function __construct(
        private readonly CurrentApiOwner $currentOwner,
        private readonly CreateMetaBoost $createBoost,
        private readonly UpdateMetaAdStatus $updateAdStatus,
        private readonly IncreaseMetaAdBudget $increaseAdBudget,
        private readonly IncreaseMetaCampaignBudget $increaseCampaignBudget,
    ) {}

    public function createBoost(CreateBoostRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        return response()->json([
            'data' => $this->createBoost->execute($this->currentOwner->resolve($request), $data),
        ], 201);
    }

    public function increaseCampaignBudget(
        IncreaseCampaignBudgetRequest $request,
        string $campaignId,
    ): JsonResponse {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        return response()->json([
            'data' => $this->increaseCampaignBudget->execute(
                $this->currentOwner->resolve($request),
                $campaignId,
                $data,
            ),
        ]);
    }

    public function updateAdStatus(UpdateAdStatusRequest $request, string $adId): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        return response()->json([
            'data' => $this->updateAdStatus->execute(
                $this->currentOwner->resolve($request),
                $adId,
                $data,
            ),
        ]);
    }

    public function pauseAdsByPosts(
        PauseAdsByPostsRequest $request,
        PauseMetaAdsByPosts $pauseAdsByPosts,
    ): JsonResponse {
        /** @var array{idempotency_key: string, posts: list<array{client_reference?: ?string, platform: string, post_url: string}>} $data */
        $data = $request->validated();

        return response()->json([
            'data' => $pauseAdsByPosts->execute(
                $this->currentOwner->resolve($request),
                $data,
            ),
        ]);
    }

    public function increaseAdBudget(IncreaseAdBudgetRequest $request, string $adId): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        return response()->json([
            'data' => $this->increaseAdBudget->execute(
                $this->currentOwner->resolve($request),
                $adId,
                $data,
            ),
        ]);
    }
}
