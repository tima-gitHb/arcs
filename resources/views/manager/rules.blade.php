<x-app-layout>
    <x-page-header title="Rules and allowances" subtitle="Passport validity and baggage allowance by cabin. Edit one group at a time.">
        <x-slot:breadcrumb>
            <span>Rules and allowances</span>
        </x-slot:breadcrumb>
    </x-page-header>

    @if (session('status'))
        <div class="mb-6 rounded-lg border px-4 py-3 text-sm" style="border-color: #B7DFC4; background: var(--arcs-success-bg); color: var(--arcs-success);" role="status">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-gray-900">Passengers</h2>
                    <p class="mt-2 text-sm text-gray-600">Passport must stay valid for {{ $passportMinMonths ?? '—' }} months after departure.</p>
                </div>
                <a href="{{ route('manager.rules.edit', 'passengers') }}" class="arcs-btn-secondary">Edit</a>
            </div>
        </div>

        <div class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-gray-900">Baggage</h2>
                    <ul class="mt-2 space-y-1 text-sm text-gray-600">
                        @foreach (['economy' => 'Economy', 'premium' => 'Premium', 'business' => 'Business'] as $key => $label)
                            <li>{{ $label }}: {{ $allowances[$key]->pieces ?? '—' }} pcs · {{ $allowances[$key]->max_kg_per_bag ?? '—' }} kg</li>
                        @endforeach
                    </ul>
                </div>
                <a href="{{ route('manager.rules.edit', 'baggage') }}" class="arcs-btn-secondary">Edit</a>
            </div>
        </div>

        <div class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-gray-900">Fleet and flights</h2>
                    <p class="mt-2 text-sm text-gray-600">Hard max per bag {{ $hardMaxKg }} kg (read from settings).</p>
                </div>
                <a href="{{ route('manager.rules.edit', 'fleet') }}" class="arcs-btn-secondary">Edit</a>
            </div>
        </div>

        <div class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-gray-900">Check-in and gate</h2>
                    <p class="mt-2 text-sm text-gray-600">Passport and baggage rules apply at check-in and the gate.</p>
                </div>
                <a href="{{ route('manager.rules.edit', 'checkin') }}" class="arcs-btn-secondary">Edit</a>
            </div>
        </div>
    </div>
</x-app-layout>
