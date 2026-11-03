<?php

namespace App\Services;

use App\Enums\PassengerStatus;
use App\Exceptions\BoardingPassNotAllowed;
use App\Models\AuditEntry;
use App\Models\BoardingPass;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * Completing check-in and boarding passes (C5: SR-8, SR-21, SR-32, SR-42).
 * Status still changes only in StatusService.
 */
class BoardingPassService
{
    /**
     * Boarding starts this many minutes before departure (shown on the pass).
     */
    public const BOARDING_MINUTES_BEFORE_DEPARTURE = 40;

    public function __construct(private StatusService $statuses) {}

    /**
     * "Confirm check-in": StatusService::checkIn() checks every rule, then the first pass
     * is issued. One transaction: no check-in without a pass, no pass without a check-in.
     */
    public function completeCheckIn(Passenger $passenger, User $by): BoardingPass
    {
        return DB::transaction(function () use ($passenger, $by) {
            $this->statuses->checkIn($passenger, $by);

            return $this->issue($passenger, $by);
        });
    }

    /**
     * Issue a pass; any active pass is voided first, so a passenger has one active pass (SR-32).
     * Used for the first pass and for reprints.
     */
    public function issue(Passenger $passenger, User $by): BoardingPass
    {
        return DB::transaction(function () use ($passenger, $by) {
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($passenger->id);

            if ($passenger->status !== PassengerStatus::CheckedIn) {
                throw new BoardingPassNotAllowed('A boarding pass is only issued to a checked-in passenger.');
            }

            $passenger->boardingPasses()->whereNull('voided_at')->update(['voided_at' => now()]);

            // PNR first (SR-21), then passenger and pass number, so every pass is unique.
            $number = $passenger->boardingPasses()->count() + 1;
            $pass = $passenger->boardingPasses()->create([
                'barcode' => $passenger->reservation->pnr.'-'.$passenger->id.'-'.$number,
                'issued_at' => now(),
            ]);

            AuditEntry::create([
                'passenger_id' => $passenger->id,
                'field' => 'boarding_pass_issued',
                'old_value' => null,
                'new_value' => $pass->barcode,
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);

            return $pass;
        });
    }

    public function boardingTime(Flight $flight): Carbon
    {
        return $flight->departure_at->copy()->subMinutes(self::BOARDING_MINUTES_BEFORE_DEPARTURE);
    }

    /**
     * The pass's barcode as an SVG image: Code 128 of the barcode value (PNR-passenger-number).
     */
    public function barcodeSvg(BoardingPass $pass): string
    {
        $barcode = (new TypeCode128)->getBarcode($pass->barcode);

        return (new SvgRenderer)->render($barcode, 320, 60);
    }
}
