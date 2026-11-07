<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Rules and allowances</h1>
    </x-slot>

    <div class="bg-white rounded-lg shadow-sm p-6 text-gray-700">
        @if (session('status'))
            <div class="mb-6 rounded-md bg-green-50 p-4 text-sm text-green-700">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('manager.rules.update') }}" class="space-y-8">
            @csrf
            @method('PUT')

            <section class="space-y-2">
                <h2 class="text-lg font-semibold text-navy">Passport validity</h2>

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

            <section class="space-y-4">
                <h2 class="text-lg font-semibold text-navy">Baggage allowance per cabin class</h2>

                @foreach (['economy' => 'Economy', 'premium' => 'Premium', 'business' => 'Business'] as $key => $label)
                    <div class="rounded-md border border-gray-200 p-4">
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

            <div>
                <x-primary-button>Save rules</x-primary-button>
            </div>
        </form>
    </div>
</x-app-layout>
