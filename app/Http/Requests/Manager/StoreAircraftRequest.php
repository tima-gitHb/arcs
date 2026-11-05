<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAircraftRequest extends FormRequest
{
    /**
     * Who may submit is already decided by the role:manager middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'registration' => ['required', 'string', 'max:255', Rule::unique('aircraft', 'registration')],
            'model' => ['required', 'string', 'max:255'],
            'rows' => ['required', 'integer', 'between:1,99'],
            'seats_per_row' => ['required', 'integer', 'between:1,10'],
            'business_rows' => ['required', 'integer', 'min:0', 'lte:rows'],
            // No field on the form yet (card M8); AircraftService checks business + premium <= rows.
            'premium_rows' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
