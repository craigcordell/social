<?php

namespace App\Http\Requests\Api\Meta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ResolveMetaAdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'ad_id' => ['nullable', 'string', 'regex:/\A[0-9]+\z/'],
            'instagram_media_id' => ['nullable', 'string', 'regex:/\A[0-9]+\z/'],
            'instagram_internal_media_id' => ['nullable', 'string', 'regex:/\A[0-9]+\z/'],
            'instagram_shortcode' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'instagram_permalink' => ['nullable', 'url:http,https', 'max:500'],
            'instagram_edit_url' => ['nullable', 'string', 'max:1000'],
            'instagram_boosted_id' => ['nullable', 'string', 'regex:/\A[0-9]+\z/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (collect([
                'ad_id',
                'instagram_media_id',
                'instagram_internal_media_id',
                'instagram_shortcode',
                'instagram_permalink',
                'instagram_edit_url',
            ])->every(fn (string $key): bool => blank($this->input($key)))) {
                $validator->errors()->add(
                    'reference',
                    'Provide an ad ID or an Instagram media, shortcode, permalink, or edit URL reference.',
                );
            }
        });
    }
}
