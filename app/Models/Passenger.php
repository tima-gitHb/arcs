<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Passenger extends Model
{
    use HasFactory, StoresTimesInUtc;

    /**
     * Status, check-in and boarding times live on the passenger's coupons (B13b) and change only
     * through StatusService (SR-26). $passenger->status, ->checked_in_at and ->boarded_at read the
     * passenger's current coupon: the first one, unless a page loaded the coupon of its own flight
     * (ManifestService does). For a one-flight reservation that is the only coupon, as before B13b.
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
            'type' => PassengerType::class,
            'cabin_class' => CabinClass::class,
            'extra_bags' => 'integer',
            'extra_kg' => 'integer',
            'date_of_birth' => 'date',
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

    /**
     * The current coupon is read by the status accessors on every page: always loaded with the passenger.
     *
     * @var list<string>
     */
    protected $with = ['coupon'];

    protected static function booted(): void
    {
        // A passenger travels on every segment of the reservation: one coupon each (B13b).
        static::created(function (Passenger $passenger) {
            $segments = ReservationSegment::query()
                ->where('reservation_id', $passenger->reservation_id)
                ->orderBy('sequence')
                ->get();

            foreach ($segments as $segment) {
                $passenger->coupons()->create([
                    'reservation_segment_id' => $segment->id,
                    'flight_id' => $segment->flight_id,
                ]);
            }
        });
    }

    /**
     * Every coupon of the journey, in journey order.
     */
    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class)->orderBy('coupons.reservation_segment_id');
    }

    /**
     * The current coupon: the first of the journey, unless eager-loaded for one flight, e.g.
     * Passenger::with(['coupon' => fn ($q) => $q->where('coupons.flight_id', $flight->id)]).
     */
    public function coupon(): HasOne
    {
        return $this->hasOne(Coupon::class)->orderBy('coupons.reservation_segment_id');
    }

    /**
     * The passenger's coupon on this flight, or null.
     */
    public function couponOn(Flight $flight): ?Coupon
    {
        if ($this->relationLoaded('coupon') && $this->coupon?->flight_id === $flight->id) {
            return $this->coupon;
        }

        return $this->coupons()->where('coupons.flight_id', $flight->id)->first();
    }

    protected function status(): Attribute
    {
        return Attribute::get(fn () => $this->coupon?->status ?? PassengerStatus::Booked);
    }

    protected function checkedInAt(): Attribute
    {
        return Attribute::get(fn () => $this->coupon?->checked_in_at);
    }

    protected function boardedAt(): Attribute
    {
        return Attribute::get(fn () => $this->coupon?->boarded_at);
    }

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

    /**
     * The active pass of the first flight that has one (one per coupon since B13b).
     */
    public function activeBoardingPass(): HasOne
    {
        return $this->hasOne(BoardingPass::class)->whereNull('voided_at')->orderBy('boarding_passes.coupon_id');
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

    /**
     * The seat on the first flight that has one (a seat per coupon since B13b). Pages about one flight
     * eager-load it for that flight: with(['seatAssignment' => fn ($q) => $q->where('flight_id', $id)]).
     */
    public function seatAssignment(): HasOne
    {
        return $this->hasOne(SeatAssignment::class)->orderBy('seat_assignments.coupon_id');
    }

    public function gateOverride(): HasOne
    {
        return $this->hasOne(GateOverride::class)->orderBy('gate_overrides.coupon_id');
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
