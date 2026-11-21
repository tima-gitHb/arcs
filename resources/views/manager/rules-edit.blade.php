@php
    $titles = [
        'passengers' => 'Passengers',
        'baggage' => 'Baggage',
        'fleet' => 'Fleet and flights',
        'checkin' => 'Check-in and gate',
    ];
@endphp
<x-app-layout>
    <x-page-header :title="'Edit · '.$titles[$group]" subtitle="Saving updates every rule the system stores on this page.">
        <x-slot:breadcrumb>
            <a href="{{ route('manager.rules') }}" class="hover:underline">Rules and allowances</a>
            <span class="text-gray-300">›</span>
            <span>{{ $titles[$group] }}</span>
        </x-slot:breadcrumb>
    </x-page-header>

    <x-panel>
        <form method="POST" action="{{ route('manager.rules.update') }}" class="space-y-8">
            @csrf
            @method('PUT')

            {{-- UpdateRulesRequest requires the full set; keep other groups as hidden current values. --}}
            @if ($group !== 'passengers' && $group !== 'checkin')
                <input type="hidden" name="passport_min_months" value="{{ old('passport_min_months', $passportMinMonths) }}">
            @endif

            @if ($group === 'passengers' || $group === 'checkin')
                <section class="space-y-2">
                    <h2 class="text-base font-semibold text-gray-900">Passport validity</h2>
                    <div>
                        <x-input-label for="passport_min_months" value="Minimum passport validity after departure" />
                        <div class="mt-1 flex items-center gap-2">
                            <x-text-input id="passport_min_months" name="passport_min_months" type="number" min="0" step="1" class="w-28"
                                :value="old('passport_min_months', $passportMinMonths)" required />
                            <span class="text-sm text-gray-600">months</span>
                        </div>
                        <x-input-error :messages="$errors->get('passport_min_months')" class="mt-2" />
                    </div>
                </section>
            @endif

            @if ($group === 'baggage' || $group === 'fleet')
                <section class="space-y-4">
                    <h2 class="text-base font-semibold text-gray-900">Baggage allowance per cabin class</h2>
                    @foreach (['economy' => 'Economy', 'premium' => 'Premium', 'business' => 'Business'] as $key => $label)
                        <div class="rounded-[14px] border p-4" style="border-color: var(--arcs-border);">
                            <h3 class="font-medium text-gray-800">{{ $label }}</h3>
                            <div class="mt-3 grid gap-6 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="{{ $key }}_pieces" value="Pieces allowed" />
                                    <div class="mt-1 flex items-center gap-2">
                                        <x-text-input id="{{ $key }}_pieces" name="allowances[{{ $key }}][pieces]" type="number" min="0" max="255" step="1" class="w-28"
                                            :value="old('allowances.'.$key.'.pieces', $allowances[$key]->pieces ?? '')" required />
                                        <span class="text-sm text-gray-600">pieces</span>
                                    </div>
                                    <x-input-error :messages="$errors->get('allowances.'.$key.'.pieces')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="{{ $key }}_max_kg" value="Maximum weight per bag" />
                                    <div class="mt-1 flex items-center gap-2">
                                        <x-text-input id="{{ $key }}_max_kg" name="allowances[{{ $key }}][max_kg_per_bag]" type="number" min="0" max="{{ $hardMaxKg }}" step="0.1" class="w-28"
                                            :value="old('allowances.'.$key.'.max_kg_per_bag', $allowances[$key]->max_kg_per_bag ?? '')" required />
                                        <span class="text-sm text-gray-600">kg per bag</span>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">max {{ $hardMaxKg }} kg per bag</p>
                                    <x-input-error :messages="$errors->get('allowances.'.$key.'.max_kg_per_bag')" class="mt-2" />
                                </div>
                            </div>
                        </div>
                    @endforeach
                </section>
            @else
                @foreach (['economy', 'premium', 'business'] as $key)
                    <input type="hidden" name="allowances[{{ $key }}][pieces]" value="{{ old('allowances.'.$key.'.pieces', $allowances[$key]->pieces ?? 0) }}">
                    <input type="hidden" name="allowances[{{ $key }}][max_kg_per_bag]" value="{{ old('allowances.'.$key.'.max_kg_per_bag', $allowances[$key]->max_kg_per_bag ?? 0) }}">
                @endforeach
            @endif

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('manager.rules') }}" class="arcs-btn-secondary">Cancel</a>
                <x-primary-button>Save rules</x-primary-button>
            </div>
        </form>
    </x-panel>
</x-app-layout>
