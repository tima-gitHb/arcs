<?php

namespace App\Enums;

enum DocumentType: string
{
    case Passport = 'Passport';
    case IdCard = 'IdCard';
    case Visa = 'Visa';
    case ResidencePermit = 'ResidencePermit';

    public function label(): string
    {
        return match ($this) {
            self::Passport => 'Passport',
            self::IdCard => 'ID card',
            self::Visa => 'Visa',
            self::ResidencePermit => 'Residence permit',
        };
    }
}
