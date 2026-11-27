@php
    $originTz = \App\Models\Airport::timezoneOf($schedule->origin);
    $defaultFrom = now($originTz)->toDateString();
    $defaultTo = now($originTz)->addWeeks(8)->toDateString();
@endphp
<x-app-layout>
    <x-page-header :title="$schedule->flight_no" :subtitle="$schedule->origin.' → '.$schedule->destination.' · weekly schedule'">
        <x-slot:breadcrumb>
            <span class="text-gray-400">Flights and aircraft</span>
            <span class="text-gray-300">›</span>
            <a href="{{ route('manager.schedules.index') }}" class="hover:underline">Schedules</a>
            <span class="text-gray-300">›</span>
            <span class="arcs-mono">{{ $schedule->flight_no }}</span>
        </x-slot:breadcrumb>
        <a href="{{ route('manager.schedules.edit', $schedule) }}" class="arcs-btn-secondary">Edit</a>
    </x-page-header>

    <div class="space-y-6">
        <div class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-lg font-bold arcs-mono">{{ $schedule->flight_no }}</p>
                    <p class="text-sm text-gray-600">{{ $schedule->origin }} → {{ $schedule->destination }} · {{ $schedule->departureHm() }} local · {{ $schedule->block_minutes }} min block</p>
                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach ($schedule->dayLabels() as $day)
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" style="background: #EEF0F4;">{{ $day }}</span>
                        @endforeach
                    </div>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="background: {{ $schedule->active ? '#E8F0FE' : '#EEF0F4' }};">
                    {{ $schedule->active ? 'Active' : 'Inactive' }}
                </span>
            </div>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-gray-500">Aircraft</dt>
                    <dd class="arcs-mono font-medium">{{ $schedule->aircraft?->registration ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Gate</dt>
                    <dd>{{ $schedule->gate ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Validity</dt>
                    <dd>{{ $schedule->valid_from->format('d M Y') }} – {{ $schedule->valid_to->format('d M Y') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Check-in</dt>
                    <dd>Opens {{ $schedule->checkin_opens_minutes_before }} min before · closes {{ $schedule->checkin_closes_minutes_before }} min before</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Generated flights</dt>
                    <dd class="arcs-mono">{{ $schedule->flights->count() }}</dd>
                </div>
            </dl>
        </div>

        @if ($bookedLeft->isNotEmpty())
            <x-panel title="Booked flights left unchanged">
                <p class="mb-3 text-sm text-gray-600">These future flights already have bookings. Handle them manually.</p>
                <ul class="space-y-1 text-sm">
                    @foreach ($bookedLeft as $flight)
                        <li>
                            <a href="{{ route('manager.flights.show', $flight) }}" class="font-medium hover:underline arcs-mono" style="color: var(--arcs-accent-text);">
                                {{ $flight->flight_no }}
                            </a>
                            · <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M Y H:i" />
                            · {{ $flight->coupons_count }} booking(s)
                        </li>
                    @endforeach
                </ul>
            </x-panel>
        @endif

        <x-panel title="Generate flights">
            <form method="POST" action="{{ route('manager.schedules.generate', $schedule) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <x-input-label for="from" value="From" />
                    <input type="date" id="from" name="from" value="{{ old('from', $defaultFrom) }}" class="arcs-input mt-1 block w-44" @disabled(! $schedule->active)>
                </div>
                <div>
                    <x-input-label for="to" value="To" />
                    <input type="date" id="to" name="to" value="{{ old('to', $defaultTo) }}" class="arcs-input mt-1 block w-44" @disabled(! $schedule->active)>
                </div>
                @if ($schedule->active)
                    <x-primary-button>Generate flights</x-primary-button>
                @else
                    <button type="button" class="arcs-btn-primary opacity-50" disabled>Generate flights</button>
                @endif
            </form>
            @unless ($schedule->active)
                <p class="mt-2 text-sm text-gray-500">Activate the schedule to generate new flights. Existing flights stay.</p>
            @endunless

            @if ($generateResult)
                <div class="mt-4 rounded-xl px-4 py-3 text-sm" style="background: #F4F6FA;">
                    <p class="font-medium">
                        Created {{ $generateResult['created'] }}
                        · skipped (already exist) {{ $generateResult['skipped'] }}
                        · clash {{ $generateResult['clashes'] }}
                    </p>
                    @if (! empty($generateResult['created_dates']))
                        <p class="mt-1 text-gray-600">Created: {{ implode(', ', $generateResult['created_dates']) }}</p>
                    @endif
                    @if (! empty($generateResult['skipped_dates']))
                        <p class="mt-1 text-gray-600">Skipped: {{ implode(', ', $generateResult['skipped_dates']) }}</p>
                    @endif
                    @if (! empty($generateResult['clash_dates']))
                        <ul class="mt-1 text-gray-600">
                            @foreach ($generateResult['clash_dates'] as $clash)
                                <li>Clash {{ $clash['date'] }} with {{ $clash['with'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </x-panel>

        <x-panel title="Generated flights">
            <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Departure</th>
                            <th>Status</th>
                            <th>Bookings</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($schedule->flights as $flight)
                            <tr>
                                <td class="arcs-mono">{{ $flight->departureLocal()->toDateString() }}</td>
                                <td><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" /></td>
                                <td><x-status-badge :status="$flight->status" /></td>
                                <td class="arcs-mono">{{ $flight->coupons_count }}</td>
                                <td class="text-right">
                                    <a href="{{ route('manager.flights.show', $flight) }}" class="mr-3 text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Open</a>
                                    @if ($flight->status !== \App\Enums\FlightStatus::Cancelled)
                                        <form method="POST" action="{{ route('manager.schedules.cancel-date', [$schedule, $flight]) }}" class="inline" onsubmit="return confirm('Cancel this date only?')">
                                            @csrf
                                            <button type="submit" class="text-sm font-medium text-red-600 hover:underline">Cancel this date</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-empty-state message="No flights generated from this schedule yet." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>
    </div>
</x-app-layout>
