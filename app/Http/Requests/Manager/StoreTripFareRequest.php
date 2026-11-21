<?php

namespace App\Http\Requests\Manager;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTripFareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['origin_code', 'destination_code'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'origin_code' => ['required', 'string', 'size:3', Rule::exists('airports', 'code')],
            'destination_code' => [
                'required', 'string', 'size:3', Rule::exists('airports', 'code'), 'different:origin_code',
            ],
            'cabin_class' => ['required', Rule::enum(CabinClass::class)],
            'passenger_type' => ['required', Rule::enum(PassengerType::class)],
            'one_way' => ['required', 'numeric', 'gt:0', 'max:99999.99'],
            'round_trip' => ['nullable', 'numeric', 'gt:0', 'max:99999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destination_code.different' => 'A trip fare needs at least two airports (origin and destination).',
            'one_way.gt' => 'The one-way amount must be greater than zero.',
            'round_trip.gt' => 'The round-trip amount must be greater than zero when set.',
        ];
    }
}
