<?php

namespace App\Http\Requests\Api\Meta;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCampaignsRequest extends FormRequest
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
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'effective_status' => ['nullable', 'array', 'max:3'],
            'effective_status.*' => ['required', Rule::in(['ACTIVE', 'PAUSED', 'ARCHIVED'])],
            'after' => ['nullable', 'string', 'max:500'],
        ];
    }
}
