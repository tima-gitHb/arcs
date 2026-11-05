<?php

namespace App\Services;

use App\Exceptions\DocumentsLocked;
use App\Models\AuditEntry;
use App\Models\Passenger;
use App\Models\TravelDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a passenger's details, and adds, corrects or verifies travel documents,
 * writing an audit_log row for each change (SR-33, C2).
 */
class CorrectionService
{
    public function __construct(private CountryList $countries) {}

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

    /**
     * Document columns this service may correct, with the audit_log field name for each.
     *
     * @var array<string, string>
     */
    private const DOCUMENT_FIELDS = [
        'type' => 'document_type',
        'number' => 'document_number',
        'issuing_country' => 'document_country',
        'expiry_date' => 'document_expiry',
    ];

    /**
     * Corrects a travel document (C2) and writes one audit_log row per changed field.
     * A corrected document is no longer verified: an agent must check it again.
     *
     * @param  array<string, mixed>  $changes  e.g. ['number' => 'LR1234576']
     */
    public function correctDocument(TravelDocument $document, array $changes, User $by): void
    {
        DB::transaction(function () use ($document, $changes, $by) {
            $this->ensureDocumentsEditable($document->passenger_id);

            $fresh = $document->newQuery()->lockForUpdate()->findOrFail($document->id);
            $document->setRawAttributes($fresh->getAttributes(), true);

            $updates = [];

            foreach (self::DOCUMENT_FIELDS as $column => $auditField) {
                if (! array_key_exists($column, $changes)) {
                    continue;
                }

                // Compare plain values: enum value, Y-m-d date, string.
                $old = $column === 'expiry_date'
                    ? (string) $document->expiry_date?->toDateString()
                    : (string) $document->getRawOriginal($column);
                $new = (string) ($changes[$column] instanceof \BackedEnum ? $changes[$column]->value : $changes[$column]);

                if ($new === $old) {
                    continue;
                }

                AuditEntry::create([
                    'passenger_id' => $document->passenger_id,
                    'field' => $auditField,
                    'old_value' => $column === 'issuing_country' ? $this->countries->name($old) : $old,
                    'new_value' => $column === 'issuing_country' ? $this->countries->name($new) : $new,
                    'user_id' => $by->id,
                    'changed_at' => now(),
                ]);

                $updates[$column] = $new;
            }

            if ($updates === []) {
                return;
            }

            $document->update($updates + ['verified_by' => null, 'verified_at' => null]);
        });
    }

    /**
     * Adds a travel document and writes an audit_log row: field 'document_added',
     * new value = the document type (C2).
     *
     * @param  array<string, mixed>  $data  type, number, issuing_country, expiry_date
     */
    public function addDocument(Passenger $passenger, array $data, User $by): TravelDocument
    {
        return DB::transaction(function () use ($passenger, $data, $by) {
            $this->ensureDocumentsEditable($passenger->id);

            $document = $passenger->travelDocuments()->create($data);

            AuditEntry::create([
                'passenger_id' => $passenger->id,
                'field' => 'document_added',
                'old_value' => null,
                'new_value' => $document->type->value,
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);

            return $document;
        });
    }

    /**
     * Marks a travel document as checked by this agent, now (C2).
     */
    public function verifyDocument(TravelDocument $document, User $by): void
    {
        DB::transaction(function () use ($document, $by) {
            $this->ensureDocumentsEditable($document->passenger_id);

            $document->update([
                'verified_by' => $by->id,
                'verified_at' => now(),
            ]);
        });
    }

    /**
     * Only Booked and CheckedIn passengers (Passenger::documentsEditable()).
     * The passenger row is locked so its status cannot change meanwhile.
     */
    private function ensureDocumentsEditable(int $passengerId): void
    {
        $passenger = Passenger::query()->lockForUpdate()->findOrFail($passengerId);

        if (! $passenger->documentsEditable()) {
            throw new DocumentsLocked;
        }
    }
}
