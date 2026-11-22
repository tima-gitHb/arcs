<?php

namespace App\Http\Requests\Manager;

use App\Models\Airport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
     * "ar205", "bey" and "b4" are accepted as "AR205", "BEY" and "B4": uppercase before the rules run.
     * Only strings are touched, so an empty gate stays null.
     */
    protected function prepareForValidation(): void
    {
        foreach (['flight_no', 'origin', 'destination', 'gate'] as $field) {
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
            'flight_no' => ['required', 'string', 'regex:/^[A-Z0-9]{2}\d{1,4}$/'],
            'origin' => ['required', 'regex:/^[A-Z]{3}$/', Rule::exists('airports', 'code')],
            'destination' => ['required', 'regex:/^[A-Z]{3}$/', Rule::exists('airports', 'code'), 'different:origin'],
            'aircraft_id' => ['required', 'integer', Rule::exists('aircraft', 'id')],
            'departure_at' => ['required', 'date'],
            // Optional (B13b): needed before another flight can follow this one in a journey.
            'arrives_at' => ['nullable', 'date'],
            'checkin_opens_at' => ['required', 'date', 'before:checkin_closes_at'],
            'checkin_closes_at' => ['required', 'date', 'before:departure_at'],
            'gate' => ['nullable', 'regex:/^[A-Z]\d{1,2}$/'],
        ];
    }

    /**
     * The arrival is typed in the destination's local time, the departure in the origin's: compare
     * the instants, not the text (B13b).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('arrives_at')) {
                return;
            }

            $data = $this->flightData();
            if ($data['arrives_at']->lte($data['departure_at'])) {
                $validator->errors()->add('arrives_at', 'The arrival must be after the departure.');
            }
        }];
    }

    /**
     * The validated data with the times read as the Manager types them (B13a): departure and check-in
     * in the origin airport's local time, arrival in the destination's (B13b). The model stores UTC.
     *
     * @return array<string, mixed>
     */
    public function flightData(): array
    {
        $data = $this->validated();
        $zone = Airport::timezoneOf($data['origin']);

        foreach (['departure_at', 'checkin_opens_at', 'checkin_closes_at'] as $field) {
            $data[$field] = Carbon::parse($data[$field], $zone);
        }

        $data['arrives_at'] = filled($data['arrives_at'] ?? null)
            ? Carbon::parse($data['arrives_at'], Airport::timezoneOf($data['destination']))
            : null;

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'flight_no.regex' => 'Use an airline code and a number, e.g. '.config('arcs.airline.code').'205.',
            'origin.regex' => 'Use a 3-letter airport code, e.g. BEY.',
            'destination.regex' => 'Use a 3-letter airport code, e.g. CDG.',
            'origin.exists' => 'Unknown airport. Use an airport of the network.',
            'destination.exists' => 'Unknown airport. Use an airport of the network.',
            'gate.regex' => 'Use a letter and one or two digits, e.g. B4.',
            'checkin_opens_at.before' => 'Check-in must open before it closes.',
            'checkin_closes_at.before' => 'Check-in must close before departure.',
        ];
    }
}
