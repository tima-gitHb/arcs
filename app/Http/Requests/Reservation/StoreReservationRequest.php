<?php

namespace App\Http\Requests\Reservation;

use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Models\Flight;
use App\Models\Seat;
use App\Models\SeatAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReservationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'flight_id' => [
                'required',
                'integer',
                Rule::exists('flights', 'id')->where(
                    fn ($query) => $query->whereNotIn('status', [
                        FlightStatus::Departed->value,
                        FlightStatus::Cancelled->value,
                    ]),
                ),
            ],
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.first_name' => ['required', 'string', 'max:255'],
            'passengers.*.last_name' => ['required', 'string', 'max:255'],
            'passengers.*.type' => ['required', Rule::enum(PassengerType::class)],
            'passengers.*.date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'passengers.*.accompanying_adult' => ['nullable', 'integer', 'min:0'],
            'passengers.*.seat_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'flight_id.required' => 'Choose a flight.',
            'flight_id.exists' => 'That flight is not open for reservations.',
            'passengers.required' => 'Add at least one passenger.',
            'passengers.min' => 'Add at least one passenger.',
            'passengers.*.first_name.required' => 'Enter a first name.',
            'passengers.*.last_name.required' => 'Enter a last name.',
            'passengers.*.type.required' => 'Choose Adult, Child, or Infant.',
            'passengers.*.date_of_birth.required' => 'Enter a date of birth.',
            'passengers.*.date_of_birth.date' => 'Enter a valid date of birth.',
            'passengers.*.date_of_birth.before_or_equal' => 'Date of birth cannot be in the future.',
        ];
    }

    /**
     * An infant has to point at an Adult row on this same booking (SR-24).
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $passengers = $this->input('passengers', []);

                if (! is_array($passengers)) {
                    return;
                }

                $flight = Flight::query()->find($this->integer('flight_id'));
                $chosenSeats = [];

                foreach ($passengers as $index => $passenger) {
                    if (! is_array($passenger)) {
                        continue;
                    }

                    $type = $passenger['type'] ?? null;
                    $seatId = $passenger['seat_id'] ?? null;
                    $hasSeat = $seatId !== null && $seatId !== '';

                    if ($type === PassengerType::Infant->value) {
                        $adultIndex = $passenger['accompanying_adult'] ?? null;
                        $adult = is_numeric($adultIndex) ? ($passengers[(int) $adultIndex] ?? null) : null;
                        $linkedToAdult = is_array($adult)
                            && (int) $adultIndex !== (int) $index
                            && ($adult['type'] ?? null) === PassengerType::Adult->value;

                        if (! $linkedToAdult) {
                            $validator->errors()->add(
                                "passengers.$index.accompanying_adult",
                                'An infant must be linked to an adult on the same reservation.',
                            );
                        }

                        if ($hasSeat) {
                            $validator->errors()->add(
                                "passengers.$index.seat_id",
                                'An infant does not get a seat.',
                            );
                        }

                        continue;
                    }

                    if (! $hasSeat) {
                        $validator->errors()->add("passengers.$index.seat_id", 'Choose a seat.');

                        continue;
                    }

                    if (isset($chosenSeats[(int) $seatId])) {
                        $validator->errors()->add("passengers.$index.seat_id", 'Seat taken');

                        continue;
                    }

                    $chosenSeats[(int) $seatId] = $index;
                }

                if ($flight === null || $chosenSeats === []) {
                    return;
                }

                $onAircraft = array_map('intval', Seat::query()
                    ->where('aircraft_id', $flight->aircraft_id)
                    ->whereIn('id', array_keys($chosenSeats))
                    ->pluck('id')
                    ->all());

                $taken = array_map('intval', SeatAssignment::query()
                    ->where('flight_id', $flight->id)
                    ->whereIn('seat_id', array_keys($chosenSeats))
                    ->pluck('seat_id')
                    ->all());

                foreach ($chosenSeats as $seatId => $index) {
                    if (! in_array($seatId, $onAircraft, true)) {
                        $validator->errors()->add("passengers.$index.seat_id", 'Choose a seat on this aircraft.');
                    } elseif (in_array($seatId, $taken, true)) {
                        $validator->errors()->add("passengers.$index.seat_id", 'Seat taken');
                    }
                }
            },
        ];
    }
}
