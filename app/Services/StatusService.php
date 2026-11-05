<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Exceptions\InvalidStatusChange;
use App\Models\AuditEntry;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\GateOverride;
use App\Models\Passenger;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY code allowed to change a passenger's or a flight's status (see CLAUDE.md).
 * Every passenger change runs in a transaction and writes an audit_log row (SR-26).
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

    /**
     * Booked -> CheckedIn (SR-6, SR-23, SR-25, SR-30, SR-31).
     * A passenger other than an infant must have a seat first (C5).
     */
    public function checkIn(Passenger $passenger, User $by): void
    {
        DB::transaction(function () use ($passenger, $by) {
            $this->lock($passenger);
            $this->ensureBooked($passenger);

            $flight = $passenger->reservation->flight;

            if ($flight->status !== FlightStatus::CheckinOpen || ! $flight->isCheckinWindowOpen()) {
                throw new InvalidStatusChange('Check-in closed');
            }

            $this->finishCheckIn($passenger, $flight, $by);
        });
    }

    /**
     * Booked -> CheckedIn at the gate after check-in has closed, approved by a Manager (SR-29).
     * Skips only the check-in window / flight status rule: cancellation, documents and the
     * seat rule still apply. Boarding must not be closed. The gate agent is the actor in
     * audit_log; the gate_overrides row records the Manager, the reason and the time.
     */
    public function lateCheckIn(Passenger $passenger, User $gateUser, User $manager, string $reason): void
    {
        DB::transaction(function () use ($passenger, $gateUser, $manager, $reason) {
            $this->lock($passenger);
            $this->ensureBooked($passenger);

            if ($manager->role !== Role::Manager) {
                throw new InvalidStatusChange('A late check-in must be approved by a Manager.');
            }
            if (trim($reason) === '') {
                throw new InvalidStatusChange('A reason is required.');
            }

            $flight = $passenger->reservation->flight;

            if ($flight->status === FlightStatus::Cancelled) {
                throw new InvalidStatusChange('This flight is cancelled.');
            }
            if ($flight->isBoardingClosed()) {
                throw new InvalidStatusChange('Boarding is closed for this flight.');
            }
            if (! $flight->isLateCheckInOpen()) {
                throw new InvalidStatusChange("Late check-in isn't needed: check-in is still open for this flight.");
            }

            $this->finishCheckIn($passenger, $flight, $gateUser);

            GateOverride::create([
                'passenger_id' => $passenger->id,
                'approved_by' => $manager->id,
                'reason' => trim($reason),
                'approved_at' => now(),
            ]);
        });
    }

    private function ensureBooked(Passenger $passenger): void
    {
        if ($passenger->status === PassengerStatus::Cancelled) {
            throw new InvalidStatusChange('Reservation cancelled');
        }
        if ($passenger->status !== PassengerStatus::Booked) {
            throw new InvalidStatusChange('Already checked in');
        }
    }

    /**
     * The rules shared by checkIn() and lateCheckIn(): documents and seat, then the status change.
     */
    private function finishCheckIn(Passenger $passenger, Flight $flight, User $by): void
    {
        $this->ensureValidDocument($passenger, $flight);

        if ($passenger->type !== PassengerType::Infant && $passenger->seatAssignment === null) {
            throw new InvalidStatusChange('Seat required');
        }

        $this->changePassengerStatus($passenger, PassengerStatus::CheckedIn, $by, [
            'checked_in_at' => now(),
        ]);
    }

    /**
     * Booked -> Cancelled; the seat is released (SR-5).
     */
    public function cancel(Passenger $passenger, User $by): void
    {
        DB::transaction(function () use ($passenger, $by) {
            $this->lock($passenger);

            if ($passenger->status !== PassengerStatus::Booked) {
                throw new InvalidStatusChange('Only a booked passenger can be cancelled');
            }

            $passenger->seatAssignment()->delete();

            $this->changePassengerStatus($passenger, PassengerStatus::Cancelled, $by);
        });
    }

    /**
     * CheckedIn -> Boarded (SR-10).
     */
    public function board(Passenger $passenger, User $by): void
    {
        DB::transaction(function () use ($passenger, $by) {
            $this->lock($passenger);

            if ($passenger->status !== PassengerStatus::CheckedIn) {
                throw new InvalidStatusChange('Passenger not checked in');
            }
            if ($passenger->reservation->flight->status !== FlightStatus::Boarding) {
                throw new InvalidStatusChange('Boarding has not started');
            }

            $this->changePassengerStatus($passenger, PassengerStatus::Boarded, $by, [
                'boarded_at' => now(),
            ]);
        });
    }

    /**
     * Boarding -> Closed. Every CheckedIn passenger becomes NoShow (SR-12).
     * Returns the bags of those passengers, to be offloaded (SR-9).
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

            $flight->forceFill([
                'status' => FlightStatus::Closed,
                'boarding_closed_at' => now(),
            ])->save();

            $noShows = $flight->passengers()
                ->where('passengers.status', PassengerStatus::CheckedIn)
                ->lockForUpdate()
                ->get();

            foreach ($noShows as $passenger) {
                $this->changePassengerStatus($passenger, PassengerStatus::NoShow, $by);
            }

            return Bag::whereIn('passenger_id', $noShows->pluck('id'))
                ->whereNull('offloaded_at')
                ->get();
        });
    }

    /**
     * Any other flight status move (SR-25). See FLIGHT_MOVES.
     */
    public function setFlightStatus(Flight $flight, FlightStatus $new, User $by): void
    {
        DB::transaction(function () use ($flight, $new) {
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

            $flight->forceFill(['status' => $new])->save();
        });
    }

    /**
     * A flight cannot leave with the bags of a no-show passenger on board (SR-28).
     */
    private function ensureBagsOffloaded(Flight $flight): void
    {
        $left = Bag::query()
            ->whereNull('offloaded_at')
            ->whereHas('passenger', fn ($passenger) => $passenger
                ->where('status', PassengerStatus::NoShow)
                ->whereHas('reservation', fn ($reservation) => $reservation->where('flight_id', $flight->id)))
            ->count();

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
     * The passenger needs a Passport or ID card valid until at least
     * <passport_min_months> months after the departure date (SR-23, SR-31).
     */
    private function ensureValidDocument(Passenger $passenger, Flight $flight): void
    {
        $minMonths = (int) (Setting::find('passport_min_months')?->value ?? 0);
        $validUntil = $flight->departure_at->copy()->startOfDay()->addMonthsNoOverflow($minMonths);

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
     * Save the new status and write the audit_log row (SR-26).
     *
     * @param  array<string, mixed>  $extra  other columns to set, e.g. checked_in_at
     */
    private function changePassengerStatus(Passenger $passenger, PassengerStatus $new, User $by, array $extra = []): void
    {
        $old = $passenger->status;

        $passenger->forceFill(['status' => $new] + $extra)->save();

        AuditEntry::create([
            'passenger_id' => $passenger->id,
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
