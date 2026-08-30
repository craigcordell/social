<?php

namespace App\Services\MetaMarketing;

use App\Models\MetaAdOperation;
use App\Models\Owner;
use Closure;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class MetaAdOperationRunner
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(MetaAdOperation): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function run(
        Owner $owner,
        string $type,
        string $idempotencyKey,
        array $payload,
        Closure $callback,
    ): array {
        $adAccountId = $this->adAccountId();
        $requestHash = hash('sha256', $this->canonicalJson($payload));

        $operation = MetaAdOperation::query()->firstOrCreate(
            [
                'owner_id' => $owner->id,
                'ad_account_id' => $adAccountId,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'type' => $type,
                'request_hash' => $requestHash,
                'request_payload' => $payload,
                'status' => MetaAdOperation::STATUS_PENDING,
            ],
        );

        if (! hash_equals((string) $operation->request_hash, $requestHash) || (string) $operation->type !== $type) {
            throw new ConflictHttpException('The Idempotency-Key was already used for a different Meta mutation.');
        }

        if (! $operation->wasRecentlyCreated) {
            if ($operation->status === MetaAdOperation::STATUS_SUCCEEDED) {
                $responsePayload = is_array($operation->response_payload) ? $operation->response_payload : [];

                return [
                    ...$responsePayload,
                    'operation_id' => (int) $operation->id,
                    'idempotent_replay' => true,
                ];
            }

            throw new ConflictHttpException(
                $operation->status === MetaAdOperation::STATUS_PENDING
                    ? 'This Meta mutation is already in progress.'
                    : 'This Meta mutation previously failed. Use a new Idempotency-Key after reviewing the failure.',
            );
        }

        try {
            $result = $callback($operation);
            $response = [
                ...$result,
                'operation_id' => (int) $operation->id,
                'idempotent_replay' => false,
            ];

            $operation->forceFill([
                'status' => MetaAdOperation::STATUS_SUCCEEDED,
                'response_payload' => $response,
                'error_message' => null,
                'completed_at' => now(),
            ])->save();

            return $response;
        } catch (Throwable $exception) {
            $operation->forceFill([
                'status' => MetaAdOperation::STATUS_FAILED,
                'error_message' => $this->sanitizeError($exception->getMessage()),
                'completed_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    protected function adAccountId(): string
    {
        $adAccountId = (string) config('services.meta_marketing.ad_account_id');

        return str_starts_with($adAccountId, 'act_') ? substr($adAccountId, 4) : $adAccountId;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    protected function canonicalJson(array $payload): string
    {
        $payload = $this->sortRecursively($payload);

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    protected function sortRecursively(array $payload): array
    {
        if (Arr::isAssoc($payload)) {
            ksort($payload);
        }

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $value = $this->sortRecursively($value);
            }
        }

        return $payload;
    }

    protected function sanitizeError(string $message): string
    {
        $message = preg_replace('/([?&](?:access_token|appsecret_proof)=)[^&\s]+/i', '$1[redacted]', $message);

        return mb_substr($message ?? 'Meta mutation failed.', 0, 5000);
    }
}
