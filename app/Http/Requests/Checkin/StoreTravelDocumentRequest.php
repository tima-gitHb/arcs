<?php

namespace App\Http\Requests\Checkin;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The "Add document" form on the check-in screen (C2).
 * Errors go to their own bag so they show under the right form.
 */
class StoreTravelDocumentRequest extends FormRequest
{
    protected $errorBag = 'addDocument';

    protected function prepareForValidation(): void
    {
        $this->merge([
            'number' => strtoupper(str_replace(' ', '', (string) $this->input('number'))),
            'issuing_country' => strtoupper(trim((string) $this->input('issuing_country'))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DocumentType::class)],
            'number' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9]+$/'],
            'issuing_country' => ['required', 'string', 'size:2', 'alpha'],
            'expiry_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.regex' => 'The number may only contain letters and digits.',
            'issuing_country.size' => 'Use the 2-letter country code, e.g. LB.',
            'expiry_date.date_format' => 'The expiry must be a date.',
        ];
    }
}
