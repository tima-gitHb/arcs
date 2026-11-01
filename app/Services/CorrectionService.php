<?php

namespace App\Services;

use App\Models\AuditEntry;
use App\Models\Passenger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a passenger's details and writes one audit_log row per changed field (SR-33).
 * Check-in will reuse this service for document numbers (C2).
 */
class CorrectionService
{
    /**
     * Passenger columns this service is allowed to change.
     * Status stays with StatusService.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'first_name',
        'last_name',
    ];

    /**
     * @param  array<string, mixed>  $changes
     */
    public function correctPassenger(Passenger $passenger, array $changes, User $by): void
    {
        DB::transaction(function () use ($passenger, $changes, $by) {
            $fresh = $passenger->newQuery()->lockForUpdate()->findOrFail($passenger->id);
            $passenger->setRawAttributes($fresh->getAttributes(), true);

            $updates = [];

            foreach (self::FIELDS as $field) {
                if (! array_key_exists($field, $changes)) {
                    continue;
                }

                $new = (string) $changes[$field];

                if ($new === (string) $passenger->{$field}) {
                    continue;
                }

                $updates[$field] = $new;
            }

            if ($updates === []) {
                return;
            }

            foreach ($updates as $field => $new) {
                AuditEntry::create([
                    'passenger_id' => $passenger->id,
                    'field' => $field,
                    'old_value' => $passenger->{$field},
                    'new_value' => $new,
                    'user_id' => $by->id,
                    'changed_at' => now(),
                ]);
            }

            $passenger->update($updates);
        });
    }
}
