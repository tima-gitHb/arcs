<?php

namespace App\Services;

use App\Enums\PassengerStatus;
use App\Enums\SegmentDirection;
use App\Exceptions\BoardingPassNotAllowed;
use App\Exceptions\EntryReasonRequired;
use App\Exceptions\ExcessBaggageUnpaid;
use App\Mail\CheckInCompleteMail;
use App\Models\AuditEntry;
use App\Models\BoardingPass;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;
use Throwable;

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
        private JourneyService $journeys,
    ) {}

    /**
     * "Confirm check-in": StatusService::checkIn() checks every rule, then a pass is issued for every
     * flight of the leg (SR-66 / C23). One transaction: no check-in without passes, no pass without a
     * check-in. Returns the first flight's pass. Direction/leg: null = the leg at the agent's station.
     */
    public function completeCheckIn(Passenger $passenger, User $by, ?string $entryReason = null, ?SegmentDirection $direction = null, ?int $leg = null): BoardingPass
    {
        $leg ??= $this->journeys->legFor($passenger, $by, $direction);
        $direction ??= $this->journeys->segmentsOfLeg($passenger->reservation, $leg)->first()?->direction
            ?? $this->journeys->directionFor($passenger, $by);

        $pass = DB::transaction(function () use ($passenger, $by, $entryReason, $direction, $leg) {
            $this->statuses->checkIn($passenger, $by, $direction, $leg);
            $this->assertExcessPaid($passenger, $direction, $leg);
            $this->acknowledgeEntryCheck($passenger, $by, $entryReason, $direction, $leg);

            return $this->issueLeg($passenger, $by, $leg);
        });

        // After a successful check-in only (not reprints). Failure must not undo the check-in (SR-79).
        $this->sendCheckInEmail($passenger->fresh(), $direction, $by, $leg);

        return $pass;
    }

    /**
     * Email boarding-pass and bag-tag PDFs to the passenger (or reservation contact).
     * No address → skip. Send failure → log + audit_log, check-in already committed.
     */
    private function sendCheckInEmail(Passenger $passenger, SegmentDirection $direction, User $by, ?int $leg = null): void
    {
        $passenger->loadMissing('reservation');
        // No passengers.email column yet: reservation contact only. Ready for a future passenger email.
        $email = trim((string) ($passenger->email ?? $passenger->reservation->contact_email ?? ''));

        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new CheckInCompleteMail($passenger, $direction, $leg));
        } catch (Throwable $e) {
            Log::error('Check-in email could not be sent.', [
                'passenger_id' => $passenger->id,
                'pnr' => $passenger->reservation->pnr,
                'exception' => $e->getMessage(),
            ]);

            AuditEntry::create([
                'passenger_id' => $passenger->id,
                'field' => 'checkin_email_failed',
                'old_value' => null,
                'new_value' => mb_substr($e->getMessage(), 0, 255),
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);
        }
    }

    /**
     * Same for a late check-in at the gate, approved by a Manager (SR-29).
     */
    public function completeLateCheckIn(Passenger $passenger, User $gateUser, User $manager, string $reason, ?string $entryReason = null, ?SegmentDirection $direction = null, ?int $leg = null): BoardingPass
    {
        return DB::transaction(function () use ($passenger, $gateUser, $manager, $reason, $entryReason, $direction, $leg) {
            $leg ??= $this->journeys->legFor($passenger, $gateUser, $direction);
            $direction ??= $this->journeys->segmentsOfLeg($passenger->reservation, $leg)->first()?->direction
                ?? $this->journeys->directionFor($passenger, $gateUser);

            $this->statuses->lateCheckIn($passenger, $gateUser, $manager, $reason, $direction, $leg);
            $this->assertExcessPaid($passenger, $direction, $leg);
            $this->acknowledgeEntryCheck($passenger, $gateUser, $entryReason, $direction, $leg);

            return $this->issueLeg($passenger, $gateUser, $leg);
        });
    }

    /**
     * The active passes of one direction, one per flight, in journey order.
     *
     * @return Collection<int, BoardingPass>
     */
    public function passesFor(Passenger $passenger, SegmentDirection $direction, ?int $leg = null): Collection
    {
        $couponIds = $leg !== null
            ? $this->journeys->couponsOfLeg($passenger, $leg)->modelKeys()
            : $this->journeys->couponsOf($passenger, $direction)->modelKeys();

        return BoardingPass::query()
            ->whereIn('coupon_id', $couponIds)
            ->whereNull('voided_at')
            ->orderBy('coupon_id')
            ->get();
    }

    /**
     * A pass for every coupon of the leg; returns the first flight's.
     */
    private function issueLeg(Passenger $passenger, User $by, int $leg): BoardingPass
    {
        return $this->journeys->couponsOfLeg($passenger, $leg)
            ->map(fn ($coupon) => $this->issue($passenger, $by, $coupon->flight))
            ->first();
    }

    /**
     * Pooled excess must be fully paid before the pass is issued (SR-59 / SR-60).
     * Due is quote − succeeded ExcessBaggage payments (C11). Missing fees still block.
     * Runs after status rules.
     */
    private function assertExcessPaid(Passenger $passenger, SegmentDirection $direction, ?int $leg = null): void
    {
        $passenger = $passenger->fresh();
        $excess = $this->bags->excess($passenger, $direction, $leg);

        if (! $excess->hasExcess()) {
            return;
        }

        if (! $excess->priceIsSet() || $this->bags->excessDueCents($passenger, $direction, $leg) > 0) {
            throw new ExcessBaggageUnpaid;
        }
    }

    /**
     * The entry check is advisory (SR-49): it never refuses a check-in, but on a missing or
     * refused result the agent must write a reason, kept in audit_log (field entry_override).
     * Runs after the status rules, so their refusals keep their order and messages; the
     * exception rolls the check-in back.
     */
    private function acknowledgeEntryCheck(Passenger $passenger, User $by, ?string $reason, SegmentDirection $direction, ?int $leg = null): void
    {
        $result = $this->entryCheck->check($passenger->fresh(), $direction, $leg);

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
    public function issue(Passenger $passenger, User $by, ?Flight $flight = null): BoardingPass
    {
        return DB::transaction(function () use ($passenger, $by, $flight) {
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($passenger->id);
            // One pass per flight (B13b): the coupon of $flight, else the reservation's first flight.
            $coupon = $passenger->coupons()->where('coupons.flight_id', $flight?->id ?? $passenger->reservation->flight_id)->first();

            if ($coupon?->status !== PassengerStatus::CheckedIn) {
                throw new BoardingPassNotAllowed('A boarding pass is only issued to a checked-in passenger.');
            }

            $coupon->boardingPasses()->whereNull('voided_at')->update(['voided_at' => now()]);

            // PNR first (SR-21), then passenger and pass number, so every pass is unique.
            $number = $passenger->boardingPasses()->count() + 1;
            $pass = $passenger->boardingPasses()->create([
                'coupon_id' => $coupon->id,
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
        // The pass's own flight and seat (one pass per flight since B13b).
        $passenger->load([
            'reservation.flight',
            'accompanyingAdult',
            'seatAssignment' => fn ($query) => $query->where('coupon_id', $pass->coupon_id),
            'seatAssignment.seat',
        ]);
        $flight = $pass->coupon?->flight ?? $passenger->reservation->flight;

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
