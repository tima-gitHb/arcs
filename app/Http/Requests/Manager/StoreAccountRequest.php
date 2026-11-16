<?php

namespace App\Http\Requests\Manager;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    /**
     * Who may submit is already decided by the role:manager middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A blank station is already null. A code is uppercased so it matches airports.code.
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(Role::class)],
            // Empty is allowed: check-in and gate then see "No station set" until a manager chooses one.
            'airport_code' => ['nullable', 'string', Rule::exists('airports', 'code')],
        ];
    }
}
