<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\PersonalAccessToken;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Social\SocialPlatformManager;
use App\Services\Social\SocialPostTargetPublisher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Validation\ValidationException;
use Throwable;

class AyrshareCompatibilityController extends Controller
{
    public function __construct(private readonly SocialPlatformManager $platforms) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'post' => ['required', 'string'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['required', 'string'],
            'mediaUrls' => ['required', 'array', 'min:1'],
            'mediaUrls.*' => ['nullable', 'url', 'starts_with:http://,https://'],
            'pinterestOptions' => ['nullable', 'array'],
            'pinterestOptions.link' => ['nullable', 'url', 'starts_with:http://,https://'],
            'twitterOptions' => ['nullable', 'array'],
        ]);

        $owner = $this->tokenOwner($request);
        $imageUrl = $this->firstMediaUrl($data['mediaUrls']);
        $requestedPlatforms = $this->requestedPlatforms($data['platforms']);

        $post = SocialPost::query()->create([
            'owner_id' => $owner->id,
            'caption' => $data['post'],
            'image_url' => $imageUrl,
            'link_url' => Arr::get($data, 'pinterestOptions.link'),
            'status' => SocialPost::STATUS_PUBLISHING,
        ]);

        $errors = [];
        $targetIds = [];

        foreach ($requestedPlatforms as $platform) {
            if (! $this->platforms->supports($platform)) {
                $errors[] = $this->platformError($platform, 'post', "{$this->platformName($platform)} is not implemented.");

                continue;
            }

            $accounts = $this->connectedAccounts($owner, $platform);

            if ($accounts->isEmpty()) {
                $errors[] = $this->platformError($platform, 'post', "{$this->platformName($platform)} is not linked.");

                continue;
            }

            foreach ($accounts as $account) {
                $target = SocialPostTarget::query()->create([
                    'social_post_id' => $post->id,
                    'connected_account_id' => $account->id,
                    'provider' => $account->provider,
                    'publish_status' => SocialPostTarget::PUBLISH_STATUS_QUEUED,
                ]);

                $targetIds[] = $target->id;
            }
        }

        $this->publishTargetsConcurrently($targetIds);

        $targets = $post->targets()->orderBy('id')->get();
        $postIds = $targets
            ->filter(fn (SocialPostTarget $target): bool => $target->publish_status === SocialPostTarget::PUBLISH_STATUS_PUBLISHED)
            ->map(fn (SocialPostTarget $target): array => $this->postIdResponse($target))
            ->values()
            ->all();
        $errors = array_merge(
            $errors,
            $targets
                ->filter(fn (SocialPostTarget $target): bool => $target->publish_status === SocialPostTarget::PUBLISH_STATUS_FAILED)
                ->map(fn (SocialPostTarget $target): array => $this->platformError(
                    $target->provider,
                    'post',
                    $target->last_error ?? 'Publishing failed.',
                ))
                ->values()
                ->all(),
        );

        $post->refreshAggregateStatus();

        if ($post->targets()->doesntExist() && $errors !== []) {
            $post->forceFill(['status' => SocialPost::STATUS_FAILED])->save();
        }

        return response()->json([
            'id' => (string) $post->id,
            'status' => $errors === [] ? 'success' : 'error',
            'post' => $post->caption,
            'postIds' => $postIds,
            'errors' => $errors,
            'validate' => true,
        ]);
    }

    /**
     * @param  array<int, int>  $targetIds
     */
    protected function publishTargetsConcurrently(array $targetIds): void
    {
        if ($targetIds === []) {
            return;
        }

        $tasks = [];

        foreach ($targetIds as $targetId) {
            $tasks[(string) $targetId] = static fn (): array => app(SocialPostTargetPublisher::class)->publish($targetId);
        }

        try {
            Concurrency::run($tasks, timeout: 60);
        } catch (Throwable $exception) {
            SocialPostTarget::query()
                ->whereIn('id', $targetIds)
                ->whereIn('publish_status', [
                    SocialPostTarget::PUBLISH_STATUS_QUEUED,
                    SocialPostTarget::PUBLISH_STATUS_PUBLISHING,
                ])
                ->get()
                ->each(function (SocialPostTarget $target) use ($exception): void {
                    $target->forceFill([
                        'publish_status' => SocialPostTarget::PUBLISH_STATUS_FAILED,
                        'publish_attempts' => max(1, $target->publish_attempts),
                        'last_error' => $exception->getMessage(),
                    ])->save();
                });
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string'],
        ]);

        $post = $this->ownedPost($request, $data['id']);
        $errors = [];

        foreach ($post->targets()->with('connectedAccount')->get() as $target) {
            if (! $this->platforms->supports($target->provider)) {
                $errors[] = $this->platformError($target->provider, 'delete', "{$this->platformName($target->provider)} is not implemented.");

                continue;
            }

            if (! $target->provider_post_id) {
                $target->forceFill([
                    'delete_status' => SocialPostTarget::DELETE_STATUS_DELETED,
                    'deleted_at' => now(),
                    'last_error' => null,
                ])->save();

                continue;
            }

            try {
                $response = $this->platforms->adapter($target->provider)
                    ->delete($target->connectedAccount, $target);

                $manualDeleteRequired = (bool) ($response['manual_delete_required'] ?? false);

                $target->forceFill([
                    'delete_status' => $manualDeleteRequired
                        ? SocialPostTarget::DELETE_STATUS_MANUAL_REQUIRED
                        : SocialPostTarget::DELETE_STATUS_DELETED,
                    'provider_response' => array_merge($target->provider_response ?? [], ['delete' => $response]),
                    'deleted_at' => $manualDeleteRequired ? null : now(),
                    'last_error' => $response['message'] ?? null,
                ])->save();

                if ($manualDeleteRequired) {
                    $errors[] = $this->platformError($target->provider, 'delete', $response['message'] ?? 'Manual delete is required.');
                }
            } catch (Throwable $exception) {
                $target->forceFill([
                    'delete_status' => SocialPostTarget::DELETE_STATUS_FAILED,
                    'last_error' => $exception->getMessage(),
                ])->save();

                $errors[] = $this->platformError($target->provider, 'delete', $exception->getMessage());
            }
        }

        $post->refreshAggregateStatus();

        return response()->json([
            'status' => $errors === [] ? 'success' : 'error',
            'id' => (string) $post->id,
            'errors' => $errors,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $post = $this->ownedPost($request, $id)->load('targets');

        return response()->json($this->postInfoResponse($post));
    }

    public function comments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string'],
            'comment' => ['required', 'string'],
            'platform' => ['nullable', 'string'],
        ]);

        $post = $this->ownedPost($request, $data['id']);
        $targets = $post->targets()
            ->with('connectedAccount')
            ->where('publish_status', SocialPostTarget::PUBLISH_STATUS_PUBLISHED)
            ->when(filled($data['platform'] ?? null), fn ($query) => $query->where('provider', $this->normalizePlatform($data['platform'])))
            ->get();

        $errors = [];
        $comments = [];

        if ($targets->isEmpty()) {
            $platform = isset($data['platform']) ? $this->normalizePlatform($data['platform']) : 'all';
            $errors[] = $this->platformError($platform, 'comment', 'No published post target was found.');
        }

        foreach ($targets as $target) {
            if (! $this->platforms->supports($target->provider)) {
                $errors[] = $this->platformError($target->provider, 'comment', "{$this->platformName($target->provider)} is not implemented.");

                continue;
            }

            try {
                $response = $this->platforms->adapter($target->provider)
                    ->comment($target->connectedAccount, $target, $data['comment']);

                $comments[] = [
                    'id' => (string) ($response['id'] ?? $target->provider_post_id),
                    'platform' => $target->provider,
                    'status' => 'success',
                ];

                $target->forceFill([
                    'provider_response' => array_merge_recursive($target->provider_response ?? [], [
                        'comments' => [$response],
                    ]),
                    'last_error' => null,
                ])->save();
            } catch (Throwable $exception) {
                $errors[] = $this->platformError($target->provider, 'comment', $exception->getMessage());
            }
        }

        return response()->json([
            'id' => (string) $post->id,
            'comment' => $data['comment'],
            'status' => $errors === [] ? 'success' : 'error',
            'comments' => $comments,
            'errors' => $errors,
        ]);
    }

    public function postAnalytics(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string'],
            'platforms' => ['nullable', 'array', 'min:1'],
            'platforms.*' => ['required', 'string'],
            'searchPlatformId' => ['nullable', 'boolean'],
        ]);

        $owner = $this->tokenOwner($request);
        $response = [
            'id' => $data['id'],
            'status' => 'success',
        ];
        $errors = [];

        if ($data['searchPlatformId'] ?? false) {
            foreach ($this->requestedPlatforms($data['platforms'] ?? []) as $platform) {
                $account = $this->connectedAccounts($owner, $platform)->first();

                if (! $this->platforms->supports($platform) || ! $account) {
                    $errors[] = $this->platformError($platform, 'analytics', "{$this->platformName($platform)} is not linked or implemented.");

                    continue;
                }

                $this->mergeAnalytics($response, $errors, $account, $data['id']);
            }
        } else {
            $post = $this->ownedPost($request, $data['id']);

            foreach ($post->targets()->with('connectedAccount')->whereNotNull('provider_post_id')->get() as $target) {
                $this->mergeAnalytics($response, $errors, $target->connectedAccount, $target->provider_post_id);
            }
        }

        if ($errors !== []) {
            $response['status'] = 'error';
            $response['errors'] = $errors;
        }

        return response()->json($response);
    }

    public function socialAnalytics(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['required', 'string'],
            'quarters' => ['nullable', 'integer', 'min:1'],
        ]);

        $owner = $this->tokenOwner($request);
        $response = [];
        $errors = [];

        foreach ($this->requestedPlatforms($data['platforms']) as $platform) {
            $accounts = $this->connectedAccounts($owner, $platform);

            if (! $this->platforms->supports($platform) || $accounts->isEmpty()) {
                $errors[] = $this->platformError($platform, 'analytics', "{$this->platformName($platform)} is not linked or implemented.");

                continue;
            }

            try {
                if ($platform === 'gmb') {
                    $response[$platform] = [
                        'analytics' => $this->aggregateGoogleBusinessAnalytics($accounts),
                    ];

                    continue;
                }

                $response[$platform] = [
                    'analytics' => $this->platforms->adapter($platform)->accountAnalytics($accounts->first()),
                ];
            } catch (Throwable $exception) {
                $errors[] = $this->platformError($platform, 'analytics', $exception->getMessage());
            }
        }

        if ($errors !== []) {
            $response['errors'] = $errors;
        }

        return response()->json($response);
    }

    /**
     * @param  EloquentCollection<int, ConnectedAccount>  $accounts
     * @return array<string, mixed>
     */
    protected function aggregateGoogleBusinessAnalytics(EloquentCollection $accounts): array
    {
        $locations = $accounts
            ->map(fn (ConnectedAccount $account): array => $this->platforms->adapter('gmb')->accountAnalytics($account))
            ->values()
            ->all();

        return [
            'callClicks' => collect($locations)->sum('callClicks'),
            'websiteClicks' => collect($locations)->sum('websiteClicks'),
            'businessDirectionRequests' => collect($locations)->sum('businessDirectionRequests'),
            'businessImpressionsMobileMaps' => collect($locations)->sum('businessImpressionsMobileMaps'),
            'businessImpressionsDesktopMaps' => collect($locations)->sum('businessImpressionsDesktopMaps'),
            'businessImpressionsMobileSearch' => collect($locations)->sum('businessImpressionsMobileSearch'),
            'businessImpressionsDesktopSearch' => collect($locations)->sum('businessImpressionsDesktopSearch'),
            'locations' => $locations,
        ];
    }

    protected function mergeAnalytics(array &$response, array &$errors, ConnectedAccount $account, string $providerPostId): void
    {
        try {
            $response[$account->provider] = $this->platforms->adapter($account->provider)
                ->postAnalytics($account, $providerPostId);
        } catch (Throwable $exception) {
            $errors[] = $this->platformError($account->provider, 'analytics', $exception->getMessage());
        }
    }

    protected function tokenOwner(Request $request): Owner
    {
        $token = $request->user()?->currentAccessToken();

        abort_unless($token instanceof PersonalAccessToken && $token->owner_id, 403, 'This API token is not assigned to an owner.');

        $owner = Owner::query()
            ->whereKey($token->owner_id)
            ->where('is_active', true)
            ->first();

        abort_unless($owner, 403, 'This API token owner is not active.');

        return $owner;
    }

    protected function ownedPost(Request $request, string $id): SocialPost
    {
        $owner = $this->tokenOwner($request);

        return SocialPost::query()
            ->where('owner_id', $owner->id)
            ->findOrFail($id);
    }

    /**
     * @param  array<int, mixed>  $mediaUrls
     */
    protected function firstMediaUrl(array $mediaUrls): string
    {
        $url = collect($mediaUrls)->first(fn ($url): bool => filled($url));

        if (! is_string($url)) {
            throw ValidationException::withMessages([
                'mediaUrls' => 'At least one media URL is required.',
            ]);
        }

        return $url;
    }

    /**
     * @param  array<int, mixed>  $platforms
     * @return array<int, string>
     */
    protected function requestedPlatforms(array $platforms): array
    {
        return collect($platforms)
            ->map(fn (mixed $platform): string => $this->normalizePlatform((string) $platform))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function normalizePlatform(string $platform): string
    {
        return match (strtolower($platform)) {
            'x' => 'twitter',
            'google_business', 'google_business_profile' => 'gmb',
            default => strtolower($platform),
        };
    }

    /**
     * @return EloquentCollection<int, ConnectedAccount>
     */
    protected function connectedAccounts(Owner $owner, string $platform): EloquentCollection
    {
        return ConnectedAccount::query()
            ->where('owner_id', $owner->id)
            ->where('provider', $platform)
            ->where('status', ConnectedAccount::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{id: string|null, platform: string, status: string, postUrl: string|null}
     */
    protected function postIdResponse(SocialPostTarget $target): array
    {
        return [
            'id' => $target->provider_post_id,
            'platform' => $target->provider,
            'status' => 'success',
            'postUrl' => $target->provider_post_url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function postInfoResponse(SocialPost $post): array
    {
        $errors = $post->targets
            ->filter(fn (SocialPostTarget $target): bool => $target->publish_status === SocialPostTarget::PUBLISH_STATUS_FAILED)
            ->map(fn (SocialPostTarget $target): array => $this->platformError(
                $target->provider,
                'post',
                $target->last_error ?? 'Publishing failed.',
            ))
            ->values()
            ->all();

        return [
            'id' => (string) $post->id,
            'status' => $errors === [] ? 'success' : 'error',
            'post' => $post->caption,
            'postIds' => $post->targets
                ->filter(fn (SocialPostTarget $target): bool => $target->publish_status === SocialPostTarget::PUBLISH_STATUS_PUBLISHED)
                ->map(fn (SocialPostTarget $target): array => $this->postIdResponse($target))
                ->values()
                ->all(),
            'errors' => $errors,
            'validate' => true,
        ];
    }

    /**
     * @return array{code: int, action: string, status: string, message: string, platform: string}
     */
    protected function platformError(string $platform, string $action, string $message): array
    {
        return [
            'code' => 156,
            'action' => $action,
            'status' => 'error',
            'message' => $message,
            'platform' => $platform,
        ];
    }

    protected function platformName(string $platform): string
    {
        return match ($platform) {
            'gmb' => 'Google Business',
            'twitter' => 'Twitter',
            default => ucfirst($platform),
        };
    }
}
