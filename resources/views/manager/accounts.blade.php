<x-app-layout>
    <x-page-header title="Staff accounts" subtitle="Create an account, set the station, and unlock or deactivate from the list." />

    <div class="space-y-6">
        <x-panel title="New account">
            <p class="text-sm text-gray-600">The person gets a welcome email with a link to choose their password.</p>

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
                    <select id="role" name="role" class="arcs-input mt-1 block w-full" required>
                        <option value="" disabled @selected(! old('role'))>Choose a role</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('role')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="airport_code" value="Station" />
                    <select id="airport_code" name="airport_code" class="arcs-input mt-1 block w-full">
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
        </x-panel>

        <x-panel title="Accounts">
            <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Station</th>
                            <th>State</th>
                            <th>Last login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr>
                                <td class="font-medium">
                                    {{ $user->name }}
                                    @if ($user->is(auth()->user()))
                                        <span class="font-normal text-gray-500">(you)</span>
                                    @endif
                                </td>
                                <td>{{ $user->email }}</td>
                                <td>{{ $user->role->label() }}</td>
                                <td>
                                    <p>{{ $user->airport_code ? $user->airport_code.' · '.$user->airport->city : '—' }}</p>
                                    <form method="POST" action="{{ route('manager.accounts.station', $user) }}" class="mt-2 flex flex-wrap items-center gap-2">
                                        @csrf
                                        @method('PATCH')
                                        <select name="airport_code" aria-label="Station for {{ $user->name }}" class="arcs-input w-full max-w-[14rem] text-sm">
                                            <option value="" @selected($user->airport_code === null)>—</option>
                                            @foreach ($airports as $airport)
                                                <option value="{{ $airport->code }}" @selected($user->airport_code === $airport->code)>{{ $airport->code }} · {{ $airport->city }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Save</button>
                                    </form>
                                </td>
                                <td>
                                    {{-- A locked account is shown as Locked even if it is also deactivated. --}}
                                    @if ($user->locked)
                                        <x-status-badge status="Failed" label="Locked" />
                                    @elseif (! $user->active)
                                        <x-status-badge label="Deactivated" />
                                    @else
                                        <x-status-badge status="Succeeded" label="Active" />
                                    @endif
                                </td>
                                <td>@if ($user->last_login_at)<x-station-time :at="$user->last_login_at" format="Y-m-d H:i" />@else Never @endif</td>
                                <td>
                                    <div class="flex flex-wrap gap-x-3 gap-y-1">
                                        @if ($user->locked)
                                            <form method="POST" action="{{ route('manager.accounts.unlock', $user) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Unlock</button>
                                            </form>
                                        @endif

                                        {{-- No button to deactivate yourself (the service refuses it too). --}}
                                        @if (! $user->active)
                                            <form method="POST" action="{{ route('manager.accounts.active', $user) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="active" value="1">
                                                <button type="submit" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Activate</button>
                                            </form>
                                        @elseif (! $user->is(auth()->user()))
                                            <form method="POST" action="{{ route('manager.accounts.active', $user) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="active" value="0">
                                                <button type="submit" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Deactivate</button>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{ route('manager.accounts.reset-link', $user) }}">
                                            @csrf
                                            <button type="submit" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Send reset link</button>
                                        </form>

                                        {{-- A welcome email is for someone who has never signed in. After that, use the reset link. --}}
                                        @if ($user->last_login_at === null)
                                            <form method="POST" action="{{ route('manager.accounts.resend-welcome', $user) }}">
                                                @csrf
                                                <button type="submit" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Resend welcome email</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-panel>
    </div>
</x-app-layout>
