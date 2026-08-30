<?php

namespace App\Http\Requests\Api\Meta;

use Illuminate\Foundation\Http\FormRequest;

class IncreaseAdBudgetRequest extends FormRequest
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

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'increase_by_minor' => ['required', 'integer', 'min:1'],
        ];
    }
}
