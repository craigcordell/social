<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DeleteSocialPostTarget;
use App\Jobs\PublishSocialPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SocialPostController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:owners,id'],
            'target_ids' => ['required', 'array', 'min:1'],
            'target_ids.*' => ['integer', 'distinct', 'exists:connected_accounts,id'],
            'caption' => ['required', 'string', 'max:2200'],
            'image_url' => ['required', 'url', 'starts_with:http://,https://'],
            'link_url' => ['nullable', 'url', 'starts_with:http://,https://'],
            'scheduled_at' => ['nullable', 'date'],
            'external_id' => ['nullable', 'string', 'max:120'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        Owner::query()->findOrFail($data['owner_id']);

        $targets = ConnectedAccount::query()
            ->where('owner_id', $data['owner_id'])
            ->where('status', ConnectedAccount::STATUS_ACTIVE)
            ->whereIn('id', $data['target_ids'])
            ->get();

        if ($targets->count() !== count($data['target_ids'])) {
            throw ValidationException::withMessages([
                'target_ids' => 'All targets must be active connected accounts for the selected owner.',
            ]);
        }

        if (! empty($data['idempotency_key'])) {
            $existing = SocialPost::query()
                ->with(['owner', 'targets.connectedAccount'])
                ->where('owner_id', $data['owner_id'])
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing) {
                return response()->json(['data' => $this->serializePost($existing)]);
            }
        }

        [$post, $targetIds] = DB::transaction(function () use ($data, $targets): array {
            $scheduledAt = isset($data['scheduled_at']) ? now()->parse($data['scheduled_at']) : null;

            $post = SocialPost::query()->create([
                'owner_id' => $data['owner_id'],
                'external_id' => $data['external_id'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'caption' => $data['caption'],
                'image_url' => $data['image_url'],
                'link_url' => $data['link_url'] ?? null,
                'scheduled_at' => $scheduledAt,
                'status' => $scheduledAt?->isFuture() ? SocialPost::STATUS_SCHEDULED : SocialPost::STATUS_QUEUED,
            ]);

            $targetIds = $targets->map(function (ConnectedAccount $account) use ($post): int {
                return SocialPostTarget::query()->create([
                    'social_post_id' => $post->id,
                    'connected_account_id' => $account->id,
                    'provider' => $account->provider,
                    'publish_status' => SocialPostTarget::PUBLISH_STATUS_QUEUED,
                ])->id;
            })->all();

            return [$post, $targetIds];
        });

        $delay = $post->scheduled_at?->isFuture() ? $post->scheduled_at : null;

        foreach ($targetIds as $targetId) {
            PublishSocialPostTarget::dispatch($targetId)->delay($delay);
        }

        return response()->json([
            'data' => $this->serializePost($post->load(['owner', 'targets.connectedAccount'])),
        ], 202);
    }

    public function show(SocialPost $socialPost): JsonResponse
    {
        return response()->json([
            'data' => $this->serializePost($socialPost->load(['owner', 'targets.connectedAccount'])),
        ]);
    }

    public function destroy(Request $request, SocialPost $socialPost): JsonResponse
    {
        $data = $request->validate([
            'target_ids' => ['nullable', 'array', 'min:1'],
            'target_ids.*' => ['integer', 'distinct', 'exists:social_post_targets,id'],
        ]);

        $targets = $socialPost->targets()
            ->when(! empty($data['target_ids']), fn ($query) => $query->whereIn('id', $data['target_ids']))
            ->get();

        if (! empty($data['target_ids']) && $targets->count() !== count($data['target_ids'])) {
            throw ValidationException::withMessages([
                'target_ids' => 'All target ids must belong to this post.',
            ]);
        }

        foreach ($targets as $target) {
            $target->forceFill([
                'delete_status' => SocialPostTarget::DELETE_STATUS_QUEUED,
                'last_error' => null,
            ])->save();

            DeleteSocialPostTarget::dispatch($target->id);
        }

        $socialPost->refreshAggregateStatus();

        return response()->json([
            'data' => $this->serializePost($socialPost->fresh(['owner', 'targets.connectedAccount'])),
        ], 202);
    }

    protected function serializePost(SocialPost $post): array
    {
        return [
            'id' => $post->id,
            'owner_id' => $post->owner_id,
            'external_id' => $post->external_id,
            'caption' => $post->caption,
            'image_url' => $post->image_url,
            'link_url' => $post->link_url,
            'scheduled_at' => $post->scheduled_at?->toJSON(),
            'status' => $post->status,
            'targets' => $post->targets->map(fn (SocialPostTarget $target): array => [
                'id' => $target->id,
                'connected_account_id' => $target->connected_account_id,
                'provider' => $target->provider,
                'display_name' => $target->connectedAccount?->display_name,
                'publish_status' => $target->publish_status,
                'delete_status' => $target->delete_status,
                'provider_post_id' => $target->provider_post_id,
                'provider_post_url' => $target->provider_post_url,
                'publish_attempts' => $target->publish_attempts,
                'delete_attempts' => $target->delete_attempts,
                'last_error' => $target->last_error,
                'published_at' => $target->published_at?->toJSON(),
                'deleted_at' => $target->deleted_at?->toJSON(),
            ]),
        ];
    }
}
