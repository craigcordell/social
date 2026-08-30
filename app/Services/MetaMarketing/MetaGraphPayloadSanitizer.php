<?php

namespace App\Services\MetaMarketing;

final class MetaGraphPayloadSanitizer
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function sanitize(array $payload): array
    {
        if (isset($payload['paging']) && is_array($payload['paging'])) {
            if (isset($payload['paging']['next'])) {
                $payload['paging']['has_next_page'] = true;
            }

            unset($payload['paging']['previous'], $payload['paging']['next']);
        }

        foreach ($payload as $key => $value) {
            if (is_string($key) && hash_equals('access_token', $key)) {
                $payload[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->sanitize($value);

                continue;
            }

            if (is_string($value) && str_contains($value, 'access_token=')) {
                $payload[$key] = preg_replace('/([?&]access_token=)[^&]+/', '$1[redacted]', $value) ?? $value;
            }
        }

        return $payload;
    }
}
