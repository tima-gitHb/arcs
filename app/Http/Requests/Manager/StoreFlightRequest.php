<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFlightRequest extends FormRequest
{
    /**
     * Who may submit is already decided by the role:manager middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * "me205" is accepted as "ME205": uppercase before the rules run.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->flight_no)) {
            $this->merge(['flight_no' => strtoupper(trim($this->flight_no))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'flight_no' => ['required', 'string', 'regex:/^[A-Z0-9]{2}\d{1,4}$/'],
            'origin' => ['required', 'regex:/^[A-Z]{3}$/'],
            'destination' => ['required', 'regex:/^[A-Z]{3}$/', 'different:origin'],
            'aircraft_id' => ['required', 'integer', Rule::exists('aircraft', 'id')],
            'departure_at' => ['required', 'date'],
            'checkin_opens_at' => ['required', 'date', 'before:checkin_closes_at'],
            'checkin_closes_at' => ['required', 'date', 'before:departure_at'],
            'gate' => ['nullable', 'regex:/^[A-Z]\d{1,2}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'flight_no.regex' => 'Use an airline code and a number, e.g. ME205.',
            'origin.regex' => 'Use a 3-letter airport code, e.g. BEY.',
            'destination.regex' => 'Use a 3-letter airport code, e.g. CDG.',
            'gate.regex' => 'Use a letter and one or two digits, e.g. B4.',
            'checkin_opens_at.before' => 'Check-in must open before it closes.',
            'checkin_closes_at.before' => 'Check-in must close before departure.',
        ];
    }
}
