@php
    $panelOpen = $selected !== null;
@endphp
<x-app-layout>
    <x-page-header title="Audit log" subtitle="Who changed a passenger, a flight, a fare, or an airport, and when.">
        <x-slot:breadcrumb>
            <span>Audit log</span>
        </x-slot:breadcrumb>
    </x-page-header>

    <div class="space-y-6" x-data="{ open: {{ $panelOpen ? 'true' : 'false' }}, entryId: {{ $selected?->id ? (int) $selected->id : 'null' }} }">
        <x-panel title="Filters">
            <form method="GET" action="{{ route('manager.audit') }}" class="grid gap-4 sm:grid-cols-5 sm:items-start">
                <div>
                    <x-input-label for="flight_id" value="Flight" />
                    <select id="flight_id" name="flight_id" class="arcs-input mt-1 block w-full">
                        <option value="">All flights</option>
                        @foreach ($flights as $flight)
                            <option value="{{ $flight->id }}" @selected((string) request('flight_id') === (string) $flight->id)>
                                {{ $flight->flight_no }} — {{ $flight->departureLocal()->format('d M Y') }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="passenger" value="Passenger or PNR" />
                    <x-text-input id="passenger" name="passenger" type="text" class="mt-1 block w-full" :value="request('passenger')" />
                </div>
                <div>
                    <x-input-label for="user_id" value="Staff member" />
                    <select id="user_id" name="user_id" class="arcs-input mt-1 block w-full">
                        <option value="">All staff</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}" @selected((string) request('user_id') === (string) $member->id)>{{ $member->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="date" value="Date" />
                    <x-text-input id="date" name="date" type="date" class="mt-1 block w-full" :value="request('date')" />
                </div>
                <div class="flex items-center gap-3 sm:pt-6">
                    <x-primary-button>Filter</x-primary-button>
                    <a href="{{ route('manager.audit') }}" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Clear</a>
                </div>
            </form>
        </x-panel>

        <div class="grid gap-6" :class="open ? 'xl:grid-cols-[1fr_22rem]' : ''">
            <x-panel title="Entries">
                <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                    <table class="arcs-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>What</th>
                                <th>Passenger</th>
                                <th>Change</th>
                                <th>Staff</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($entries as $entry)
                                @php
                                    $isAirport = str_starts_with($entry->field, 'airport ');
                                    $isPrice = str_starts_with($entry->field, 'fare ') || str_starts_with($entry->field, 'fee ');
                                    $auditValue = function (?string $value) use ($entry): string {
                                        if ($value === null || $value === '') {
                                            return '—';
                                        }
                                        if ($entry->field === 'flight_status') {
                                            return \App\Enums\FlightStatus::from($value)->label();
                                        }
                                        return $value;
                                    };
                                    $chip = $isAirport ? 'Airports' : ($isPrice ? 'Fares' : ($entry->passenger ? 'Passenger' : 'Flight'));
                                    $chipBg = $isAirport ? '#E8F0FE' : ($isPrice ? '#FEF3C7' : ($entry->passenger ? '#E6F4EA' : '#EEF0F4'));
                                @endphp
                                <tr class="cursor-pointer hover:bg-gray-50" data-entry-id="{{ $entry->id }}"
                                    onclick="window.location='{{ route('manager.audit', array_merge(request()->query(), ['entry' => $entry->id])) }}'">
                                    <td class="whitespace-nowrap"><x-station-time :at="$entry->changed_at" format="d M Y H:i" /></td>
                                    <td>
                                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" style="background: {{ $chipBg }};">{{ $chip }}</span>
                                    </td>
                                    <td data-audit-subject="{{ $isAirport ? 'airports' : ($isPrice ? 'fares' : ($entry->passenger ? 'passenger' : 'flight')) }}">
                                        @if ($isAirport)
                                            Airports
                                        @elseif ($isPrice)
                                            Fares and fees
                                        @elseif ($entry->passenger)
                                            {{ $entry->passenger->first_name }} {{ $entry->passenger->last_name }}
                                        @else
                                            Flight {{ $entry->flight?->flight_no ?? '—' }}
                                        @endif
                                    </td>
                                    <td>
                                        <span class="text-gray-500">{{ $entry->field }}</span>
                                        <span class="block">{{ $auditValue($entry->old_value) }} → {{ $auditValue($entry->new_value) }}</span>
                                    </td>
                                    <td>{{ $entry->user->name }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-gray-500">No entries match these filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-panel>

            <aside x-show="open" x-cloak class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);" data-audit-panel>
                @if ($selected)
                    @php
                        $auditValue = function (?string $value) use ($selected): string {
                            if ($value === null || $value === '') {
                                return '—';
                            }
                            if ($selected->field === 'flight_status') {
                                return \App\Enums\FlightStatus::from($value)->label();
                            }
                            return $value;
                        };
                    @endphp
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="font-semibold text-gray-900">Entry detail</h2>
                        <a href="{{ route('manager.audit', request()->except('entry')) }}" class="text-sm text-gray-500 hover:underline">Close</a>
                    </div>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="text-gray-500">Field</dt>
                            <dd class="font-medium">{{ $selected->field }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Before</dt>
                            <dd class="font-medium" data-audit-before>{{ $auditValue($selected->old_value) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">After</dt>
                            <dd class="font-medium" data-audit-after>{{ $auditValue($selected->new_value) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Booking</dt>
                            <dd>{{ $selected->passenger?->reservation?->pnr ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Flight</dt>
                            <dd class="arcs-mono">{{ $selected->flight?->flight_no ?? $selected->passenger?->reservation?->flight?->flight_no ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Staff</dt>
                            <dd>{{ $selected->user->name }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-gray-500">Select a row to see before and after.</p>
                @endif
            </aside>
        </div>

        {{ $entries->links() }}
    </div>
</x-app-layout>
