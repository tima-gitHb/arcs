<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Enums\SegmentDirection;
use App\Exceptions\InvalidStatusChange;
use App\Models\AuditEntry;
use App\Models\Bag;
use App\Models\Coupon;
use App\Models\Flight;
use App\Models\GateOverride;
use App\Models\Passenger;
use App\Models\SeatAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Support\FlightAction;
use App\Support\FlightStatusGate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY code allowed to change a passenger's or a flight's status (see CLAUDE.md).
 * Since B13b a passenger's status lives on their coupons, one per flight of the journey:
 * check-in covers every coupon of a direction, boarding one flight's coupon (SR-66).
 * Every change runs in a transaction and writes an audit_log row per coupon (SR-26).
 */
class StatusService
{
    /**
     * Flight status moves allowed through setFlightStatus() (SR-25).
     * Boarding -> Closed is missing on purpose: use closeBoarding().
     */
    private const FLIGHT_MOVES = [
        'Scheduled' => ['CheckinOpen', 'Delayed', 'Cancelled'],
        'CheckinOpen' => ['Boarding', 'Delayed', 'Cancelled'],
        'Boarding' => ['Delayed'],
        'Delayed' => ['CheckinOpen', 'Boarding', 'Cancelled'],
        'Closed' => ['Departed'],
    ];

    public function __construct(private JourneyService $journeys) {}

    /**
     * Booked -> CheckedIn for every coupon of one leg, all or none (SR-6, SR-23, SR-25, SR-30,
     * SR-31, SR-66, C23/CR-16). A connection inside the leg still checks in together. Multi-city
     * and overnight splits check in one leg at a time. Window and CheckinOpen on the leg's first
     * flight; documents and seat on every flight of the leg. Direction/leg: null = leg departing
     * from the agent's station (JourneyService::legFor).
     */
    public function checkIn(Passenger $passenger, User $by, ?SegmentDirection $direction = null, ?int $leg = null): void
    {
        DB::transaction(function () use ($passenger, $by, $direction, $leg) {
            $this->lock($passenger);
            $leg ??= $this->journeys->legFor($passenger, $by, $direction);
            $coupons = $this->journeys->couponsOfLeg($passenger, $leg);
            $this->ensureBooked($coupons);
            $this->assertEarlierLegsComplete($passenger, $leg);

            $flight = $coupons->first()->flight;

            FlightStatusGate::assertAllows($flight, FlightAction::CheckIn);
            if (! $flight->isCheckinWindowOpen()) {
                throw new InvalidStatusChange('Check-in closed');
            }

            $this->finishCheckIn($passenger, $coupons, $by);
        });
    }

    /**
     * Booked -> CheckedIn at the gate after check-in has closed, approved by a Manager (SR-29).
     * Skips only the check-in window / flight status rule: cancellation, documents and the
     * seat rule still apply. Boarding must not be closed. The gate agent is the actor in
     * audit_log; a gate_overrides row per coupon records the Manager, the reason and the time.
     */
    public function lateCheckIn(Passenger $passenger, User $gateUser, User $manager, string $reason, ?SegmentDirection $direction = null, ?int $leg = null): void
    {
        DB::transaction(function () use ($passenger, $gateUser, $manager, $reason, $direction, $leg) {
            $this->lock($passenger);
            $leg ??= $this->journeys->legFor($passenger, $gateUser, $direction);
            $coupons = $this->journeys->couponsOfLeg($passenger, $leg);
            $this->ensureBooked($coupons);
            $this->assertEarlierLegsComplete($passenger, $leg);

            if ($manager->role !== Role::Manager) {
                throw new InvalidStatusChange('A late check-in must be approved by a Manager.');
            }
            if (trim($reason) === '') {
                throw new InvalidStatusChange('A reason is required.');
            }

            $flight = $coupons->first()->flight;

            if ($flight->status === FlightStatus::Cancelled) {
                throw new InvalidStatusChange('This flight is cancelled.');
            }
            if ($flight->isBoardingClosed()) {
                throw new InvalidStatusChange('Boarding is closed for this flight.');
            }
            if (! $flight->isLateCheckInOpen()) {
                throw new InvalidStatusChange("Late check-in isn't needed: check-in is still open for this flight.");
            }

            $this->finishCheckIn($passenger, $coupons, $gateUser);

            foreach ($coupons as $coupon) {
                GateOverride::create([
                    'passenger_id' => $passenger->id,
                    'coupon_id' => $coupon->id,
                    'approved_by' => $manager->id,
                    'reason' => trim($reason),
                    'approved_at' => now(),
                ]);
            }
        });
    }

    /**
     * A later leg cannot check in while an earlier leg is still Booked or CheckedIn (C23).
     */
    private function assertEarlierLegsComplete(Passenger $passenger, int $leg): void
    {
        if (! $this->journeys->earlierLegsAreComplete($passenger, $leg)) {
            throw new InvalidStatusChange('Earlier leg must be flown or cancelled before checking in this leg.');
        }
    }

    /**
     * @param  Collection<int, Coupon>  $coupons
     */
    private function ensureBooked(Collection $coupons): void
    {
        if ($coupons->contains(fn (Coupon $coupon) => $coupon->status === PassengerStatus::Cancelled)) {
            throw new InvalidStatusChange('Reservation cancelled');
        }
        if ($coupons->contains(fn (Coupon $coupon) => $coupon->status !== PassengerStatus::Booked)) {
            throw new InvalidStatusChange('Already checked in');
        }
    }

    /**
     * The rules shared by checkIn() and lateCheckIn(): documents and seat on every flight, then the
     * status change of every coupon.
     *
     * @param  Collection<int, Coupon>  $coupons
     */
    private function finishCheckIn(Passenger $passenger, Collection $coupons, User $by): void
    {
        foreach ($coupons as $coupon) {
            $this->ensureValidDocument($passenger, $coupon->flight);
        }

        // Accepting the passenger for check-in records document verification for the gate (#48).
        $passenger->travelDocuments()
            ->whereIn('type', [DocumentType::Passport, DocumentType::IdCard])
            ->whereNull('verified_at')
            ->update([
                'verified_at' => now(),
                'verified_by' => $by->id,
            ]);

        if ($passenger->type !== PassengerType::Infant) {
            $seated = SeatAssignment::query()->whereIn('coupon_id', $coupons->modelKeys())->pluck('coupon_id')->all();

            foreach ($coupons as $coupon) {
                if (! in_array($coupon->id, $seated)) {
                    throw new InvalidStatusChange($coupons->count() === 1 ? 'Seat required' : "Seat required on {$coupon->flight->flight_no}");
                }
            }
        }

        foreach ($coupons as $coupon) {
            $this->changeCouponStatus($coupon, PassengerStatus::CheckedIn, $by, ['checked_in_at' => now()]);
        }

        $passenger->unsetRelation('coupon');
    }

    /**
     * Booked -> Cancelled for the whole journey; every seat is released (SR-5).
     */
    public function cancel(Passenger $passenger, User $by): void
    {
        DB::transaction(function () use ($passenger, $by) {
            $this->lock($passenger);
            $coupons = $passenger->coupons()->with('flight')->lockForUpdate()->get();

            if ($coupons->contains(fn (Coupon $coupon) => $coupon->status !== PassengerStatus::Booked)) {
                throw new InvalidStatusChange('Only a booked passenger can be cancelled');
            }

            SeatAssignment::query()->where('passenger_id', $passenger->id)->delete();

            foreach ($coupons as $coupon) {
                $this->changeCouponStatus($coupon, PassengerStatus::Cancelled, $by);
            }

            $passenger->unsetRelation('coupon');
        });
    }

    /**
     * Booked -> Cancelled for selected coupons only (Sprint 4 Feature 2 partial refund).
     * Seats on those flights are released; other coupons are untouched.
     *
     * @param  list<int>|Collection<int, Coupon>  $coupons
     */
    public function cancelCoupons(Passenger $passenger, User $by, iterable $coupons): void
    {
        DB::transaction(function () use ($passenger, $by, $coupons) {
            $this->lock($passenger);

            $ids = collect($coupons)->map(function ($coupon) {
                return $coupon instanceof Coupon ? $coupon->id : (int) $coupon;
            })->unique()->values()->all();

            if ($ids === []) {
                throw new InvalidStatusChange('Nothing to cancel');
            }

            $locked = Coupon::query()
                ->where('passenger_id', $passenger->id)
                ->whereIn('id', $ids)
                ->with('flight')
                ->lockForUpdate()
                ->get();

            if ($locked->count() !== count($ids)) {
                throw new InvalidStatusChange('Coupon does not belong to this passenger');
            }

            foreach ($locked as $coupon) {
                if ($coupon->status !== PassengerStatus::Booked) {
                    throw new InvalidStatusChange('Only a booked passenger can be cancelled');
                }
            }

            SeatAssignment::query()
                ->where('passenger_id', $passenger->id)
                ->whereIn('flight_id', $locked->pluck('flight_id'))
                ->delete();

            foreach ($locked as $coupon) {
                $this->changeCouponStatus($coupon, PassengerStatus::Cancelled, $by);
            }

            $passenger->unsetRelation('coupon');
        });
    }

    /**
     * CheckedIn -> Boarded on one flight (SR-10, SR-66): the coupon of $flight only. Null = the
     * reservation's first flight, as before B13b. Gate pages pass the flight being boarded.
     */
    public function board(Passenger $passenger, User $by, ?Flight $flight = null): void
    {
        DB::transaction(function () use ($passenger, $by, $flight) {
            $this->lock($passenger);

            $coupon = Coupon::query()
                ->where('passenger_id', $passenger->id)
                ->where('flight_id', $flight?->id ?? $passenger->reservation->flight_id)
                ->with('flight')
                ->lockForUpdate()
                ->first();

            if ($coupon === null) {
                throw new InvalidStatusChange('Passenger is not booked on this flight');
            }
            if ($coupon->status !== PassengerStatus::CheckedIn) {
                throw new InvalidStatusChange('Passenger not checked in');
            }

            FlightStatusGate::assertAllows($coupon->flight, FlightAction::Board);

            $this->assertReadyToBoard($passenger, $coupon);

            $this->changeCouponStatus($coupon, PassengerStatus::Boarded, $by, ['boarded_at' => now()]);

            $passenger->unsetRelation('coupon');
        });
    }

    /**
     * Gate boarding: excess baggage paid and travel documents verified (#48).
     */
    private function assertReadyToBoard(Passenger $passenger, Coupon $coupon): void
    {
        $coupon->loadMissing('segment');
        $direction = $coupon->segment?->direction;

        $due = app(BagService::class)->excessDueCents($passenger, $direction);
        if ($due > 0) {
            throw new InvalidStatusChange('Excess baggage is unpaid');
        }

        $documents = $passenger->travelDocuments()
            ->whereIn('type', [DocumentType::Passport, DocumentType::IdCard])
            ->get();

        if ($documents->isEmpty() || ! $documents->contains(fn ($doc) => $doc->verified_at !== null)) {
            throw new InvalidStatusChange('Travel documents are not verified');
        }
    }

    /**
     * Boarding -> Closed. Every CheckedIn coupon of this flight becomes NoShow (SR-12).
     * Each no-show cancels later coupons on the same reservation (G7 / SR-81), including a return.
     * The Closed step writes a flight audit row before those passenger rows (SR-55).
     * Returns the bags of those passengers in that direction, to be offloaded (SR-9).
     *
     * @return Collection<int, Bag>
     */
    public function closeBoarding(Flight $flight, User $by): Collection
    {
        return DB::transaction(function () use ($flight, $by) {
            $this->lock($flight);

            if ($flight->status !== FlightStatus::Boarding) {
                throw new InvalidStatusChange('Boarding is not open');
            }

            $old = $flight->status->value;

            $flight->forceFill([
                'status' => FlightStatus::Closed,
                'boarding_closed_at' => now(),
            ])->save();

            AuditEntry::forFlight($flight, 'flight_status', $old, FlightStatus::Closed->value, $by);

            $noShows = Coupon::query()
                ->where('flight_id', $flight->id)
                ->where('status', PassengerStatus::CheckedIn)
                ->orderBy('passenger_id')
                ->lockForUpdate()
                ->get();

            foreach ($noShows as $coupon) {
                $coupon->setRelation('flight', $flight);
                $this->changeCouponStatus($coupon, PassengerStatus::NoShow, $by);
                $this->cancelLaterCoupons($coupon, $by);
            }

            return Bag::query()->noShowOn($flight)->whereNull('offloaded_at')->get();
        });
    }

    /**
     * G7 / SR-81: Booked or CheckedIn coupons after $coupon (higher journey sequence) become Cancelled,
     * seats released and passes voided. Covers onward connection legs and the return. One audit row each.
     *
     * @return Collection<int, Coupon> the coupons cancelled
     */
    public function cancelLaterCoupons(Coupon $coupon, User $by): Collection
    {
        return DB::transaction(function () use ($coupon, $by) {
            $segment = $coupon->segment;

            $later = Coupon::query()
                ->select('coupons.*')
                ->join('reservation_segments', 'reservation_segments.id', '=', 'coupons.reservation_segment_id')
                ->where('coupons.passenger_id', $coupon->passenger_id)
                ->where('reservation_segments.sequence', '>', $segment->sequence)
                ->whereIn('coupons.status', [PassengerStatus::Booked, PassengerStatus::CheckedIn])
                ->orderBy('reservation_segments.sequence')
                ->with('flight')
                ->lockForUpdate()
                ->get();

            foreach ($later as $next) {
                SeatAssignment::query()->where('coupon_id', $next->id)->delete();
                $next->boardingPasses()->whereNull('voided_at')->update(['voided_at' => now()]);
                $this->changeCouponStatus($next, PassengerStatus::Cancelled, $by);
            }

            return $later;
        });
    }

    /**
     * Any other flight status move (SR-25). See FLIGHT_MOVES.
     * After a successful save, writes one flight audit row (SR-55). A refused move writes nothing.
     */
    public function setFlightStatus(Flight $flight, FlightStatus $new, User $by): void
    {
        DB::transaction(function () use ($flight, $new, $by) {
            $this->lock($flight);

            $allowed = self::FLIGHT_MOVES[$flight->status->value] ?? [];

            if (! in_array($new->value, $allowed, true)) {
                throw new InvalidStatusChange(
                    "Flight cannot go from {$flight->status->label()} to {$new->label()}"
                );
            }

            if ($new === FlightStatus::Departed) {
                $this->ensureBagsOffloaded($flight);
            }

            $old = $flight->status->value;

            $flight->forceFill(['status' => $new])->save();

            AuditEntry::forFlight($flight, 'flight_status', $old, $new->value, $by);
        });
    }

    /**
     * A flight cannot leave with the bags of a no-show passenger on board (SR-28).
     */
    private function ensureBagsOffloaded(Flight $flight): void
    {
        $left = Bag::query()->noShowOn($flight)->whereNull('offloaded_at')->count();

        if ($left > 0) {
            throw new InvalidStatusChange($left.' '.Str::plural('bag', $left).' still to offload');
        }
    }

    /**
     * The statuses setFlightStatus() would accept for this flight right now (read only, SR-25).
     * Used to offer only legal buttons; the real check stays in setFlightStatus().
     *
     * @return list<FlightStatus>
     */
    public function allowedFlightMoves(Flight $flight): array
    {
        return array_map(
            fn (string $status) => FlightStatus::from($status),
            self::FLIGHT_MOVES[$flight->status->value] ?? [],
        );
    }

    /**
     * The passenger's coupons in one direction, locked for the transaction, flights loaded.
     *
     * @return Collection<int, Coupon>
     */
    private function directionCoupons(Passenger $passenger, SegmentDirection $direction): Collection
    {
        $coupons = $this->journeys->couponsOf($passenger, $direction);

        if ($coupons->isEmpty()) {
            throw new InvalidStatusChange("Passenger has no {$direction->label()} flight");
        }

        Coupon::query()->whereIn('id', $coupons->modelKeys())->lockForUpdate()->get();

        return $coupons;
    }

    /**
     * The passenger needs a Passport or ID card valid until at least
     * <passport_min_months> months after the departure date (SR-23, SR-31).
     */
    private function ensureValidDocument(Passenger $passenger, Flight $flight): void
    {
        $minMonths = (int) (Setting::find('passport_min_months')?->value ?? 0);
        $validUntil = $flight->departureDate()->addMonthsNoOverflow($minMonths);

        $documents = $passenger->travelDocuments()
            ->whereIn('type', [DocumentType::Passport, DocumentType::IdCard])
            ->get();

        if ($documents->isEmpty()) {
            throw new InvalidStatusChange('No travel document');
        }

        if (! $documents->contains(fn ($doc) => $doc->expiry_date->gte($validUntil))) {
            throw new InvalidStatusChange('Document expired');
        }
    }

    /**
     * Save the coupon's new status and write the audit_log row (SR-26): passenger, flight and coupon.
     *
     * @param  array<string, mixed>  $extra  other columns to set, e.g. checked_in_at
     */
    private function changeCouponStatus(Coupon $coupon, PassengerStatus $new, User $by, array $extra = []): void
    {
        $old = $coupon->status;

        $coupon->forceFill(['status' => $new] + $extra)->save();

        AuditEntry::create([
            'passenger_id' => $coupon->passenger_id,
            'flight_id' => $coupon->flight_id,
            'coupon_id' => $coupon->id,
            'field' => 'status',
            'old_value' => $old?->value,
            'new_value' => $new->value,
            'user_id' => $by->id,
            'changed_at' => now(),
        ]);
    }

    /**
     * Re-read the row and lock it until the transaction ends,
     * so two staff members cannot change the same record at the same time.
     * Cached relations are dropped so rules are checked on fresh data
     * (e.g. the flight's current status, not the one loaded earlier).
     */
    private function lock(Model $model): void
    {
        $fresh = $model->newQuery()->lockForUpdate()->findOrFail($model->getKey());

        $model->setRawAttributes($fresh->getAttributes(), true);
        $model->setRelations([]);
    }
}
