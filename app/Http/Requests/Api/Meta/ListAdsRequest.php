<?php

namespace App\Http\Requests\Api\Meta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'effective_status' => ['nullable', 'array', 'max:10'],
            'effective_status.*' => [
                'required',
                Rule::in([
                    'ACTIVE',
                    'PAUSED',
                    'ARCHIVED',
                    'DELETED',
                    'CAMPAIGN_PAUSED',
                    'ADSET_PAUSED',
                    'DISAPPROVED',
                    'PENDING_REVIEW',
                    'PREAPPROVED',
                    'WITH_ISSUES',
                ]),
            ],
            'after' => ['nullable', 'string', 'max:500'],
        ];
    }
}
