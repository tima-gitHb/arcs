<?php

namespace App\Services;

use App\Models\AuditEntry;
use App\Models\Passenger;
use App\Models\User;

/**
 * Passenger profile updates that are not status changes (CR-12 email at the desk).
 */
class PassengerService
{
    /**
     * Address used for check-in / boarding-pass mail: passengers.email, else booking contact.
     */
    public function noticeEmail(Passenger $passenger): ?string
    {
        $passenger->loadMissing('reservation');
        $own = trim((string) ($passenger->email ?? ''));
        if ($own !== '') {
            return $own;
        }

        $contact = trim((string) ($passenger->reservation->contact_email ?? ''));

        return $contact !== '' ? $contact : null;
    }

    /**
     * Desk label: the passenger email, else "Booking contact: …", else "No email".
     */
    public function noticeEmailLabel(Passenger $passenger): string
    {
        $passenger->loadMissing('reservation');
        $own = trim((string) ($passenger->email ?? ''));
        if ($own !== '') {
            return $own;
        }

        $contact = trim((string) ($passenger->reservation->contact_email ?? ''));
        if ($contact !== '') {
            return 'Booking contact: '.$contact;
        }

        return 'No email';
    }

    /**
     * Set or clear passengers.email. Empty string clears (booking contact is then used for mail).
     */
    public function updateEmail(Passenger $passenger, ?string $email, User $by): void
    {
        $normalized = $email === null ? null : trim($email);
        if ($normalized === '') {
            $normalized = null;
        }

        $old = $passenger->email;
        if ($old === $normalized) {
            return;
        }

        $passenger->forceFill(['email' => $normalized])->save();

        // audit_log.new_value / old_value are NOT NULL strings — cleared email → "".
        AuditEntry::create([
            'passenger_id' => $passenger->id,
            'field' => 'email',
            'old_value' => $old ?? '',
            'new_value' => $normalized ?? '',
            'user_id' => $by->id,
            'changed_at' => now(),
        ]);
    }
}
