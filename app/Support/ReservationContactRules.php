<?php

namespace App\Support;

/**
 * Validation for the reservation contact, ready for the booking form (cards R9, R10).
 */
class ReservationContactRules
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'contact_email' => ['nullable', 'email', 'max:255'],
            // Digits, with an optional + in front.
            'contact_phone' => ['nullable', 'regex:/^\+?[0-9]+$/', 'max:20'],
        ];
    }
}
