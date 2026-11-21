<?php

namespace App\Http\Controllers\Checkin;

use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Services\CheckinFlightService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Check-in by flight (C13): today's flights open this list of reservations.
 */
class FlightCheckinController extends Controller
{
    public function show(Request $request, Flight $flight, CheckinFlightService $flights): View
    {
        $filter = (string) $request->query('q', '');
        $status = $request->query('status', 'all');
        if (! in_array($status, ['all', 'waiting', 'done'], true)) {
            $status = 'all';
        }

        $rows = $flights->reservations($flight, $filter);
        $counts = [
            'all' => $rows->count(),
            'waiting' => $rows->filter(fn (array $row) => $row['checked_in'] < $row['passengers'])->count(),
            'done' => $rows->filter(fn (array $row) => $row['passengers'] > 0 && $row['checked_in'] >= $row['passengers'])->count(),
        ];

        $filtered = match ($status) {
            'waiting' => $rows->filter(fn (array $row) => $row['checked_in'] < $row['passengers'])->values(),
            'done' => $rows->filter(fn (array $row) => $row['passengers'] > 0 && $row['checked_in'] >= $row['passengers'])->values(),
            default => $rows,
        };

        return view('checkin.flight', [
            'flight' => $flight,
            'filter' => $filter,
            'status' => $status,
            'statusCounts' => $counts,
            'rows' => $filtered,
        ]);
    }
}
