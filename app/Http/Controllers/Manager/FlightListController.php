<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\SpecialService;
use App\Support\ManagerStation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Flight lists (SR-2, SR-45): passengers, baggage and special services of one flight.
 * Read only. The flight and the tab come from GET parameters, so a list can be bookmarked and printed.
 */
class FlightListController extends Controller
{
    /**
     * The tabs, in the order they are shown. The first one is the default.
     */
    private const TABS = ['passengers', 'baggage', 'services'];

    public function index(Request $request): View
    {
        // find() gives null for a missing or unknown id (and for "abc", which integer() turns into 0):
        // the page then shows only the picker, it does not fail.
        $station = ManagerStation::apply($request);
        $flight = Flight::find($request->integer('flight'));
        // A bookmarked flight from another station is not this station's list.
        if ($flight !== null && $station !== null && $flight->origin !== $station) {
            $flight = null;
        }

        // An unknown tab falls back to the first one.
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : self::TABS[0];

        $data = [
            'flights' => Flight::query()
                ->when($station, fn ($query) => $query->departingFrom($station))
                ->orderByDesc('departure_at')
                ->get(),
            'flight' => $flight,
            'tab' => $tab,
            'tabs' => self::TABS,
            'station' => $station,
        ];

        if ($flight === null) {
            return view('manager.flight-lists', $data);
        }

        // Only the list of the open tab is loaded.
        return view('manager.flight-lists', $data + match ($tab) {
            'passengers' => ['passengers' => $this->passengers($flight)],
            'baggage' => $this->baggage($flight),
            'services' => ['services' => $this->services($flight)],
        });
    }

    /**
     * Every passenger of the flight, whatever the status, by last name then first name.
     * Eager loading: one query per relation for the whole list, not one per row.
     * checkedInBy / boardedBy are the "who did it" helpers (SR-45); ->user is the staff member.
     */
    private function passengers(Flight $flight)
    {
        // Through the flight's coupons (B13b): connecting passengers too, each with this flight's
        // status, seat and "who did it".
        return $flight->passengers()
            ->with([
                'reservation',
                'coupon' => fn ($query) => $query->where('coupons.flight_id', $flight->id),
                'coupon.checkedInBy.user',
                'coupon.boardedBy.user',
                'seatAssignment' => fn ($query) => $query->where('seat_assignments.flight_id', $flight->id),
                'seatAssignment.seat',
            ])
            ->orderBy('passengers.last_name')
            ->orderBy('passengers.first_name')
            ->get()
            ->each(function (Passenger $passenger) {
                $passenger->setRelation('checkedInBy', $passenger->coupon?->checkedInBy);
                $passenger->setRelation('boardedBy', $passenger->coupon?->boardedBy);
            });
    }

    /**
     * Every bag of the flight, with the totals line: number of bags and total weight in kg.
     * Free bags (wheelchair, stroller) and offloaded bags are counted too: it is a record of what was checked in.
     *
     * @return array{bags: Collection<int, Bag>, bagCount: int, totalWeight: string}
     */
    private function baggage(Flight $flight): array
    {
        // A bag is on every flight of its direction (B13b, SR-67).
        $bags = Bag::whereExists(fn ($coupons) => $coupons
            ->from('coupons')
            ->join('reservation_segments', 'reservation_segments.id', '=', 'coupons.reservation_segment_id')
            ->whereColumn('coupons.passenger_id', 'bags.passenger_id')
            ->whereColumn('reservation_segments.direction', 'bags.direction')
            ->where('coupons.flight_id', $flight->id))
            ->with('passenger')
            ->orderBy('tag_no')
            ->get();

        return [
            'bags' => $bags,
            'bagCount' => $bags->count(),
            'totalWeight' => number_format((float) $bags->sum('weight_kg'), 1, '.', ''),
        ];
    }

    /**
     * Every special service of the flight's passengers, by passenger name.
     */
    private function services(Flight $flight)
    {
        return SpecialService::whereHas('passenger.coupons', fn ($coupon) => $coupon->where('coupons.flight_id', $flight->id))
            ->with('passenger')
            ->get()
            ->sortBy(fn (SpecialService $service) => $service->passenger->last_name.' '.$service->passenger->first_name)
            ->values();
    }
}
