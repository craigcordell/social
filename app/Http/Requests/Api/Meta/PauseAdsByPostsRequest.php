<?php

namespace App\Http\Requests\Api\Meta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PauseAdsByPostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'posts' => ['required', 'array', 'min:1', 'max:50'],
            'posts.*' => ['required', 'array:client_reference,platform,post_url'],
            'posts.*.client_reference' => ['nullable', 'string', 'max:100'],
            'posts.*.platform' => ['required', Rule::in(['facebook', 'instagram'])],
            'posts.*.post_url' => ['required', 'url:http,https', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $posts = $this->input('posts', []);

            if (! is_array($posts)) {
                return;
            }

            foreach ($posts as $index => $post) {
                if (! is_array($post)) {
                    continue;
                }

                $platform = $post['platform'] ?? null;
                $postUrl = $post['post_url'] ?? null;

                if (! is_string($platform) || ! is_string($postUrl)) {
                    continue;
                }

                if (! $this->isSupportedPostUrl($platform, $postUrl)) {
                    $validator->errors()->add(
                        "posts.{$index}.post_url",
                        "The post URL is not a supported {$platform} post URL.",
                    );
                }
            }
        });
    }

    protected function isSupportedPostUrl(string $platform, string $postUrl): bool
    {
        $host = strtolower((string) parse_url($postUrl, PHP_URL_HOST));

        return match ($platform) {
            'facebook' => in_array($host, ['facebook.com', 'www.facebook.com', 'm.facebook.com'], true)
                && $this->isFacebookPostUrl($postUrl),
            'instagram' => in_array($host, ['instagram.com', 'www.instagram.com'], true)
                && preg_match('~instagram\.com/(?:p|reel|tv)/[A-Za-z0-9_-]+~i', $postUrl) === 1,
            default => false,
        };
    }

    protected function isFacebookPostUrl(string $postUrl): bool
    {
        $path = trim((string) parse_url($postUrl, PHP_URL_PATH), '/');

        return preg_match('~(?:^|/)[0-9]+_[0-9]+$~', $path) === 1;
    }
}
