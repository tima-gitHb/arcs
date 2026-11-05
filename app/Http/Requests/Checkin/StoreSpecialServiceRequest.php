<?php

namespace App\Http\Requests\Checkin;

use App\Enums\SpecialServiceCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The "Add service" form on the check-in screen (C7).
 * Whether details are required is a rule of SpecialServiceService, not of the form.
 */
class StoreSpecialServiceRequest extends FormRequest
{
    protected $errorBag = 'addService';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', Rule::enum(SpecialServiceCode::class)],
            'details' => ['nullable', 'string', 'max:500'],
        ];
    }
}
