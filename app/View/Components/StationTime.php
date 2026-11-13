<?php

namespace App\View\Components;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\View\Component;

/**
 * When something happened (audit log, payments, check-in by...), in the viewer's station time (B13a).
 * A viewer with no station (a Manager, say) sees UTC, labelled "UTC".
 *     <x-station-time :at="$entry->changed_at" />
 * Pass :user to use someone else's station, e.g. in an email where nobody is looking at a page.
 */
class StationTime extends Component
{
    public ?Carbon $local;

    public bool $isUtc;

    public function __construct(?DateTimeInterface $at, public string $format = 'd M Y H:i', ?User $user = null)
    {
        $user ??= auth()->user();
        $this->isUtc = $user?->airport_code === null;
        $this->local = $at === null ? null : Carbon::instance($at)->setTimezone($user?->timezone() ?? 'UTC');
    }

    /**
     * Plain text, no markup: it sits inside sentences ("Checked in by Maya at 10:00").
     */
    public function render(): string
    {
        return <<<'blade'
            @if ($local){{ $local->format($format) }}{{ $isUtc ? ' UTC' : '' }}@else—@endif
            blade;
    }
}
