<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Audit log</h1>
    </x-slot>

    <div class="space-y-6">
        {{-- Filters: a GET form, so they show in the URL and the pagination links can keep them. --}}
        <div class="bg-white rounded-lg shadow-sm p-6 text-gray-700">
            <form method="GET" action="{{ route('manager.audit') }}" class="grid gap-4 sm:grid-cols-5 sm:items-start">
                <div>
                    <x-input-label for="flight_id" value="Flight" />
                    <select id="flight_id" name="flight_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="">All flights</option>
                        @foreach ($flights as $flight)
                            <option value="{{ $flight->id }}" @selected((string) request('flight_id') === (string) $flight->id)>
                                {{ $flight->flight_no }} — {{ $flight->departure_at->format('d M Y') }}
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
                    <select id="user_id" name="user_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
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
                    <a href="{{ route('manager.audit') }}" class="text-sm text-indigo-600 hover:underline">Clear</a>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-sm overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-2 font-medium">Time</th>
                        <th class="px-4 py-2 font-medium">Passenger</th>
                        <th class="px-4 py-2 font-medium">Field</th>
                        <th class="px-4 py-2 font-medium">Change</th>
                        <th class="px-4 py-2 font-medium">Staff member</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr class="border-b border-gray-100 last:border-0">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $entry->changed_at->format('d M Y H:i') }}</td>
                            @php
                                // A price change (fare or fee) belongs to no passenger and no flight. It is told
                                // by its field ("fare Business Adult", "fee ExtraBag"), not by what is missing.
                                $isPrice = str_starts_with($entry->field, 'fare ') || str_starts_with($entry->field, 'fee ');
                            @endphp
                            {{-- data-audit-subject says what the row is about: fares, passenger or flight. --}}
                            <td class="px-4 py-2" data-audit-subject="{{ $isPrice ? 'fares' : ($entry->passenger ? 'passenger' : 'flight') }}">
                                {{-- A row about a flight has no passenger. --}}
                                @if ($isPrice)
                                    Fares and fees
                                @elseif ($entry->passenger)
                                    {{ $entry->passenger->first_name }} {{ $entry->passenger->last_name }}
                                @else
                                    Flight {{ $entry->flight?->flight_no ?? '—' }}
                                @endif
                            </td>
                            <td class="px-4 py-2">{{ $entry->field }}</td>
                            {{-- A new value has no old one (e.g. a document added): show a dash. --}}
                            <td class="px-4 py-2">{{ $entry->old_value ?? '—' }} → {{ $entry->new_value }}</td>
                            <td class="px-4 py-2">{{ $entry->user->name }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-gray-500">No entries match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- The controller used withQueryString(), so these links keep the filters. --}}
        {{ $entries->links() }}
    </div>
</x-app-layout>
