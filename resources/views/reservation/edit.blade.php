@php
    $fieldLabels = [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'status' => 'Status',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Correct name" subtitle="{{ $passenger->reservation->pnr }} · {{ $passenger->reservation->flight->flight_no }} · {{ $passenger->type->label() }}">
            <a href="{{ route('reservation.search', ['flight' => $passenger->reservation->flight_id]) }}" class="arcs-btn-secondary">Back to the flight</a>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-6">
        <x-panel title="Passenger">
            <p class="mb-4 text-sm text-[var(--arcs-muted)]">
                {{ $passenger->reservation->pnr }}
                · {{ $passenger->reservation->flight->flight_no }}
                · {{ $passenger->type->label() }}
                · <x-status-badge :status="$passenger->status" />
            </p>

            <form method="POST" action="{{ route('reservation.update', $passenger) }}" class="grid gap-4 sm:grid-cols-2">
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
                    <button type="submit" class="arcs-btn-primary">Save</button>
                </div>
            </form>
        </x-panel>

        <x-panel title="History">
            @forelse ($passenger->auditEntries as $entry)
                <div class="mt-4 border-t border-[var(--arcs-border)] pt-4 text-sm first:mt-0 first:border-t-0 first:pt-0">
                    <p>
                        {{ $fieldLabels[$entry->field] ?? $entry->field }}
                        changed from {{ $entry->old_value ?? '—' }} to {{ $entry->new_value }}
                    </p>
                    <p class="mt-1 text-[var(--arcs-muted)]">
                        {{ $entry->user->name }}
                        · <x-station-time :at="$entry->changed_at" format="d M Y H:i" />
                    </p>
                </div>
            @empty
                <p class="text-sm text-[var(--arcs-muted)]">No changes yet.</p>
            @endforelse
        </x-panel>

        <a href="{{ route('reservation.search', ['flight' => $passenger->reservation->flight_id]) }}" class="inline-block text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">
            Back to the flight
        </a>
    </div>
</x-app-layout>
