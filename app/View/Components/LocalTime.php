<?php

namespace App\View\Components;

use App\Support\LocalTime as Clock;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\View\Component;

/**
 * A flight time in an airport's local time (B13a). Times are stored in UTC; never format a
 * flight time directly. Departure and check-in times use the origin airport:
 *     <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" />
 * A null time shows a dash.
 */
class LocalTime extends Component
{
    public ?Carbon $local;

    public function __construct(
        ?DateTimeInterface $at,
        public ?string $airport = null,
        public string $format = 'd M Y H:i',
    ) {
        $this->local = Clock::at($at, $airport);
    }

    public function utc(): string
    {
        return $this->local->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public function render(): string
    {
        return <<<'blade'
            @if ($local)<time datetime="{{ $utc() }}" title="Local time at {{ $airport ?? 'UTC' }}">{{ $local->format($format) }}</time>@else—@endif
            blade;
    }
}
