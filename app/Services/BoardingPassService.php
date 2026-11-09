<?php

namespace App\Services;

use App\Enums\PassengerStatus;
use App\Exceptions\BoardingPassNotAllowed;
use App\Exceptions\EntryReasonRequired;
use App\Exceptions\ExcessBaggageUnpaid;
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
 * Completing check-in and boarding passes (C5 / C10: SR-8, SR-21, SR-32, SR-42, SR-59).
 * Status still changes only in StatusService.
 */
class BoardingPassService
{
    /**
     * Boarding starts this many minutes before departure (shown on the pass).
     */
    public const BOARDING_MINUTES_BEFORE_DEPARTURE = 40;

    public function __construct(
        private StatusService $statuses,
        private EntryRequirementChecker $entryCheck,
        private BagService $bags,
    ) {}

    /**
     * "Confirm check-in": StatusService::checkIn() checks every rule, then the first pass
     * is issued. One transaction: no check-in without a pass, no pass without a check-in.
     */
    public function completeCheckIn(Passenger $passenger, User $by, ?string $entryReason = null): BoardingPass
    {
        return DB::transaction(function () use ($passenger, $by, $entryReason) {
            $this->statuses->checkIn($passenger, $by);
            $this->assertExcessPaid($passenger);
            $this->acknowledgeEntryCheck($passenger, $by, $entryReason);

            return $this->issue($passenger, $by);
        });
    }

    /**
     * Same for a late check-in at the gate, approved by a Manager (SR-29).
     */
    public function completeLateCheckIn(Passenger $passenger, User $gateUser, User $manager, string $reason, ?string $entryReason = null): BoardingPass
    {
        return DB::transaction(function () use ($passenger, $gateUser, $manager, $reason, $entryReason) {
            $this->statuses->lateCheckIn($passenger, $gateUser, $manager, $reason);
            $this->assertExcessPaid($passenger);
            $this->acknowledgeEntryCheck($passenger, $gateUser, $entryReason);

            return $this->issue($passenger, $gateUser);
        });
    }

    /**
     * Pooled excess must be fully paid before the pass is issued (SR-59 / SR-60).
     * Due is quote − succeeded ExcessBaggage payments (C11). Missing fees still block.
     * Runs after status rules.
     */
    private function assertExcessPaid(Passenger $passenger): void
    {
        $passenger = $passenger->fresh();
        $excess = $this->bags->excess($passenger);

        if (! $excess->hasExcess()) {
            return;
        }

        if (! $excess->priceIsSet() || $this->bags->excessDueCents($passenger) > 0) {
            throw new ExcessBaggageUnpaid;
        }
    }

    /**
     * The entry check is advisory (SR-49): it never refuses a check-in, but on a missing or
     * refused result the agent must write a reason, kept in audit_log (field entry_override).
     * Runs after the status rules, so their refusals keep their order and messages; the
     * exception rolls the check-in back.
     */
    private function acknowledgeEntryCheck(Passenger $passenger, User $by, ?string $reason): void
    {
        $result = $this->entryCheck->check($passenger->fresh());

        if (! $result->needsAcknowledgement()) {
            return;
        }

        if (trim((string) $reason) === '') {
            throw new EntryReasonRequired;
        }

        AuditEntry::create([
            'passenger_id' => $passenger->id,
            'field' => 'entry_override',
            'old_value' => $result->message,
            'new_value' => trim($reason),
            'user_id' => $by->id,
            'changed_at' => now(),
        ]);
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

    /**
     * Everything the printable pass page needs (checkin/boarding-pass.blade.php).
     * Shared by the check-in and gate routes, so both show the same pass.
     *
     * @return array<string, mixed>
     */
    public function printData(Passenger $passenger, BoardingPass $pass): array
    {
        $passenger->load(['reservation.flight', 'seatAssignment.seat', 'accompanyingAdult']);
        $flight = $passenger->reservation->flight;

        return [
            'passenger' => $passenger,
            'pass' => $pass,
            'flight' => $flight,
            'boardingTime' => $this->boardingTime($flight),
            'barcodeSvg' => $this->barcodeSvg($pass),
        ];
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
