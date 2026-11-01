<?php

namespace App\Http\Controllers\Reservation;

use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Exceptions\SeatTaken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\StoreReservationRequest;
use App\Models\Flight;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Services\PnrGenerator;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function create(): View
    {
        $flights = Flight::query()
            ->whereNotIn('status', [
                FlightStatus::Departed->value,
                FlightStatus::Cancelled->value,
            ])
            ->orderBy('departure_at')
            ->orderBy('flight_no')
            ->get();

        $flightGroups = collect([
            FlightStatus::CheckinOpen,
            FlightStatus::Boarding,
            FlightStatus::Closed,
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
                'date_of_birth' => '',
                'accompanying_adult' => '',
            ]];
        }

        return view('reservation.create', [
            'flights' => $flights,
            'flightGroups' => $flightGroups,
            'passengers' => $passengers,
        ]);
    }

    public function store(StoreReservationRequest $request, ReservationService $reservations): RedirectResponse
    {
        try {
            $reservation = $reservations->create(
                Flight::query()->findOrFail($request->integer('flight_id')),
                $request->user(),
                $request->validated('passengers'),
            );
        } catch (SeatTaken) {
            return back()->withInput()->withErrors([
                'seat' => 'Seat taken',
            ]);
        }

        return redirect()
            ->route('reservation.confirmation', $reservation)
            ->with('success', 'Reservation '.$reservation->pnr.' created.');
    }

    public function seats(Flight $flight): JsonResponse
    {
        if (in_array($flight->status, [FlightStatus::Departed, FlightStatus::Cancelled], true)) {
            abort(404);
        }

        $taken = array_flip(
            SeatAssignment::query()->where('flight_id', $flight->id)->pluck('seat_id')->all(),
        );

        $seats = Seat::query()
            ->where('aircraft_id', $flight->aircraft_id)
            ->get()
            ->sortBy(function (Seat $seat): int {
                if (! preg_match('/^(\d+)([A-F])$/', $seat->seat_no, $matches)) {
                    return 9999;
                }

                return ((int) $matches[1]) * 10 + ord($matches[2]);
            })
            ->values()
            ->map(function (Seat $seat) use ($taken): array {
                preg_match('/^(\d+)([A-F])$/', $seat->seat_no, $matches);

                return [
                    'id' => $seat->id,
                    'seat_no' => $seat->seat_no,
                    'row' => isset($matches[1]) ? (int) $matches[1] : 0,
                    'letter' => $matches[2] ?? '',
                    'cabin' => $seat->cabin_class->value,
                    'taken' => isset($taken[$seat->id]),
                ];
            });

        return response()->json(['seats' => $seats]);
    }

    public function confirmation(Reservation $reservation): View
    {
        $reservation->load(['flight', 'passengers.accompanyingAdult', 'passengers.seatAssignment.seat']);

        return view('reservation.confirmation', [
            'reservation' => $reservation,
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
}
