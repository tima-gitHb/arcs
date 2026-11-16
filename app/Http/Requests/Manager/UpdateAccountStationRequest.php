<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountStationRequest extends FormRequest
{
    /**
     * Who may submit is already decided by the role:manager middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A blank choice is no station. A typed code is uppercased so it matches airports.code.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('airport_code'))) {
            $this->merge(['airport_code' => strtoupper(trim($this->input('airport_code')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'airport_code' => ['nullable', 'string', Rule::exists('airports', 'code')],
        ];
    }
}
