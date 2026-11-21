<x-app-layout>
    <x-page-header title="Audit log" subtitle="Who changed a passenger, a flight, a fare, or an airport, and when." />

    <div class="space-y-6">
        <x-manager-station :station="$station" />

        {{-- Filters: a GET form, so they show in the URL and the pagination links can keep them. --}}
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
                    <x-input-error :messages="$errors->get('flight_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="passenger" value="Passenger or PNR" />
                    <x-text-input id="passenger" name="passenger" type="text" class="mt-1 block w-full" :value="request('passenger')" />
                    <x-input-error :messages="$errors->get('passenger')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="user_id" value="Staff member" />
                    <select id="user_id" name="user_id" class="arcs-input mt-1 block w-full">
                        <option value="">All staff</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}" @selected((string) request('user_id') === (string) $member->id)>
                                {{ $member->name }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('user_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="date" value="Date" />
                    <x-text-input id="date" name="date" type="date" class="mt-1 block w-full" :value="request('date')" />
                    <x-input-error :messages="$errors->get('date')" class="mt-2" />
                </div>

                <div class="flex items-center gap-3 sm:pt-6">
                    <x-primary-button>Filter</x-primary-button>
                    <a href="{{ route('manager.audit') }}" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Clear</a>
                </div>
            </form>
        </x-panel>

        <x-panel title="Entries">
            <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Passenger</th>
                            <th>Field</th>
                            <th>Change</th>
                            <th>Staff member</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entries as $entry)
                            <tr>
                                <td class="whitespace-nowrap"><x-station-time :at="$entry->changed_at" format="d M Y H:i" /></td>
                                @php
                                    // A price change (fare or fee) and an airport change belong to no passenger and no flight.
                                    // They are told by the field ("fare Business Adult", "airport CDG minutes"), not by what is missing.
                                    $isAirport = str_starts_with($entry->field, 'airport ');
                                    $isPrice = str_starts_with($entry->field, 'fare ') || str_starts_with($entry->field, 'fee ');

                                    // An empty gate is stored as ''. A flight status is stored as its enum value.
                                    $auditValue = function (?string $value) use ($entry): string {
                                        if ($value === null || $value === '') {
                                            return '—';
                                        }

                                        if ($entry->field === 'flight_status') {
                                            return \App\Enums\FlightStatus::from($value)->label();
                                        }

                                        return $value;
                                    };
                                @endphp
                                {{-- data-audit-subject says what the row is about: airports, fares, passenger or flight. --}}
                                <td data-audit-subject="{{ $isAirport ? 'airports' : ($isPrice ? 'fares' : ($entry->passenger ? 'passenger' : 'flight')) }}">
                                    {{-- A row about a flight has no passenger. --}}
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
                                <td>{{ $entry->field }}</td>
                                {{-- A new value has no old one (e.g. a document added): show a dash. --}}
                                <td>{{ $auditValue($entry->old_value) }} → {{ $auditValue($entry->new_value) }}</td>
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

        {{-- The controller used withQueryString(), so these links keep the filters. --}}
        {{ $entries->links() }}
    </div>
</x-app-layout>
