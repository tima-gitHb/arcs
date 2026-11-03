<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFlightGateRequest extends FormRequest
{
    /**
     * Who may submit is already decided by the role:manager middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * "b4" is accepted as "B4": uppercase before the rules run. An empty gate stays null.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->gate)) {
            $this->merge(['gate' => strtoupper(trim($this->gate))]);
        }
    }

    /**
     * An empty gate is allowed: it clears the gate.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'gate' => ['nullable', 'regex:/^[A-Z]\d{1,2}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gate.regex' => 'Use a letter and one or two digits, e.g. B4.',
        ];
    }
}
