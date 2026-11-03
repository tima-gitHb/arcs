<?php

namespace App\Services;

use App\Enums\BagHandling;
use App\Enums\BagType;
use App\Enums\CabinClass;
use App\Enums\PassengerStatus;
use App\Exceptions\BagNotAllowed;
use App\Models\AuditEntry;
use App\Models\Bag;
use App\Models\BaggageAllowance;
use App\Models\Passenger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bags at check-in (C4: SR-9, SR-27, SR-43). Extra bags are flagged over_allowance,
 * never charged (charging is out of scope).
 */
class BagService
{
    private const TAG_DIGITS = 6;

    private const MAX_TAG_ATTEMPTS = 10;

    /**
     * Add a bag with a new unique tag, write an audit row and refresh the allowance flags.
     */
    public function add(Passenger $passenger, float $weightKg, BagType $type, User $by): Bag
    {
        return DB::transaction(function () use ($passenger, $weightKg, $type, $by) {
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($passenger->id);

            if (! $passenger->checkinDetailsEditable()) {
                throw new BagNotAllowed('Bags can only be added for a booked or checked-in passenger.');
            }

            $bag = $passenger->bags()->create([
                'tag_no' => $this->newTag($passenger),
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

            if ($passenger->status !== PassengerStatus::Booked) {
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
     * Set over_allowance on each of the passenger's bags, in the order they were added (SR-27):
     * a counted bag is over if it is beyond the allowed pieces or heavier than the maximum.
     * Wheelchairs and strollers are never counted and never over (SR-43).
     * Offloaded bags do not count. No allowance set for the cabin: nothing is flagged.
     */
    public function recalculate(Passenger $passenger): void
    {
        $allowance = BaggageAllowance::query()->where('cabin_class', $this->cabinClassOf($passenger))->first();
        $counted = 0;

        $bags = $passenger->bags()->whereNull('offloaded_at')->orderBy('id')->get();

        foreach ($bags as $bag) {
            $over = false;

            if ($allowance && $bag->type->countsTowardAllowance()) {
                $counted++;
                $over = $counted > $allowance->pieces || (float) $bag->weight_kg > (float) $allowance->max_kg_per_bag;
            }

            if ($bag->over_allowance !== $over) {
                $bag->update(['over_allowance' => $over]);
            }
        }
    }

    /**
     * The cabin of the passenger's seat; an infant (no seat) travels in the adult's cabin.
     * Economy when no seat is assigned yet.
     */
    public function cabinClassOf(Passenger $passenger): CabinClass
    {
        $seated = $passenger->accompanyingAdult ?? $passenger;

        return $seated->seatAssignment?->seat?->cabin_class ?? CabinClass::Economy;
    }

    /**
     * Tag number: the airline code of the flight (first 2 characters, e.g. ME) + 6 digits,
     * unique in bags (SR-9). Retried on the rare collision, like the PNR.
     */
    private function newTag(Passenger $passenger): string
    {
        $airline = strtoupper(substr($passenger->reservation->flight->flight_no, 0, 2));

        for ($attempt = 0; $attempt < self::MAX_TAG_ATTEMPTS; $attempt++) {
            $tag = $airline.str_pad((string) random_int(0, 10 ** self::TAG_DIGITS - 1), self::TAG_DIGITS, '0', STR_PAD_LEFT);

            if (! Bag::query()->where('tag_no', $tag)->exists()) {
                return $tag;
            }
        }

        throw new RuntimeException('Could not generate a unique bag tag.');
    }
}
