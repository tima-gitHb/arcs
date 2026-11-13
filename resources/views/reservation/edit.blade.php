@php
    $fieldLabels = [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'status' => 'Status',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Correct name</h1>
    </x-slot>

    <div class="max-w-3xl space-y-6">
        <div class="rounded-lg bg-white p-6 shadow-sm">
            <p class="text-sm text-gray-500">
                {{ $passenger->reservation->pnr }}
                · {{ $passenger->reservation->flight->flight_no }}
                · {{ $passenger->type->label() }}
                · {{ $passenger->status->label() }}
            </p>

            <form method="POST" action="{{ route('reservation.update', $passenger) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                @csrf
                @method('PUT')

                <div>
                    <x-input-label for="first_name" value="First name" />
                    <x-text-input
                        id="first_name"
                        name="first_name"
                        type="text"
                        class="mt-1 block w-full"
                        :value="old('first_name', $passenger->first_name)"
                        required
                        maxlength="255"
                        autocomplete="off"
                    />
                    <x-input-error :messages="$errors->get('first_name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="last_name" value="Last name" />
                    <x-text-input
                        id="last_name"
                        name="last_name"
                        type="text"
                        class="mt-1 block w-full"
                        :value="old('last_name', $passenger->last_name)"
                        required
                        maxlength="255"
                        autocomplete="off"
                    />
                    <x-input-error :messages="$errors->get('last_name')" class="mt-2" />
                </div>

                <div class="sm:col-span-2">
                    <button type="submit" class="rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">
                        Save
                    </button>
                </div>
            </form>
        </div>

        <div class="rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-navy">History</h2>

            @forelse ($passenger->auditEntries as $entry)
                <div class="mt-4 border-t border-gray-100 pt-4 text-sm">
                    <p>
                        {{ $fieldLabels[$entry->field] ?? $entry->field }}
                        changed from {{ $entry->old_value ?? '—' }} to {{ $entry->new_value }}
                    </p>
                    <p class="mt-1 text-gray-500">
                        {{ $entry->user->name }}
                        · <x-station-time :at="$entry->changed_at" format="d M Y H:i" />
                    </p>
                </div>
            @empty
                <p class="mt-3 text-sm text-gray-600">No changes yet.</p>
            @endforelse
        </div>

        <a href="{{ route('reservation.search', ['flight' => $passenger->reservation->flight_id]) }}" class="inline-block text-sm font-medium text-navy hover:underline">
            Back to the flight
        </a>
    </div>
</x-app-layout>
