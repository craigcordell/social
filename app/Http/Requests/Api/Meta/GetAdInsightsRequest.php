<?php

namespace App\Http\Requests\Api\Meta;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GetAdInsightsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'level' => ['nullable', Rule::in(['account', 'campaign', 'adset', 'ad'])],
            'since' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:since'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'after' => ['nullable', 'string', 'max:500'],
        ];
    }
}
