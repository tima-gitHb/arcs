<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\SpecialService;
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
        $flight = Flight::find($request->integer('flight'));

        // An unknown tab falls back to the first one.
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : self::TABS[0];

        $data = [
            'flights' => Flight::orderByDesc('departure_at')->get(),
            'flight' => $flight,
            'tab' => $tab,
            'tabs' => self::TABS,
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
        return $flight->passengers()
            ->with(['reservation', 'seatAssignment.seat', 'checkedInBy.user', 'boardedBy.user'])
            ->orderBy('passengers.last_name')
            ->orderBy('passengers.first_name')
            ->get();
    }

    /**
     * Every bag of the flight, with the totals line: number of bags and total weight in kg.
     * Free bags (wheelchair, stroller) and offloaded bags are counted too: it is a record of what was checked in.
     *
     * @return array{bags: Collection<int, Bag>, bagCount: int, totalWeight: string}
     */
    private function baggage(Flight $flight): array
    {
        $bags = Bag::whereHas('passenger.reservation', fn ($reservation) => $reservation->where('flight_id', $flight->id))
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
        return SpecialService::whereHas('passenger.reservation', fn ($reservation) => $reservation->where('flight_id', $flight->id))
            ->with('passenger')
            ->get()
            ->sortBy(fn (SpecialService $service) => $service->passenger->last_name.' '.$service->passenger->first_name)
            ->values();
    }
}
