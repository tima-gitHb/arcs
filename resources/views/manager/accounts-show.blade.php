@php
    $locked = (bool) ($account->locked ?? false);
@endphp
<x-app-layout>
    <x-page-header :title="$account->name" :subtitle="$account->email.' · '.$account->role->label()">
        <x-slot:breadcrumb>
            <span class="text-gray-400">Staff accounts</span>
            <span class="text-gray-300">›</span>
            <a href="{{ route('manager.accounts') }}" class="hover:underline">Accounts</a>
            <span class="text-gray-300">›</span>
            <span>{{ $account->name }}</span>
        </x-slot:breadcrumb>
    </x-page-header>

    @if (session('temporary_password'))
        <x-panel title="Temporary password" class="mb-6">
            <p class="text-sm text-gray-700">Show this once to the new staff member. It will not be shown again.</p>
            <p class="mt-3 font-mono text-lg font-semibold tracking-wide text-navy" data-arcs-temporary-password>{{ session('temporary_password') }}</p>
        </x-panel>
    @endif

    <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
        <div class="space-y-6">
            <x-panel title="Account">
                <form method="POST" action="{{ route('manager.accounts.station', $account) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    @method('PATCH')
                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" type="text" class="mt-1 block w-full" :value="$account->name" disabled />
                    </div>
                    <div>
                        <x-input-label for="email" value="Email" />
                        <x-text-input id="email" type="email" class="mt-1 block w-full" :value="$account->email" disabled />
                    </div>
                    <div>
                        <x-input-label for="role" value="Role" />
                        <x-text-input id="role" type="text" class="mt-1 block w-full" :value="$account->role->label()" disabled />
                    </div>
                    <div>
                        <x-input-label for="airport_code" value="Station" />
                        <select id="airport_code" name="airport_code" class="arcs-input mt-1 block w-full">
                            <option value="" @selected($account->airport_code === null)>—</option>
                            @foreach ($airports as $airport)
                                <option value="{{ $airport->code }}" @selected($account->airport_code === $airport->code)>{{ $airport->code }} · {{ $airport->city }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-sm text-gray-600">Required for check-in and gate staff</p>
                        <x-input-error :messages="$errors->get('airport_code')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-primary-button>Save station</x-primary-button>
                    </div>
                </form>
            </x-panel>

            <x-panel title="Recent activity">
                <ul class="space-y-2 text-sm">
                    @forelse ($activity as $entry)
                        <li class="flex flex-wrap justify-between gap-2 border-b py-2 last:border-0" style="border-color: var(--arcs-border);">
                            <span>{{ $entry->field }}</span>
                            <span class="text-gray-500"><x-station-time :at="$entry->changed_at" format="d M Y H:i" /></span>
                        </li>
                    @empty
                        <li class="text-gray-500">No audit rows for this account yet.</li>
                    @endforelse
                </ul>
            </x-panel>
        </div>

        <div class="space-y-4">
            @if ($locked)
                <x-panel title="Unlock">
                    <form method="POST" action="{{ route('manager.accounts.unlock', $account) }}">
                        @csrf
                        @method('PATCH')
                        <x-primary-button>Unlock account</x-primary-button>
                    </form>
                </x-panel>
            @endif

            <x-panel title="Password">
                <div class="space-y-3">
                    <form method="POST" action="{{ route('manager.accounts.reset-link', $account) }}">
                        @csrf
                        <button type="submit" class="arcs-btn-secondary w-full">Send reset link</button>
                    </form>
                    @if ($account->last_login_at === null)
                        <form method="POST" action="{{ route('manager.accounts.resend-welcome', $account) }}">
                            @csrf
                            <button type="submit" class="arcs-btn-secondary w-full">Resend welcome email</button>
                        </form>
                    @endif
                </div>
            </x-panel>

            <div class="rounded-[22px] border p-5" style="border-color: #F5C2C0; background: #FCE8E6;">
                <h2 class="font-semibold" style="color: var(--arcs-error);">Deactivate</h2>
                <p class="mt-1 text-sm text-gray-700">The person will not be able to sign in.</p>
                <form method="POST" action="{{ route('manager.accounts.active', $account) }}" class="mt-4">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="active" value="{{ $account->active ? 0 : 1 }}">
                    <button type="submit" class="rounded-full px-4 py-2 text-sm font-semibold text-white" style="background: var(--arcs-error);">
                        {{ $account->active ? 'Deactivate account' : 'Activate account' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
