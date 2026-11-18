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

        if (! $this->filled('outbound_flight_ids') && $this->filled('flight_id')) {
            $merge['outbound_flight_ids'] = [$this->integer('flight_id')];
        }

        if (! $this->filled('journey_type')) {
            $outbound = $this->input('outbound_flight_ids', $merge['outbound_flight_ids'] ?? []);
            $return = $this->input('return_flight_ids', []);
            $merge['journey_type'] = match (true) {
                is_array($return) && $return !== [] => 'round_trip',
                is_array($outbound) && count($outbound) > 1 => 'connection',
                default => 'one_way',
            };
        }

        $passengers = $this->input('passengers');

        if (is_array($passengers)) {
            $firstFlightId = (int) (($merge['outbound_flight_ids'] ?? $this->input('outbound_flight_ids', [null]))[0] ?? 0);

            foreach ($passengers as $index => $passenger) {
                if (! is_array($passenger)) {
                    continue;
                }

                if (is_array($passenger['passport'] ?? null)) {
                    $passengers[$index]['passport']['number'] = strtoupper(str_replace(
                        ' ',
                        '',
                        (string) ($passenger['passport']['number'] ?? ''),
                    ));
                    $passengers[$index]['passport']['issuing_country'] = strtoupper(trim(
                        (string) ($passenger['passport']['issuing_country'] ?? ''),
                    ));
                }

                $seats = is_array($passenger['seats'] ?? null) ? $passenger['seats'] : [];
                if ($firstFlightId > 0 && ($passenger['seat_id'] ?? null) && empty($seats[$firstFlightId])) {
                    $seats[$firstFlightId] = $passenger['seat_id'];
                    $passengers[$index]['seats'] = $seats;
                }
            }

            $merge['passengers'] = $passengers;
        }

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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $bookable = fn ($query) => $query->whereNotIn('status', [
            FlightStatus::Departed->value,
            FlightStatus::Cancelled->value,
            FlightStatus::Closed->value,
        ]);

        return [
            'journey_type' => ['required', Rule::in(['one_way', 'connection', 'round_trip'])],
            'outbound_flight_ids' => ['required', 'array', 'min:1', 'max:3'],
            'outbound_flight_ids.*' => ['integer', Rule::exists('flights', 'id')->where($bookable)],
            'return_flight_ids' => ['nullable', 'array', 'max:3'],
            'return_flight_ids.*' => ['integer', Rule::exists('flights', 'id')->where($bookable)],
            'flight_id' => ['nullable', 'integer'],
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.first_name' => ['required', 'string', 'max:255'],
            'passengers.*.last_name' => ['required', 'string', 'max:255'],
            'passengers.*.type' => ['required', Rule::enum(PassengerType::class)],
            'passengers.*.cabin_class' => ['nullable', Rule::enum(CabinClass::class)],
            'passengers.*.date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'passengers.*.accompanying_adult' => ['nullable', 'integer', 'min:0'],
            'passengers.*.seat_id' => ['nullable', 'integer'],
            'passengers.*.seats' => ['nullable', 'array'],
            'passengers.*.seats.*' => ['nullable', 'integer'],
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
            'journey_type.required' => 'Choose one-way, connection or round trip.',
            'outbound_flight_ids.required' => 'Choose the outbound flight(s).',
            'outbound_flight_ids.min' => 'Choose the outbound flight(s).',
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

                $type = $this->input('journey_type');
                $outboundIds = array_values(array_map('intval', (array) $this->input('outbound_flight_ids', [])));
                $returnIds = array_values(array_map('intval', (array) $this->input('return_flight_ids', [])));

                if ($type === 'one_way' && count($outboundIds) !== 1) {
                    $validator->errors()->add('outbound_flight_ids', 'One-way needs exactly one outbound flight.');
                }
                if ($type === 'connection' && count($outboundIds) < 2) {
                    $validator->errors()->add('outbound_flight_ids', 'A connection needs at least two outbound flights.');
                }
                if ($type === 'round_trip' && $returnIds === []) {
                    $validator->errors()->add('return_flight_ids', 'Round trip needs at least one return flight.');
                }
                if ($type !== 'round_trip' && $returnIds !== []) {
                    $validator->errors()->add('return_flight_ids', 'Return flights are only for round trip.');
                }

                $passengers = $this->input('passengers', []);
                if (! is_array($passengers)) {
                    return;
                }

                $flightIds = array_values(array_unique([...$outboundIds, ...$returnIds]));
                $flights = Flight::query()->whereIn('id', $flightIds)->get()->keyBy('id');

                foreach ($passengers as $index => $passenger) {
                    if (! is_array($passenger)) {
                        continue;
                    }

                    $pType = $passenger['type'] ?? null;
                    $seats = is_array($passenger['seats'] ?? null) ? $passenger['seats'] : [];
                    $legacySeat = $passenger['seat_id'] ?? null;

                    if ($pType === PassengerType::Infant->value) {
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

                        foreach ([...array_values($seats), $legacySeat] as $seatId) {
                            if ($seatId !== null && $seatId !== '') {
                                $validator->errors()->add(
                                    "passengers.$index.seat_id",
                                    'An infant does not get a seat.',
                                );
                                break;
                            }
                        }

                        continue;
                    }

                    $cabin = $passenger['cabin_class'] ?? null;
                    if ($cabin === null || $cabin === '') {
                        $validator->errors()->add("passengers.$index.cabin_class", 'Choose a cabin class.');
                    }

                    foreach ($flightIds as $flightId) {
                        $seatId = $seats[$flightId] ?? $seats[(string) $flightId] ?? null;
                        if (($seatId === null || $seatId === '') && count($flightIds) === 1 && $legacySeat) {
                            $seatId = $legacySeat;
                        }

                        if ($seatId === null || $seatId === '') {
                            $validator->errors()->add(
                                count($flightIds) === 1 ? "passengers.$index.seat_id" : "passengers.$index.seats.$flightId",
                                'Choose a seat on each flight.',
                            );

                            continue;
                        }

                        $flight = $flights->get($flightId);
                        $seat = $flight
                            ? Seat::query()->where('aircraft_id', $flight->aircraft_id)->find((int) $seatId)
                            : null;

                        $errorKey = count($flightIds) === 1
                            ? "passengers.$index.seat_id"
                            : "passengers.$index.seats.$flightId";

                        if ($seat === null) {
                            $validator->errors()->add($errorKey, 'Choose a seat on this aircraft.');
                        } elseif ($cabin && $seat->cabin_class->value !== $cabin) {
                            $validator->errors()->add($errorKey, 'Choose a seat in that cabin class.');
                        } elseif (SeatAssignment::query()->where('flight_id', $flightId)->where('seat_id', (int) $seatId)->exists()) {
                            $validator->errors()->add($errorKey, 'Seat taken');
                        }
                    }
                }

                foreach ($flightIds as $flightId) {
                    $taken = [];
                    foreach ($passengers as $index => $passenger) {
                        if (! is_array($passenger) || ($passenger['type'] ?? null) === PassengerType::Infant->value) {
                            continue;
                        }
                        $seats = is_array($passenger['seats'] ?? null) ? $passenger['seats'] : [];
                        $seatId = $seats[$flightId] ?? $seats[(string) $flightId] ?? null;
                        if (($seatId === null || $seatId === '') && count($flightIds) === 1) {
                            $seatId = $passenger['seat_id'] ?? null;
                        }
                        if ($seatId === null || $seatId === '') {
                            continue;
                        }
                        $seatId = (int) $seatId;
                        if (isset($taken[$seatId])) {
                            $key = count($flightIds) === 1
                                ? "passengers.$index.seat_id"
                                : "passengers.$index.seats.$flightId";
                            $validator->errors()->add($key, 'Seat taken');
                        }
                        $taken[$seatId] = $index;
                    }
                }
            },
        ];
    }

    /**
     * @return list<int>
     */
    public function outboundFlightIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('outbound_flight_ids')));
    }

    /**
     * @return list<int>
     */
    public function returnFlightIds(): array
    {
        return array_values(array_map('intval', (array) ($this->validated('return_flight_ids') ?? [])));
    }
}
