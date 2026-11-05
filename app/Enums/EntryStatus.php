<?php

namespace App\Enums;

/**
 * Result of the entry requirements check (SR-49).
 */
enum EntryStatus: string
{
    case Ok = 'ok';
    case Missing = 'missing';
    case Refused = 'refused';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Missing => 'Document missing',
            self::Refused => 'No admission',
            self::Unknown => 'Check manually',
        };
    }
}
