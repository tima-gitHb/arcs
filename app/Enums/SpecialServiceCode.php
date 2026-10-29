<?php

namespace App\Enums;

/**
 * IATA special service request (SSR) codes used by ARCS (SR-44).
 */
enum SpecialServiceCode: string
{
    case WCHR = 'WCHR';
    case WCHS = 'WCHS';
    case WCHC = 'WCHC';
    case BLND = 'BLND';
    case DEAF = 'DEAF';
    case DPNA = 'DPNA';
    case MAAS = 'MAAS';
    case UMNR = 'UMNR';
    case PETC = 'PETC';
    case AVIH = 'AVIH';
    case MEDA = 'MEDA';
    case VGML = 'VGML';
    case DBML = 'DBML';
    case CHML = 'CHML';

    public function label(): string
    {
        return match ($this) {
            self::WCHR => 'Wheelchair: can walk short distances and climb stairs',
            self::WCHS => 'Wheelchair: cannot climb stairs',
            self::WCHC => 'Wheelchair: cannot walk, carried to the seat',
            self::BLND => 'Blind or visually impaired',
            self::DEAF => 'Deaf or hard of hearing',
            self::DPNA => 'Intellectual or developmental disability, needs help',
            self::MAAS => 'Meet and assist',
            self::UMNR => 'Unaccompanied minor',
            self::PETC => 'Pet in the cabin',
            self::AVIH => 'Animal in the hold',
            self::MEDA => 'Medical case',
            self::VGML => 'Vegetarian meal',
            self::DBML => 'Diabetic meal',
            self::CHML => 'Child meal',
        };
    }

    /**
     * Codes that need a free-text note (e.g. the pet, the minor's guardian, the medical need).
     */
    public function requiresDetails(): bool
    {
        return match ($this) {
            self::UMNR, self::PETC, self::AVIH, self::DPNA, self::MEDA => true,
            default => false,
        };
    }
}
