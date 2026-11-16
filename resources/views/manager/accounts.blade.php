<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Staff accounts</h1>
    </x-slot>

    <div class="space-y-6">
        <div class="bg-white rounded-lg shadow-sm p-6 text-gray-700">
            <h2 class="text-lg font-semibold text-navy">New account</h2>
            <p class="mt-1 text-sm text-gray-600">The person gets a welcome email with a link to choose their password.</p>

            <form method="POST" action="{{ route('manager.accounts.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5 sm:items-start">
                @csrf

                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="role" value="Role" />
                    <select id="role" name="role" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                        <option value="" disabled @selected(! old('role'))>Choose a role</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('role')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="airport_code" value="Station" />
                    <select id="airport_code" name="airport_code" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="" @selected(old('airport_code') === null)>—</option>
                        @foreach ($airports as $airport)
                            <option value="{{ $airport->code }}" @selected(old('airport_code') === $airport->code)>{{ $airport->code }} · {{ $airport->city }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-sm text-gray-600">Required for check-in and gate staff</p>
                    <x-input-error :messages="$errors->get('airport_code')" class="mt-2" />
                </div>

                <div class="sm:pt-6">
                    <x-primary-button>Create account</x-primary-button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-sm overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Email</th>
                        <th class="px-4 py-2 font-medium">Role</th>
                        <th class="px-4 py-2 font-medium">Station</th>
                        <th class="px-4 py-2 font-medium">State</th>
                        <th class="px-4 py-2 font-medium">Last login</th>
                        <th class="px-4 py-2 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr class="border-b border-gray-100 last:border-0">
                            <td class="px-4 py-2 font-medium">
                                {{ $user->name }}
                                @if ($user->is(auth()->user()))
                                    <span class="font-normal text-gray-500">(you)</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">{{ $user->email }}</td>
                            <td class="px-4 py-2">{{ $user->role->label() }}</td>
                            <td class="px-4 py-2">
                                <p>{{ $user->airport_code ? $user->airport_code.' · '.$user->airport->city : '—' }}</p>
                                <form method="POST" action="{{ route('manager.accounts.station', $user) }}" class="mt-2 flex flex-wrap items-center gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <select name="airport_code" aria-label="Station for {{ $user->name }}" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                                        <option value="" @selected($user->airport_code === null)>—</option>
                                        @foreach ($airports as $airport)
                                            <option value="{{ $airport->code }}" @selected($user->airport_code === $airport->code)>{{ $airport->code }} · {{ $airport->city }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="text-indigo-600 hover:underline">Save</button>
                                </form>
                            </td>
                            <td class="px-4 py-2">
                                {{-- A locked account is shown as Locked even if it is also deactivated. --}}
                                @if ($user->locked)
                                    <span class="text-red-600">Locked</span>
                                @elseif (! $user->active)
                                    <span class="text-gray-500">Deactivated</span>
                                @else
                                    <span class="text-green-700">Active</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">@if ($user->last_login_at)<x-station-time :at="$user->last_login_at" format="Y-m-d H:i" />@else Never @endif</td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap gap-2">
                                    @if ($user->locked)
                                        <form method="POST" action="{{ route('manager.accounts.unlock', $user) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="text-indigo-600 hover:underline">Unlock</button>
                                        </form>
                                    @endif

                                    {{-- No button to deactivate yourself (the service refuses it too). --}}
                                    @if (! $user->active)
                                        <form method="POST" action="{{ route('manager.accounts.active', $user) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="active" value="1">
                                            <button type="submit" class="text-indigo-600 hover:underline">Activate</button>
                                        </form>
                                    @elseif (! $user->is(auth()->user()))
                                        <form method="POST" action="{{ route('manager.accounts.active', $user) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="active" value="0">
                                            <button type="submit" class="text-indigo-600 hover:underline">Deactivate</button>
                                        </form>
                                    @endif

                                    <form method="POST" action="{{ route('manager.accounts.reset-link', $user) }}">
                                        @csrf
                                        <button type="submit" class="text-indigo-600 hover:underline">Send reset link</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
