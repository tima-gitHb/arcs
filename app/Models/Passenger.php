<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Passenger extends Model
{
    use HasFactory, StoresTimesInUtc;

    /**
     * 'status', 'checked_in_at' and 'boarded_at' are deliberately not fillable:
     * they only change through StatusService (SR-26).
     *
     * @var list<string>
     */
    protected $fillable = [
        'reservation_id',
        'first_name',
        'last_name',
        'type',
        'cabin_class',
        'extra_bags',
        'extra_kg',
        'date_of_birth',
        'accompanying_adult_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PassengerStatus::class,
            'type' => PassengerType::class,
            'cabin_class' => CabinClass::class,
            'extra_bags' => 'integer',
            'extra_kg' => 'integer',
            'date_of_birth' => 'date',
            'checked_in_at' => 'datetime',
            'boarded_at' => 'datetime',
        ];
    }

    /**
     * Unset extras count as zero until the row is read back from the database.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'extra_bags' => 0,
        'extra_kg' => 0,
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function accompanyingAdult(): BelongsTo
    {
        return $this->belongsTo(Passenger::class, 'accompanying_adult_id');
    }

    public function infants(): HasMany
    {
        return $this->hasMany(Passenger::class, 'accompanying_adult_id');
    }

    public function bags(): HasMany
    {
        return $this->hasMany(Bag::class);
    }

    public function boardingPasses(): HasMany
    {
        return $this->hasMany(BoardingPass::class);
    }

    public function activeBoardingPass(): HasOne
    {
        return $this->hasOne(BoardingPass::class)->whereNull('voided_at');
    }

    public function specialServices(): HasMany
    {
        return $this->hasMany(SpecialService::class);
    }

    public function travelDocuments(): HasMany
    {
        return $this->hasMany(TravelDocument::class);
    }

    public function auditEntries(): HasMany
    {
        return $this->hasMany(AuditEntry::class);
    }

    public function seatAssignment(): HasOne
    {
        return $this->hasOne(SeatAssignment::class);
    }

    public function gateOverride(): HasOne
    {
        return $this->hasOne(GateOverride::class);
    }

    /**
     * Check-in details (documents, seat, adding bags) can change only while the passenger
     * is Booked or CheckedIn (C2–C4). Views use this to hide the forms; the services enforce it.
     */
    public function checkinDetailsEditable(): bool
    {
        return in_array($this->status, [PassengerStatus::Booked, PassengerStatus::CheckedIn], true);
    }

    /**
     * Travel documents (C2): same rule as the other check-in details.
     */
    public function documentsEditable(): bool
    {
        return $this->checkinDetailsEditable();
    }

    /*
     * "Who did it" (SR-45): the audit_log row of a status change, with ->user and ->changed_at,
     * or null if it never happened. Eager-load for lists: Passenger::with('checkedInBy.user').
     */

    public function checkedInBy(): HasOne
    {
        return $this->statusChangeTo(PassengerStatus::CheckedIn);
    }

    public function boardedBy(): HasOne
    {
        return $this->statusChangeTo(PassengerStatus::Boarded);
    }

    public function cancelledBy(): HasOne
    {
        return $this->statusChangeTo(PassengerStatus::Cancelled);
    }

    public function markedNoShowBy(): HasOne
    {
        return $this->statusChangeTo(PassengerStatus::NoShow);
    }

    /**
     * The latest audit_log row where this passenger's status became $status.
     */
    private function statusChangeTo(PassengerStatus $status): HasOne
    {
        return $this->hasOne(AuditEntry::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('field', 'status')->where('new_value', $status->value),
        );
    }
}
