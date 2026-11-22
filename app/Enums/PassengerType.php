<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum PassengerType: string
{
    case Adult = 'Adult';
    case Child = 'Child';
    case Infant = 'Infant';

    public function label(): string
    {
        return match ($this) {
            self::Adult => 'Adult',
            self::Child => 'Child',
            self::Infant => 'Infant',
        };
    }

    /**
     * Type from age on the travel date: infant under 2, child 2–11, adult 12+.
     */
    public static function fromDateOfBirthOn(CarbonInterface $dateOfBirth, CarbonInterface $travelDate): self
    {
        $on = $travelDate->copy()->startOfDay();
        $dob = $dateOfBirth->copy()->startOfDay();

        if ($on->lt($dob->copy()->addYears(2))) {
            return self::Infant;
        }
        if ($on->lt($dob->copy()->addYears(12))) {
            return self::Child;
        }

        return self::Adult;
    }

    /**
     * Age in whole years on the travel date (0 if before first birthday).
     */
    public static function ageOn(CarbonInterface $dateOfBirth, CarbonInterface $travelDate): int
    {
        return (int) $dateOfBirth->copy()->startOfDay()->diffInYears($travelDate->copy()->startOfDay());
    }
}
