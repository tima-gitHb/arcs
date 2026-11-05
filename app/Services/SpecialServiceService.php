<?php

namespace App\Services;

use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\SpecialServiceCode;
use App\Exceptions\SpecialServiceNotAllowed;
use App\Models\AuditEntry;
use App\Models\Passenger;
use App\Models\SpecialService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Special services with IATA codes on the check-in screen (C7: SR-2, SR-44).
 * Every add and remove writes an audit_log row.
 */
class SpecialServiceService
{
    private const LOCKED = 'Special services can only be changed for a booked or checked-in passenger.';

    public function add(Passenger $passenger, SpecialServiceCode $code, ?string $details, User $by): SpecialService
    {
        return DB::transaction(function () use ($passenger, $code, $details, $by) {
            // The lock also stops two agents adding the same code at the same moment.
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($passenger->id);
            $details = trim((string) $details);

            if (! $passenger->checkinDetailsEditable()) {
                throw new SpecialServiceNotAllowed(self::LOCKED);
            }
            if ($code->requiresDetails() && $details === '') {
                throw new SpecialServiceNotAllowed("Details are required for {$code->value}.");
            }
            if ($code === SpecialServiceCode::UMNR && ! $this->isLoneChild($passenger)) {
                throw new SpecialServiceNotAllowed('UMNR is only for a child with no adult on the reservation.');
            }
            if ($passenger->specialServices()->where('type', $code)->exists()) {
                throw new SpecialServiceNotAllowed("{$code->value} is already on this passenger.");
            }

            $service = $passenger->specialServices()->create([
                'type' => $code,
                'details' => $details === '' ? null : $details,
            ]);

            AuditEntry::create([
                'passenger_id' => $passenger->id,
                'field' => 'special_service_added',
                'old_value' => null,
                'new_value' => $code->value,
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);

            return $service;
        });
    }

    public function remove(SpecialService $service, User $by): void
    {
        DB::transaction(function () use ($service, $by) {
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($service->passenger_id);

            if (! $passenger->checkinDetailsEditable()) {
                throw new SpecialServiceNotAllowed(self::LOCKED);
            }

            $service->delete();

            AuditEntry::create([
                'passenger_id' => $passenger->id,
                'field' => 'special_service_removed',
                'old_value' => $service->type->value,
                'new_value' => 'removed',
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);
        });
    }

    /**
     * A Child with no Adult on the same reservation. A cancelled adult is not travelling.
     */
    private function isLoneChild(Passenger $passenger): bool
    {
        return $passenger->type === PassengerType::Child
            && ! $passenger->reservation->passengers()
                ->where('type', PassengerType::Adult)
                ->where('status', '!=', PassengerStatus::Cancelled)
                ->exists();
    }
}
