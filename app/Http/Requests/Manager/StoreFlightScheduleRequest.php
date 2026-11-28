<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFlightScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['flight_no', 'origin', 'destination', 'gate'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }

        $days = $this->input('days_of_week', []);
        if (is_array($days)) {
            $this->merge([
                'days_of_week' => collect($days)->map(fn ($d) => (int) $d)->unique()->sort()->values()->all(),
            ]);
        }

        $this->merge(['active' => $this->boolean('active')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $code = preg_quote((string) config('arcs.airline.code'), '/');

        return [
            'flight_no' => ['required', 'string', 'regex:/^'.$code.'\d{1,4}$/'],
            'origin' => ['required', 'regex:/^[A-Z]{3}$/', Rule::exists('airports', 'code')],
            'destination' => ['required', 'regex:/^[A-Z]{3}$/', Rule::exists('airports', 'code'), 'different:origin'],
            'aircraft_id' => ['required', 'integer', Rule::exists('aircraft', 'id')],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'departure_local_time' => ['required', 'date_format:H:i'],
            'block_minutes' => ['required', 'integer', 'min:30', 'max:1080'],
            'gate' => ['nullable', 'regex:/^[A-Z]\d{1,2}$/'],
            'checkin_opens_minutes_before' => ['nullable', 'integer', 'min:30', 'max:720'],
            'checkin_closes_minutes_before' => ['nullable', 'integer', 'min:15', 'max:360'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['required', 'date', 'after_or_equal:valid_from'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = Carbon::parse($this->input('valid_from'))->startOfDay();
            $to = Carbon::parse($this->input('valid_to'))->startOfDay();

            if ($from->diffInDays($to) > 366) {
                $validator->errors()->add('valid_to', 'The validity range may be at most 1 year.');
            }

            $opens = (int) ($this->input('checkin_opens_minutes_before') ?? 180);
            $closes = (int) ($this->input('checkin_closes_minutes_before') ?? 45);
            if ($closes >= $opens) {
                $validator->errors()->add(
                    'checkin_closes_minutes_before',
                    'Check-in must close after it opens (fewer minutes before departure).',
                );
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function scheduleData(): array
    {
        $data = $this->validated();
        $data['departure_local_time'] = $data['departure_local_time'].':00';
        $data['checkin_opens_minutes_before'] = (int) ($data['checkin_opens_minutes_before'] ?? 180);
        $data['checkin_closes_minutes_before'] = (int) ($data['checkin_closes_minutes_before'] ?? 45);
        $data['active'] = array_key_exists('active', $data) ? (bool) $data['active'] : true;
        $data['gate'] = $data['gate'] ?? null;

        return $data;
    }
}
