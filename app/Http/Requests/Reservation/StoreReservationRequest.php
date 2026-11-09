<?php

namespace App\Http\Requests\Reservation;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Models\Flight;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Services\CountryList;
use App\Support\ReservationContactRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReservationRequest extends FormRequest
{
    /**
     * Never flash the card back into the form.
     *
     * @var list<string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'card_number',
        'card_cvc',
        'card_expiry',
    ];

    protected function prepareForValidation(): void
    {
        $merge = [];

        $passengers = $this->input('passengers');

        if (is_array($passengers)) {
            foreach ($passengers as $index => $passenger) {
                if (! is_array($passenger) || ! is_array($passenger['passport'] ?? null)) {
                    continue;
                }

                $passengers[$index]['passport']['number'] = strtoupper(str_replace(
                    ' ',
                    '',
                    (string) ($passenger['passport']['number'] ?? ''),
                ));
                $passengers[$index]['passport']['issuing_country'] = strtoupper(trim(
                    (string) ($passenger['passport']['issuing_country'] ?? ''),
                ));
            }

            $merge['passengers'] = $passengers;
        }

        // Accept 1226 / 12-26 / 12 / 26 → 12/26 for the gateway.
        $expiryDigits = preg_replace('/\D/', '', (string) $this->input('card_expiry', ''));
        if (is_string($expiryDigits) && strlen($expiryDigits) === 4) {
            $merge['card_expiry'] = substr($expiryDigits, 0, 2).'/'.substr($expiryDigits, 2, 2);
        }

        $cardDigits = preg_replace('/\D/', '', (string) $this->input('card_number', ''));
        if (is_string($cardDigits) && $cardDigits !== '') {
            $merge['card_number'] = trim(chunk_split($cardDigits, 4, ' '));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * Passport fields use the same rules as check-in's document form (number, country, expiry).
     *
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
            'passengers.*.cabin_class' => ['nullable', Rule::enum(CabinClass::class)],
            'passengers.*.date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'passengers.*.accompanying_adult' => ['nullable', 'integer', 'min:0'],
            'passengers.*.seat_id' => ['nullable', 'integer'],
            'passengers.*.extra_bags' => ['nullable', 'integer', 'min:0', 'max:10'],
            'passengers.*.extra_kg' => ['nullable', 'integer', 'min:0', 'max:100'],
            'passengers.*.passport' => ['required', 'array'],
            'passengers.*.passport.number' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9]+$/'],
            'passengers.*.passport.issuing_country' => ['required', 'string', Rule::in(app(CountryList::class)->codes())],
            'passengers.*.passport.expiry_date' => ['required', 'date_format:Y-m-d'],
            'card_number' => ['required', 'string', 'regex:/^[\d\s]{12,23}$/'],
            'card_expiry' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
            'card_cvc' => ['required', 'string', 'regex:/^\d{3,4}$/'],
            ...ReservationContactRules::rules(),
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
            'passengers.*.passport.required' => 'Enter the passport details.',
            'passengers.*.passport.number.regex' => 'The number may only contain letters and digits.',
            'passengers.*.passport.issuing_country.in' => 'Choose a country from the list.',
            'passengers.*.passport.expiry_date.date_format' => 'The expiry must be a date.',
            'card_number.required' => 'Enter the card number.',
            'card_number.regex' => 'Enter a valid card number.',
            'card_expiry.required' => 'Enter the card expiry (MM/YY).',
            'card_expiry.regex' => 'Expiry must be MM/YY (e.g. 12/30).',
            'card_cvc.required' => 'Enter the CVC.',
            'card_cvc.regex' => 'CVC must be 3 or 4 digits.',
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
                $expiry = (string) $this->input('card_expiry', '');

                if (preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $expiry)) {
                    $endOfMonth = Carbon::createFromFormat('!m/y', $expiry)->endOfMonth();

                    if ($endOfMonth->lt(now())) {
                        $validator->errors()->add(
                            'card_expiry',
                            'Card expiry must be this month or later (MM/YY). Today is '.now()->format('M Y').'.',
                        );
                    }
                }

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

                    $cabin = $passenger['cabin_class'] ?? null;

                    if ($cabin === null || $cabin === '') {
                        $validator->errors()->add("passengers.$index.cabin_class", 'Choose a cabin class.');
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

                $seats = Seat::query()
                    ->where('aircraft_id', $flight->aircraft_id)
                    ->whereIn('id', array_keys($chosenSeats))
                    ->get()
                    ->keyBy(fn (Seat $seat): int => (int) $seat->id);

                $taken = array_map('intval', SeatAssignment::query()
                    ->where('flight_id', $flight->id)
                    ->whereIn('seat_id', array_keys($chosenSeats))
                    ->pluck('seat_id')
                    ->all());

                foreach ($chosenSeats as $seatId => $index) {
                    $seat = $seats->get($seatId);
                    $cabin = $passengers[$index]['cabin_class'] ?? null;

                    if ($seat === null) {
                        $validator->errors()->add("passengers.$index.seat_id", 'Choose a seat on this aircraft.');
                    } elseif ($cabin !== null && $cabin !== '' && $seat->cabin_class->value !== $cabin) {
                        $validator->errors()->add("passengers.$index.seat_id", 'Choose a seat in that cabin class.');
                    } elseif (in_array($seatId, $taken, true)) {
                        $validator->errors()->add("passengers.$index.seat_id", 'Seat taken');
                    }
                }
            },
        ];
    }
}
