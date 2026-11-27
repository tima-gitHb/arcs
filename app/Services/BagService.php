<?php

namespace App\Services;

use App\Enums\BagHandling;
use App\Enums\BagType;
use App\Enums\PassengerStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\SegmentDirection;
use App\Exceptions\BagNotAllowed;
use App\Exceptions\FareNotFound;
use App\Models\AuditEntry;
use App\Models\Bag;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bags at check-in (C4 / C10: SR-9, SR-43, SR-59). Weight is pooled against the allowance;
 * excess is priced by FareService. Charging the card is C11.
 */
class BagService
{
    public function __construct(
        private AllowanceService $allowances,
        private FareService $fares,
        private JourneyService $journeys,
    ) {}

    private const TAG_DIGITS = 6;

    private const MAX_TAG_ATTEMPTS = 10;

    /**
     * Add a bag with a new unique tag, write an audit row and refresh the allowance flags.
     */
    public function add(Passenger $passenger, float $weightKg, BagType $type, User $by, ?SegmentDirection $direction = null, ?int $leg = null): Bag
    {
        return DB::transaction(function () use ($passenger, $weightKg, $type, $by, $direction, $leg) {
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($passenger->id);
            // The leg being checked in; the bag goes to that leg's final destination (SR-67 / C23).
            $leg ??= $this->journeys->legFor($passenger, $by, $direction);
            $direction ??= $this->journeys->segmentsOfLeg($passenger->reservation, $leg)->first()?->direction
                ?? $this->journeys->directionFor($passenger, $by);

            if (! in_array($this->journeys->statusOfLeg($passenger, $leg), [PassengerStatus::Booked, PassengerStatus::CheckedIn], true)) {
                throw new BagNotAllowed('Bags can only be added for a booked or checked-in passenger.');
            }

            $allowance = $this->allowances->for($passenger, $direction, $leg);
            if ($weightKg > $allowance->hardMaxKg) {
                throw new BagNotAllowed('Bag over 32 kg must be repacked');
            }

            $destination = $this->journeys->finalDestinationOfLeg($passenger->reservation, $leg);

            $bag = $passenger->bags()->create([
                'direction' => $direction,
                'destination' => $destination,
                'tag_no' => $this->newTag($passenger, $direction),
                'type' => $type,
                // Wheelchairs and strollers go back to the passenger at the aircraft door.
                'handling' => $type->countsTowardAllowance() ? BagHandling::Hold : BagHandling::GateDelivery,
                'weight_kg' => $weightKg,
            ]);

            AuditEntry::create([
                'passenger_id' => $passenger->id,
                'field' => 'bag_added',
                'old_value' => null,
                'new_value' => $bag->tag_no,
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);

            $this->recalculate($passenger);

            return $bag->refresh();
        });
    }

    /**
     * Remove a bag: only before check-in is confirmed (passenger still Booked).
     */
    public function remove(Bag $bag, User $by): void
    {
        DB::transaction(function () use ($bag, $by) {
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($bag->passenger_id);

            $bagLeg = $this->legNumberForBag($passenger, $bag);
            $status = $bagLeg !== null
                ? $this->journeys->statusOfLeg($passenger, $bagLeg)
                : $this->journeys->statusOf($passenger, $bag->direction);

            if ($status !== PassengerStatus::Booked) {
                throw new BagNotAllowed('A bag can only be removed before check-in is confirmed.');
            }

            $bag->delete();

            AuditEntry::create([
                'passenger_id' => $passenger->id,
                'field' => 'bag_removed',
                'old_value' => $bag->tag_no,
                'new_value' => 'removed',
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);

            $this->recalculate($passenger);
        });
    }

    /**
     * Confirm a no-show passenger's bag is off the aircraft: records who and when (G4, SR-28).
     */
    public function confirmOffload(Bag $bag, User $by): void
    {
        DB::transaction(function () use ($bag, $by) {
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($bag->passenger_id);
            $bag = Bag::query()->lockForUpdate()->findOrFail($bag->id);

            $noShow = $this->journeys->couponsOf($passenger, $bag->direction)
                ->contains(fn ($coupon) => $coupon->status === PassengerStatus::NoShow);
            if (! $noShow) {
                throw new BagNotAllowed('Only the bags of a no-show passenger can be offloaded.');
            }
            if ($bag->offloaded_at !== null) {
                throw new BagNotAllowed("Bag {$bag->tag_no} is already offloaded.");
            }

            $bag->update(['offloaded_at' => now(), 'offloaded_by' => $by->id]);

            AuditEntry::create([
                'passenger_id' => $passenger->id,
                'field' => 'bag_offloaded',
                'old_value' => null,
                'new_value' => $bag->tag_no,
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);
        });
    }

    /**
     * Pooled excess vs AllowanceService (SR-59). Extra pieces beyond the allowance are
     * charged as ExtraBag; allowed kg expands by kg-per-bag for each of those pieces so
     * weight already covered by an extra bag is not charged again as ExcessKg:
     * allowed kg = (allowed pieces + extra bags) × kg per bag (+ prepaid extra_kg).
     * Wheelchairs and strollers are ignored. Missing fees → quote null ("Price not set").
     */
    public function excess(Passenger $passenger, ?SegmentDirection $direction = null, ?int $leg = null): BaggageExcess
    {
        // Each leg/direction has its own allowance and bags (B13b / C23). Default: current direction/leg.
        $leg ??= $direction === null ? $this->journeys->legFor($passenger) : null;
        if ($leg !== null) {
            $direction ??= $this->journeys->segmentsOfLeg($passenger->reservation, $leg)->first()?->direction
                ?? $this->journeys->directionOf($passenger);
        } else {
            $direction ??= $this->journeys->directionOf($passenger);
        }

        $allowance = $this->allowances->for($passenger, $direction, $leg);
        $bagsQuery = $passenger->bags()
            ->where('direction', $direction)
            ->whereNull('offloaded_at')
            ->orderBy('id');

        if ($leg !== null) {
            $bagsQuery->where('destination', $this->journeys->finalDestinationOfLeg($passenger->reservation, $leg));
        }

        $counted = $bagsQuery
            ->get()
            ->filter(fn (Bag $bag) => $bag->type->countsTowardAllowance());

        $piecesUsed = $counted->count();
        $kgUsed = (float) $counted->sum(fn (Bag $bag) => (float) $bag->weight_kg);

        $extraBags = 0;
        $excessKg = 0.0;

        if ($allowance->isSet) {
            $extraBags = max(0, $piecesUsed - $allowance->pieces);
            // Don't charge the same weight twice: each extra bag also expands allowed kg.
            $allowedKg = $allowance->totalKg + ($extraBags * $allowance->kgPerBag);
            $excessKg = max(0.0, round($kgUsed - $allowedKg, 1));
        }

        $quote = null;
        if ($extraBags > 0 || $excessKg > 0) {
            try {
                $quote = $this->fares->quote([], [
                    'extra_bags' => $extraBags,
                    'extra_kg' => (int) ceil($excessKg),
                ]);
            } catch (FareNotFound) {
                $quote = null;
            }
        } else {
            $quote = new Quote([], 0, 'USD');
        }

        return new BaggageExcess($piecesUsed, $kgUsed, $extraBags, $excessKg, $quote);
    }

    /**
     * Succeeded ExcessBaggage payments already taken for this passenger (C11 / SR-60).
     */
    public function excessPaidCents(Passenger $passenger): int
    {
        return (int) Payment::query()
            ->where('passenger_id', $passenger->id)
            ->where('kind', PaymentKind::ExcessBaggage)
            ->where('status', PaymentStatus::Succeeded)
            ->sum('amount_cents');
    }

    /**
     * What is still owed for the current pooled excess after prior payments (C11).
     * Zero when there is no excess, fees are missing, or payments cover the quote.
     */
    public function excessDueCents(Passenger $passenger, ?SegmentDirection $direction = null, ?int $leg = null): int
    {
        $excess = $this->excess($passenger, $direction, $leg);

        if (! $excess->hasExcess() || ! $excess->priceIsSet()) {
            return 0;
        }

        return max(0, $excess->quote->totalCents - $this->excessPaidCents($passenger));
    }

    /**
     * Clear per-bag over_allowance: excess is pooled at passenger level (C10 / SR-59).
     * Still called after add/remove/seat change so the column stays consistent.
     */
    public function recalculate(Passenger $passenger): void
    {
        $bags = $passenger->bags()->whereNull('offloaded_at')->orderBy('id')->get();

        foreach ($bags as $bag) {
            if ($bag->over_allowance) {
                $bag->update(['over_allowance' => false]);
            }
        }
    }

    /**
     * Reprint: rotate to a new unique tag_no and audit old → new (C15). The old number is void
     * because it no longer exists on any bag.
     */
    public function reprintTag(Bag $bag, User $by): Bag
    {
        return DB::transaction(function () use ($bag, $by) {
            $bag = Bag::query()->lockForUpdate()->findOrFail($bag->id);
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($bag->passenger_id);
            $oldTag = $bag->tag_no;
            $newTag = $this->newTag($passenger, $bag->direction);

            $bag->update(['tag_no' => $newTag]);

            AuditEntry::create([
                'passenger_id' => $passenger->id,
                'field' => 'bag_tag_reprint',
                'old_value' => $oldTag,
                'new_value' => $newTag,
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);

            return $bag->refresh();
        });
    }

    /**
     * Tag number: the airline code of the flight (first 2 characters of flight_no, e.g. config arcs.airline.code) + 6 digits,
     * unique in bags (SR-9). Retried on the rare collision, like the PNR.
     */
    private function newTag(Passenger $passenger, SegmentDirection $direction): string
    {
        $first = $this->journeys->flightsOf($passenger->reservation, $direction)->first() ?? $passenger->reservation->flight;
        $airline = strtoupper(substr($first->flight_no, 0, 2));

        for ($attempt = 0; $attempt < self::MAX_TAG_ATTEMPTS; $attempt++) {
            $tag = $airline.str_pad((string) random_int(0, 10 ** self::TAG_DIGITS - 1), self::TAG_DIGITS, '0', STR_PAD_LEFT);

            if (! Bag::query()->where('tag_no', $tag)->exists()) {
                return $tag;
            }
        }

        throw new RuntimeException('Could not generate a unique bag tag.');
    }

    /**
     * Infer the bag's leg from its destination (C23 — no bags.leg column).
     */
    private function legNumberForBag(Passenger $passenger, Bag $bag): ?int
    {
        foreach ($this->journeys->legNumbers($passenger->reservation) as $leg) {
            if ($this->journeys->finalDestinationOfLeg($passenger->reservation, $leg) === $bag->destination) {
                return $leg;
            }
        }

        return null;
    }
}
