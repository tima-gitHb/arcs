<x-app-layout>
    <x-page-header title="Staff accounts" subtitle="Search by person, role or station, then open an account.">
        <x-slot:breadcrumb>
            <span class="text-gray-400">Staff accounts</span>
            <span class="text-gray-300">›</span>
            <span>Accounts</span>
        </x-slot:breadcrumb>
        <a href="{{ route('manager.accounts.create') }}" class="arcs-btn-primary">+ New account</a>
    </x-page-header>

    <div class="space-y-6">
        <x-panel>
            <form method="GET" action="{{ route('manager.accounts') }}" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[12rem] flex-1">
                    <x-input-label for="q" value="Search" />
                    <x-text-input id="q" name="q" type="search" class="mt-1 block w-full" :value="$filters['q']" placeholder="Name or email" />
                </div>
                <div>
                    <x-input-label for="airport_code" value="Station" />
                    <select id="airport_code" name="airport_code" class="arcs-input mt-1 block w-40">
                        <option value="">All stations</option>
                        @foreach ($airports as $airport)
                            <option value="{{ $airport->code }}" @selected($filters['airport_code'] === $airport->code)>{{ $airport->code }}</option>
                        @endforeach
                    </select>
                </div>
                <x-primary-button>Filter</x-primary-button>
            </form>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('manager.accounts', array_filter(['q' => $filters['q'] ?: null, 'airport_code' => $filters['airport_code'] ?: null])) }}"
                    class="rounded-full px-3 py-1.5 text-sm {{ $filters['role'] === '' ? 'font-semibold text-white' : 'text-gray-600' }}"
                    style="{{ $filters['role'] === '' ? 'background: var(--arcs-bar)' : 'background: #EEF0F4' }}">All roles</a>
                @foreach ($roles as $role)
                    <a href="{{ route('manager.accounts', array_filter(['q' => $filters['q'] ?: null, 'airport_code' => $filters['airport_code'] ?: null, 'role' => $role->value])) }}"
                        class="rounded-full px-3 py-1.5 text-sm {{ $filters['role'] === $role->value ? 'font-semibold text-white' : 'text-gray-600' }}"
                        style="{{ $filters['role'] === $role->value ? 'background: var(--arcs-bar)' : 'background: #EEF0F4' }}">{{ $role->label() }}</a>
                @endforeach
            </div>
        </x-panel>

        <x-panel>
            <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th>Role</th>
                            <th>Station</th>
                            <th>State</th>
                            <th>Last login</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $user)
                            @php
                                $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
                                $locked = (bool) ($user->locked ?? false);
                            @endphp
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-9 w-9 items-center justify-center rounded-full text-xs font-bold text-white" style="background: var(--arcs-bar);">{{ $initials }}</span>
                                        <span>
                                            <span class="block font-medium">{{ $user->name }}</span>
                                            <span class="block text-sm text-gray-500">{{ $user->email }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td>{{ $user->role->label() }}</td>
                                <td class="arcs-mono">
                                    @if ($user->airport)
                                        {{ $user->airport->code }} · {{ $user->airport->city }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if (! $user->active)
                                        Inactive
                                    @elseif ($locked)
                                        Locked
                                    @else
                                        Active
                                    @endif
                                </td>
                                <td>
                                    @if ($user->last_login_at)
                                        <x-station-time :at="$user->last_login_at" format="Y-m-d H:i" />
                                    @else
                                        Never
                                    @endif
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('manager.accounts.show', $user) }}" class="font-medium hover:underline" style="color: var(--arcs-accent-text);">Open →</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-empty-state message="No accounts match." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $users->links() }}</div>
        </x-panel>
    </div>
</x-app-layout>
