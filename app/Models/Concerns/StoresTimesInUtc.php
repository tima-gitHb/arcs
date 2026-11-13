<?php

namespace App\Models\Concerns;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Every date and time is stored in UTC (B13a). Laravel writes a Carbon as it reads, without
 * converting it, so a Paris time given to a model would be saved as if it were UTC. This turns
 * any date-time value into UTC before it is stored. Date-only columns (date of birth, expiry)
 * are left alone: they are calendar days, not instants.
 */
trait StoresTimesInUtc
{
    public function setAttribute($key, $value)
    {
        if ($value instanceof DateTimeInterface && ! $this->hasCast($key, ['date', 'immutable_date'])) {
            $value = Carbon::instance($value)->utc();
        }

        return parent::setAttribute($key, $value);
    }
}
