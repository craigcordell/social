<?php

namespace App\Http\Requests\Api\Meta;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBoostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'platform' => ['required', Rule::in(['facebook', 'instagram'])],
            'post_id' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'daily_budget_minor' => ['required', 'integer', 'min:1'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'status' => ['nullable', Rule::in(['PAUSED', 'ACTIVE'])],
            'template_ad_set_id' => ['nullable', 'string', 'max:100', 'regex:/\A[0-9]+\z/'],
        ];
    }
}
