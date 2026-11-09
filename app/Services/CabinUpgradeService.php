<?php

namespace App\Services;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Exceptions\SeatTaken;
use App\Exceptions\UpgradeNotAllowed;
use App\Models\AuditEntry;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\User;
use App\Services\Payments\CardDetails;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Paid cabin upgrade at check-in: Economy → Premium → Business only.
 */
class CabinUpgradeService
{
    /** @var list<CabinClass> */
    private const RANK = [CabinClass::Economy, CabinClass::Premium, CabinClass::Business];

    public function __construct(
        private FareService $fares,
        private PaymentService $payments,
        private SeatService $seats,
        private BoardingPassService $passes,
        private BagService $bags,
    ) {}

    public function priceCents(Passenger $passenger, CabinClass $to): int
    {
        $from = $passenger->cabin_class ?? CabinClass::Economy;
        $this->assertUpgradeAllowed($passenger, $to, preview: true);

        $new = $this->fares->quote([['cabin_class' => $to, 'type' => $passenger->type]])->totalCents;
        $old = $this->fares->quote([['cabin_class' => $from, 'type' => $passenger->type]])->totalCents;

        return max(0, $new - $old);
    }

    /**
     * Charge, then in one transaction: cabin_class, seat, infant class, bags, boarding pass.
     */
    public function upgrade(Passenger $passenger, CabinClass $to, Seat $seat, CardDetails $card, User $by): Payment
    {
        $amount = $this->priceCents($passenger, $to);

        if ($seat->cabin_class !== $to) {
            throw new UpgradeNotAllowed('Pick a free seat in '.$to->label().'.');
        }

        if ($amount <= 0) {
            throw new UpgradeNotAllowed('No upgrade charge applies for that class change.');
        }

        // Refuse a taken seat before charging so a declined/failed path leaves nothing behind.
        if (SeatAssignment::query()
            ->where('flight_id', $passenger->reservation->flight_id)
            ->where('seat_id', $seat->id)
            ->where('passenger_id', '!=', $passenger->id)
            ->exists()) {
            throw new SeatTaken;
        }

        // Charge first; on decline nothing else runs.
        $payment = $this->payments->charge(
            $card,
            $amount,
            PaymentKind::Upgrade,
            $by,
            $passenger->reservation,
            $passenger,
        );

        if ($payment->status !== PaymentStatus::Succeeded) {
            return $payment;
        }

        DB::transaction(function () use ($passenger, $to, $seat, $by, $amount) {
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($passenger->id);
            $this->assertUpgradeAllowed($passenger, $to);

            $from = $passenger->cabin_class ?? CabinClass::Economy;
            $oldSeatNo = $passenger->seatAssignment?->seat?->seat_no;

            $passenger->update(['cabin_class' => $to]);

            AuditEntry::create([
                'passenger_id' => $passenger->id,
                'field' => 'cabin_class',
                'old_value' => $from->value,
                'new_value' => $to->value,
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);

            // Infants on this adult follow the new class (no seat).
            $passenger->infants()->each(function (Passenger $infant) use ($to, $by, $from) {
                $infant->update(['cabin_class' => $to]);
                AuditEntry::create([
                    'passenger_id' => $infant->id,
                    'field' => 'cabin_class',
                    'old_value' => $from->value,
                    'new_value' => $to->value,
                    'user_id' => $by->id,
                    'changed_at' => now(),
                ]);
            });

            $this->seats->assignForUpgrade($passenger->fresh(), $seat, $by);

            if ($passenger->status === PassengerStatus::CheckedIn) {
                $this->passes->issue($passenger->fresh(), $by);
            }

            $this->bags->recalculate($passenger->fresh());

            AuditEntry::create([
                'passenger_id' => $passenger->id,
                'field' => 'upgrade',
                'old_value' => $from->value.($oldSeatNo ? " {$oldSeatNo}" : ''),
                'new_value' => $to->value.' '.$seat->seat_no.' '.Money::format($amount),
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);
        });

        return $payment;
    }

    /**
     * @return list<CabinClass>
     */
    public function higherClasses(CabinClass $from): array
    {
        $rank = array_search($from, self::RANK, true);
        if ($rank === false) {
            return [];
        }

        return array_values(array_slice(self::RANK, $rank + 1));
    }

    private function assertUpgradeAllowed(Passenger $passenger, CabinClass $to, bool $preview = false): void
    {
        if (! in_array($passenger->status, [PassengerStatus::Booked, PassengerStatus::CheckedIn], true)) {
            throw new UpgradeNotAllowed('Upgrade is only for a booked or checked-in passenger.');
        }

        $flight = $passenger->reservation->flight;
        if ($flight->isBoardingClosed() || $flight->status === FlightStatus::Cancelled) {
            throw new UpgradeNotAllowed('Upgrade is not available after boarding is closed.');
        }

        if ($passenger->type === PassengerType::Infant) {
            throw new UpgradeNotAllowed('Upgrade the accompanying adult; the infant follows.');
        }

        $from = $passenger->cabin_class ?? CabinClass::Economy;
        if (! in_array($to, $this->higherClasses($from), true)) {
            throw new UpgradeNotAllowed('Only an upgrade to a higher cabin class is allowed.');
        }
    }
}
