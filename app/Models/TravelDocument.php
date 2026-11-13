<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\Role;
use App\Models\Concerns\StoresTimesInUtc;
use App\Services\CountryList;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelDocument extends Model
{
    use HasFactory, StoresTimesInUtc;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'passenger_id',
        'type',
        'number',
        'issuing_country',
        'expiry_date',
        'verified_by',
        'verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'expiry_date' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Staff read country names, never codes (SR-49).
     */
    public function issuingCountryName(): string
    {
        return app(CountryList::class)->name($this->issuing_country);
    }

    /**
     * The document number as this user may see it (SR-34): in full for check-in staff and
     * the Manager, otherwise only the last 4 characters (••••1234).
     * Use this wherever a document number is displayed.
     */
    public function maskedNumberFor(User $user): string
    {
        if (in_array($user->role, [Role::Checkin, Role::Manager], true)) {
            return $this->number;
        }

        return '••••'.substr($this->number, -4);
    }
}
