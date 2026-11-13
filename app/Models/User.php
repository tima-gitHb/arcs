<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Models\Concerns\StoresTimesInUtc;
use App\Support\LocalTime;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, StoresTimesInUtc;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'airport_code',
        'active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'failed_logins' => 'integer',
            'locked' => 'boolean',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The station this staff member works at (B13a), or null.
     */
    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'airport_code', 'code');
    }

    /**
     * The time zone of the station: "today" and "when it happened" times follow it. UTC without a station.
     */
    public function timezone(): string
    {
        return Airport::timezoneOf($this->airport_code);
    }

    /**
     * A calendar day at this user's station (today by default) as two UTC instants, for whereBetween().
     *
     * @param  string|null  $date  Y-m-d
     * @return array{0: Carbon, 1: Carbon}
     */
    public function stationDay(?string $date = null): array
    {
        return LocalTime::dayInUtc($this->timezone(), $date);
    }

    /**
     * Check-in and gate staff work only on flights departing from their station (B13a).
     */
    public function worksAt(Flight $flight): bool
    {
        return $this->airport_code !== null && $flight->origin === $this->airport_code;
    }

    public function createdReservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'created_by');
    }

    public function auditEntries(): HasMany
    {
        return $this->hasMany(AuditEntry::class);
    }
}
