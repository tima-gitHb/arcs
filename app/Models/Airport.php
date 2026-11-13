<?php

namespace App\Models;

use App\Services\CountryList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Once;

/**
 * An airport of the network (B13a). Flights fly between airports; each staff member
 * works at one (users.airport_code, their station). The rows are created by the migration.
 */
class Airport extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'city',
        'country',
        'timezone',
        'min_connection_minutes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'min_connection_minutes' => 60,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_connection_minutes' => 'integer',
        ];
    }

    /**
     * Where timezoneOf() keeps the code => time zone map, on the current request.
     */
    private const ZONES_KEY = 'arcs.airport_timezones';

    protected static function booted(): void
    {
        // A new or changed airport is seen by timezoneOf() straight away.
        static::saved(fn () => request()->attributes->remove(self::ZONES_KEY));
        static::deleted(fn () => request()->attributes->remove(self::ZONES_KEY));
    }

    /**
     * The IANA time zone of an airport, or UTC for no airport. One query per request,
     * however many times it is asked (pages show many flights). Kept on the request, not
     * in once(): a request always costs the same number of queries, whatever ran before it.
     */
    public static function timezoneOf(?string $code): string
    {
        $attributes = request()->attributes;

        if (! $attributes->has(self::ZONES_KEY)) {
            $attributes->set(self::ZONES_KEY, static::query()->pluck('timezone', 'code')->all());
        }

        return $attributes->get(self::ZONES_KEY)[$code] ?? 'UTC';
    }

    public function countryName(): string
    {
        return app(CountryList::class)->name($this->country);
    }
}
