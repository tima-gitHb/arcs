<?php

namespace App\Http\Controllers\Reservation;

use App\Enums\CabinClass;
use App\Enums\FeeCode;
use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Exceptions\FareNotFound;
use App\Exceptions\InvalidJourney;
use App\Exceptions\InvalidStatusChange;
use App\Exceptions\PaymentDeclined;
use App\Exceptions\SeatNotAllowed;
use App\Exceptions\SeatTaken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\StoreReservationRequest;
use App\Http\Requests\Reservation\UpdatePassengerRequest;
use App\Mail\ETicketMail;
use App\Models\Airport;
use App\Models\BaggageAllowance;
use App\Models\Fare;
use App\Models\Fee;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TripFare;
use App\Services\CorrectionService;
use App\Services\FareService;
use App\Services\Payments\CardDetails;
use App\Services\PnrGenerator;
use App\Services\ReservationService;
use App\Services\SeatService;
use App\Services\StatusService;
use App\Support\FlightStatusGate;
use App\Support\Journey;
use App\Support\Money;
use Database\Seeders\FareSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function create(): View
    {
        // Bookable statuses only (#41 / #7): Scheduled (future), Check-in open, Delayed — not Boarding.
        $bookable = array_map(fn ($s) => $s->value, FlightStatusGate::bookableStatuses());
        $flights = Flight::query()
            ->whereIn('status', $bookable)
            ->where(function ($query) {
                $query->whereIn('status', [
                    FlightStatus::CheckinOpen->value,
                    FlightStatus::Delayed->value,
                ])->orWhere(function ($scheduled) {
                    $scheduled->where('status', FlightStatus::Scheduled->value)
                        ->where('departure_at', '>=', now());
                });
            })
            ->orderBy('departure_at')
            ->orderBy('flight_no')
            ->get();

        $flightGroups = collect([
            FlightStatus::CheckinOpen,
            FlightStatus::Delayed,
            FlightStatus::Scheduled,
        ])->mapWithKeys(fn (FlightStatus $status) => [
            $status->value => $flights->where('status', $status)->values(),
        ])->filter(fn ($group) => $group->isNotEmpty());

        $passengers = old('passengers');

        if (! is_array($passengers) || $passengers === []) {
            $passengers = [[
                'first_name' => '',
                'last_name' => '',
                'type' => PassengerType::Adult->value,
                'cabin_class' => CabinClass::Economy->value,
                'date_of_birth' => '',
                'accompanying_adult' => '',
                'extra_bags' => 0,
                'extra_kg' => 0,
                'passport' => [
                    'number' => '',
                    'issuing_country' => null,
                    'expiry_date' => '',
                ],
            ]];
        }

        $this->ensurePublishedPrices();

        $allowances = BaggageAllowance::query()->get()->mapWithKeys(
            fn (BaggageAllowance $row) => [
                $row->cabin_class->value => [
                    'label' => $row->cabin_class->label(),
                    'pieces' => $row->pieces,
                    'kg' => (float) $row->max_kg_per_bag,
                ],
            ],
        );

        $fares = Fare::query()->get()->map(
            fn (Fare $fare) => [
                'cabin' => $fare->cabin_class->value,
                'type' => $fare->passenger_type->value,
                'cents' => $fare->amount_cents,
                'origin' => $fare->origin_airport_code,
                'destination' => $fare->destination_airport_code,
            ],
        )->values();

        $tripFares = TripFare::query()->get()->map(
            fn (TripFare $fare) => [
                'cabin' => $fare->cabin_class->value,
                'type' => $fare->passenger_type->value,
                'origin' => $fare->origin_code,
                'destination' => $fare->destination_code,
                'one_way_cents' => $fare->one_way_cents,
                'round_trip_cents' => $fare->round_trip_cents,
            ],
        )->values();

        $defaultFares = $fares->filter(
            fn (array $fare) => $fare['origin'] === null && $fare['destination'] === null,
        )->values();

        $fees = [
            'extra_bag' => (int) Fee::query()->where('code', FeeCode::ExtraBag)->value('amount_cents'),
            'extra_kg' => (int) Fee::query()->where('code', FeeCode::ExcessKg)->value('amount_cents'),
        ];

        $cabinOptions = collect(CabinClass::cases())->map(function (CabinClass $cabin) use ($allowances, $defaultFares) {
            $adultFare = $defaultFares->first(
                fn (array $fare) => $fare['cabin'] === $cabin->value && $fare['type'] === PassengerType::Adult->value,
            );
            $allowance = $allowances[$cabin->value] ?? null;

            return [
                'value' => $cabin->value,
                'label' => $cabin->label(),
                'fare_cents' => $adultFare['cents'] ?? null,
                'fare_label' => isset($adultFare['cents']) ? Money::format($adultFare['cents']) : null,
                'allowance' => $allowance,
            ];
        });

        $flightPayload = $flights->map(fn (Flight $flight) => [
            'id' => $flight->id,
            'flight_no' => $flight->flight_no,
            'origin' => $flight->origin,
            'destination' => $flight->destination,
            'status' => $flight->status->value,
            'status_label' => $flight->status->label(),
            'departure_at' => $flight->departure_at?->toIso8601String(),
            'arrives_at' => $flight->arrives_at?->toIso8601String(),
            'label' => $flight->flight_no.' · '.$flight->origin.' → '.$flight->destination.' · '.
                $flight->departureLocal()->format('D d M Y H:i').' · '.$flight->status->label(),
        ])->values();

        $connectionMinutes = Airport::query()->pluck('min_connection_minutes', 'code')->all();

        return view('reservation.create', [
            'flights' => $flights,
            'flightGroups' => $flightGroups,
            'flightPayload' => $flightPayload,
            'connectionMinutes' => $connectionMinutes,
            'passengers' => $passengers,
            'allowances' => $allowances,
            'fares' => $fares,
            'tripFares' => $tripFares,
            'fees' => $fees,
            'cabinOptions' => $cabinOptions,
            'pricesReady' => $defaultFares->isNotEmpty() && ($fees['extra_bag'] > 0),
            'journeyType' => old('journey_type', 'one_way'),
            'outboundFlightIds' => array_map('intval', (array) old('outbound_flight_ids', old('flight_id') ? [old('flight_id')] : [])),
            'returnFlightIds' => array_map('intval', (array) old('return_flight_ids', [])),
            'multiCityLegs' => old('legs', [[null], [null]]),
        ]);
    }

    /**
     * Demo / local installs sometimes skip seeders. Without fares the booking
     * cannot charge — publish the default table so staff never see a blank price list.
     */
    private function ensurePublishedPrices(): void
    {
        $needsFares = Fare::query()->defaults()
            ->where('cabin_class', CabinClass::Economy)
            ->where('passenger_type', PassengerType::Adult)
            ->doesntExist();

        if ($needsFares || Fee::query()->where('code', FeeCode::ExtraBag)->doesntExist()) {
            app(FareSeeder::class)->run();
        }

        if (BaggageAllowance::query()->count() < count(CabinClass::cases())) {
            app(SettingSeeder::class)->run();
        }
    }

    public function store(StoreReservationRequest $request, ReservationService $reservations): RedirectResponse
    {
        $this->ensurePublishedPrices();

        $safeInput = fn () => $request->except(['card_number', 'card_cvc', 'card_expiry']);

        try {
            $token = (string) $request->validated('booking_token');
            $cacheKey = 'booking_token:'.$token;
            if (! Cache::add($cacheKey, 'pending', now()->addHour())) {
                $existingId = Cache::get($cacheKey.':reservation');
                if (is_numeric($existingId)) {
                    return redirect()->route('reservation.confirmation', $existingId)
                        ->with('success', 'Reservation already created.');
                }

                return back()->withInput($safeInput())->withErrors([
                    'booking_token' => 'This booking was already submitted. Wait a moment or reload the page.',
                ]);
            }

            $journey = $this->journeyFromRequest($request);

            $reservation = $reservations->create(
                $journey,
                $request->user(),
                $request->validated('passengers'),
                [
                    'contact_email' => $request->validated('contact_email'),
                    'contact_phone' => $request->validated('contact_phone'),
                ],
                new CardDetails(
                    $request->validated('card_number'),
                    $request->validated('card_expiry'),
                    $request->validated('card_cvc'),
                ),
            );

            Cache::put($cacheKey.':reservation', $reservation->id, now()->addHour());
        } catch (InvalidJourney $exception) {
            $field = $request->input('journey_type') === 'multi_city' ? 'legs' : 'outbound_flight_ids';

            return back()->withInput($safeInput())->withErrors([
                $field => $exception->getMessage(),
            ]);
        } catch (SeatTaken|SeatNotAllowed $exception) {
            return back()->withInput($safeInput())->withErrors([
                'seat' => $exception->getMessage(),
            ]);
        } catch (PaymentDeclined $exception) {
            $message = $exception->getMessage();
            $field = match (true) {
                str_contains(strtolower($message), 'expiry') => 'card_expiry',
                str_contains(strtolower($message), 'cvc') => 'card_cvc',
                default => 'card_number',
            };

            return back()->withInput($safeInput())->withErrors([
                $field => $message,
            ]);
        } catch (FareNotFound $exception) {
            $field = $request->input('journey_type') === 'multi_city' ? 'legs' : 'outbound_flight_ids';

            return back()->withInput($safeInput())->withErrors([
                $field => $exception->getMessage(),
            ]);
        }

        $reservation->load(['flight', 'segments.flight', 'passengers.seatAssignment.seat', 'payments']);
        $payment = $reservation->farePayment();

        $mailNote = '';

        if ($reservation->contact_email && $payment !== null) {
            try {
                Mail::to($reservation->contact_email)->send(new ETicketMail($reservation, $payment));
            } catch (\Throwable) {
                // PNR is already paid — do not fail the desk flow if mail cannot render/send.
                $mailNote = ' E-ticket email could not be sent — print the receipt instead.';
            }
        }

        return redirect()
            ->route('reservation.confirmation', $reservation)
            ->with('success', 'PNR '.$reservation->pnr.' saved and paid.'.$mailNote);
    }

    public function seats(Flight $flight, SeatService $seatService): JsonResponse
    {
        if (in_array($flight->status, [FlightStatus::Departed, FlightStatus::Cancelled], true)) {
            abort(404);
        }

        // Shared with the check-in seat map (C3).
        return response()->json(['seats' => $seatService->seatMap($flight)]);
    }

    public function confirmation(Reservation $reservation, FareService $fares): View
    {
        $reservation->load([
            'flight',
            'segments.flight',
            'passengers.accompanyingAdult',
            'passengers.seatAssignment.seat',
            'passengers.coupons.flight',
            'passengers.coupons.seatAssignment.seat',
            'payments',
        ]);

        return view('reservation.confirmation', [
            'reservation' => $reservation,
            'payment' => $reservation->farePayment(),
            'journeyQuote' => $fares->journeyQuote($reservation),
        ]);
    }

    public function search(Request $request): View
    {
        $pnr = strtoupper(trim((string) $request->query('pnr', '')));
        $reservation = null;
        $error = null;

        if ($pnr !== '') {
            if (! preg_match('/^[A-HJ-NP-Z2-9]{'.PnrGenerator::LENGTH.'}$/', $pnr)) {
                $error = 'Enter a 6-character PNR.';
            } else {
                $reservation = Reservation::query()
                    ->with(['flight', 'passengers.accompanyingAdult', 'passengers.seatAssignment.seat'])
                    ->where('pnr', $pnr)
                    ->first();

                if ($reservation === null) {
                    $error = 'No reservation with that PNR.';
                }
            }
        }

        $flights = Flight::query()
            ->withCount('reservations')
            ->orderBy('departure_at')
            ->orderBy('flight_no')
            ->get();

        $selectedFlight = null;

        if ($request->filled('flight')) {
            $selectedFlight = $flights->firstWhere('id', $request->integer('flight'));

            $selectedFlight?->load([
                'reservations' => fn ($query) => $query->orderBy('id'),
                'reservations.passengers.accompanyingAdult',
                'reservations.passengers.seatAssignment.seat',
            ]);
        }

        return view('reservation.search', [
            'pnr' => $pnr,
            'reservation' => $reservation,
            'error' => $error,
            'flights' => $flights,
            'selectedFlight' => $selectedFlight,
        ]);
    }

    public function edit(Passenger $passenger): View
    {
        $passenger->load([
            'reservation.flight',
            'auditEntries' => fn ($query) => $query->orderByDesc('changed_at')->orderByDesc('id'),
            'auditEntries.user',
        ]);

        return view('reservation.edit', [
            'passenger' => $passenger,
        ]);
    }

    public function update(UpdatePassengerRequest $request, Passenger $passenger, CorrectionService $corrections): RedirectResponse
    {
        $corrections->correctPassenger(
            $passenger,
            $request->safe()->only(['first_name', 'last_name']),
            $request->user(),
        );

        $passenger->refresh();

        return redirect()
            ->route('reservation.edit', $passenger)
            ->with('success', $passenger->first_name.' '.$passenger->last_name.' updated.');
    }

    public function cancel(Request $request, Passenger $passenger, StatusService $statuses): RedirectResponse
    {
        try {
            $statuses->cancel($passenger, $request->user());
        } catch (InvalidStatusChange $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            $passenger->first_name.' '.$passenger->last_name.' cancelled.',
        );
    }

    private function journeyFromRequest(StoreReservationRequest $request): Journey
    {
        if ($request->input('journey_type') === 'multi_city') {
            $legIds = $request->cityLegs();
            $allIds = [];
            foreach ($legIds as $leg) {
                foreach ($leg as $id) {
                    $allIds[] = $id;
                }
            }
            $flights = Flight::query()->whereIn('id', $allIds)->get()->keyBy('id');
            $cityLegs = [];
            foreach ($legIds as $leg) {
                $cityLegs[] = array_map(fn (int $id) => $flights->get($id), $leg);
            }

            return Journey::multiCity($cityLegs);
        }

        $outboundIds = $request->outboundFlightIds();
        $returnIds = $request->returnFlightIds();
        $outbound = Flight::query()->whereIn('id', $outboundIds)->get()
            ->sortBy(fn (Flight $flight) => array_search($flight->id, $outboundIds, true))
            ->values()
            ->all();
        $return = Flight::query()->whereIn('id', $returnIds)->get()
            ->sortBy(fn (Flight $flight) => array_search($flight->id, $returnIds, true))
            ->values()
            ->all();

        return new Journey($outbound, $return);
    }
}
