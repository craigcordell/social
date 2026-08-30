<?php

namespace App\Services\MetaMarketing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final class MetaGraphApiClient
{
    public function __construct(
        private readonly MetaMarketingConfiguration $configuration,
        private readonly MetaGraphPayloadSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->getWithToken($this->configuration->accessToken(), $path, $query);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function getWithToken(
        #[SensitiveParameter]
        string $accessToken,
        string $path,
        array $query = [],
    ): array {
        $response = $this->readRequest($accessToken)
            ->get(
                $this->configuration->endpoint($path),
                array_filter(
                    [
                        ...$query,
                        'appsecret_proof' => $this->configuration->appSecretProof($accessToken),
                    ],
                    static fn (mixed $value): bool => $value !== null && $value !== '',
                ),
            )
            ->throw()
            ->json();

        return $this->normalizeResponse($response);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        $accessToken = $this->configuration->accessToken();
        $response = $this->mutationRequest($accessToken)
            ->post(
                $this->configuration->endpoint($path),
                array_filter(
                    [
                        ...$payload,
                        'appsecret_proof' => $this->configuration->appSecretProof($accessToken),
                    ],
                    static fn (mixed $value): bool => $value !== null && $value !== '',
                ),
            )
            ->throw()
            ->json();

        return $this->normalizeResponse($response);
    }

    protected function readRequest(#[SensitiveParameter] string $accessToken): PendingRequest
    {
        return Http::acceptJson()
            ->timeout((int) config('services.meta_marketing.timeout', 15))
            ->connectTimeout((int) config('services.meta_marketing.connect_timeout', 5))
            ->retry(
                [100, 500],
                when: static fn (Throwable $exception): bool => (
                    $exception instanceof ConnectionException
                    || (
                        $exception instanceof RequestException
                        && ($exception->response->status() === 429 || $exception->response->serverError())
                    )
                ),
            )
            ->withToken($accessToken);
    }

    protected function mutationRequest(#[SensitiveParameter] string $accessToken): PendingRequest
    {
        return Http::acceptJson()
            ->asForm()
            ->timeout((int) config('services.meta_marketing.timeout', 15))
            ->connectTimeout((int) config('services.meta_marketing.connect_timeout', 5))
            ->withToken($accessToken);
    }

    /** @return array<string, mixed> */
    protected function normalizeResponse(mixed $response): array
    {
        if (! is_array($response)) {
            return [];
        }

        $payload = [];

        foreach ($response as $key => $value) {
            $payload[is_int($key) ? '_'.$key : $key] = $value;
        }

        $sanitizedPayload = $this->sanitizer->sanitize($payload);
        $result = [];

        foreach ($sanitizedPayload as $key => $value) {
            $result[is_int($key) ? '_'.$key : $key] = $value;
        }

        return $result;
    }
}
