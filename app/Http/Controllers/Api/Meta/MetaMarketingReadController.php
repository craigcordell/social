<?php

namespace App\Http\Controllers\Api\Meta;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Meta\GetAdInsightsRequest;
use App\Http\Requests\Api\Meta\GetOrganicInsightsRequest;
use App\Http\Requests\Api\Meta\ListAdsRequest;
use App\Http\Requests\Api\Meta\ListCampaignsRequest;
use App\Http\Requests\Api\Meta\ResolveMetaAdRequest;
use App\Services\Api\CurrentApiOwner;
use App\Services\MetaMarketing\MetaAccountBudgetGuard;
use App\Services\MetaMarketing\MetaAdReferenceResolver;
use App\Services\MetaMarketing\MetaMarketingApiClient;
use App\Services\MetaMarketing\MetaMarketingStatusService;
use App\Services\MetaMarketing\MetaOrganicInsightsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MetaMarketingReadController extends Controller
{
    public function __construct(
        private readonly CurrentApiOwner $currentOwner,
    ) {}

    public function status(Request $request, MetaMarketingStatusService $status): JsonResponse
    {
        return response()->json([
            'data' => $status->get($this->currentOwner->resolve($request)),
        ]);
    }

    public function campaigns(ListCampaignsRequest $request, MetaMarketingApiClient $meta): JsonResponse
    {
        /** @var array{limit?: int, effective_status?: list<string>, after?: string} $data */
        $data = $request->validated();

        return response()->json([
            'data' => $meta->campaigns(
                $this->currentOwner->resolve($request),
                $data['limit'] ?? 25,
                $data['effective_status'] ?? [],
                $data['after'] ?? null,
            ),
        ]);
    }

    public function insights(GetAdInsightsRequest $request, MetaMarketingApiClient $meta): JsonResponse
    {
        /** @var array{level?: string, since: string, until: string, limit?: int, after?: string} $data */
        $data = $request->validated();

        return response()->json([
            'data' => $meta->insights($this->currentOwner->resolve($request), [
                'level' => $data['level'] ?? 'campaign',
                'since' => $data['since'],
                'until' => $data['until'],
                'limit' => $data['limit'] ?? 25,
                'after' => $data['after'] ?? null,
            ]),
        ]);
    }

    public function ads(ListAdsRequest $request, MetaMarketingApiClient $meta): JsonResponse
    {
        /** @var array{limit?: int, effective_status?: list<string>, after?: string} $data */
        $data = $request->validated();

        return response()->json([
            'data' => $meta->ads(
                $this->currentOwner->resolve($request),
                $data['limit'] ?? 25,
                $data['effective_status'] ?? [],
                $data['after'] ?? null,
            ),
        ]);
    }

    public function ad(Request $request, MetaMarketingApiClient $meta, string $adId): JsonResponse
    {
        return response()->json([
            'data' => $meta->ad($this->currentOwner->resolve($request), $adId),
        ]);
    }

    public function resolveAd(ResolveMetaAdRequest $request, MetaAdReferenceResolver $resolver): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        return response()->json([
            'data' => $resolver->resolve($this->currentOwner->resolve($request), $data),
        ]);
    }

    public function adInsights(
        GetAdInsightsRequest $request,
        MetaMarketingApiClient $meta,
        string $adId,
    ): JsonResponse {
        /** @var array{since: string, until: string} $data */
        $data = $request->validated();

        return response()->json([
            'data' => $meta->adInsights(
                $this->currentOwner->resolve($request),
                $adId,
                $data['since'],
                $data['until'],
            ),
        ]);
    }

    public function organicInsights(
        GetOrganicInsightsRequest $request,
        MetaOrganicInsightsClient $insights,
    ): JsonResponse {
        /** @var array{platform: string, post_id: string} $data */
        $data = $request->validated();

        return response()->json([
            'data' => $insights->get(
                $this->currentOwner->resolve($request),
                $data['platform'],
                $data['post_id'],
            ),
        ]);
    }

    public function budgetStatus(Request $request, MetaAccountBudgetGuard $budgetGuard): JsonResponse
    {
        return response()->json([
            'data' => $budgetGuard->snapshot($this->currentOwner->resolve($request)),
        ]);
    }
}
