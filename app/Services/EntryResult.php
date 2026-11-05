<?php

namespace App\Services;

use App\Enums\EntryStatus;

/**
 * What EntryRequirementChecker found for one passenger (SR-49).
 */
final class EntryResult
{
    public function __construct(
        public readonly EntryStatus $status,
        public readonly string $message,
        public readonly ?string $note = null,
    ) {}

    /**
     * The check never blocks check-in, but on missing or refused the agent must write a
     * reason before confirming (audit_log field entry_override).
     */
    public function needsAcknowledgement(): bool
    {
        return in_array($this->status, [EntryStatus::Missing, EntryStatus::Refused], true);
    }
}
