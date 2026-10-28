<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'ARCS') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    @php
        $user = Auth::user();
        $role = $user->role;
        // Role badge colours from the design canvas.
        $badge = match ($role) {
            App\Enums\Role::Reservation => 'bg-[#DCE8F7] text-[#1B4F8A]',
            App\Enums\Role::Checkin => 'bg-[#D3EFEA] text-[#145A50]',
            App\Enums\Role::Gate => 'bg-white text-navy',
            App\Enums\Role::Manager => 'bg-[#FBE3D2] text-[#8A3F08]',
        };
    @endphp
    <body class="font-sans antialiased bg-page text-gray-900">
        <div class="min-h-screen flex flex-col">
            <!-- Header -->
            <header class="bg-navy text-white">
                <div class="px-4 sm:px-6 py-3 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <a href="{{ route($role->homeRoute()) }}" class="text-xl font-semibold tracking-wide">ARCS</a>
                        <span class="rounded-full px-3 py-0.5 text-xs font-semibold {{ $badge }}" data-role-badge>
                            {{ $role->label() }}
                        </span>
                    </div>

                    <div class="flex items-center gap-4 text-sm">
                        <span>{{ $user->name }}</span>
                        <a href="{{ route('profile.edit') }}" class="hover:underline">Profile</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="rounded border border-white/40 px-3 py-1 hover:bg-white/10">
                                Log out
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <!-- Flash messages -->
            @if (session('success'))
                <div class="bg-green-50 border-b border-green-200 text-green-800 px-4 sm:px-6 py-3 text-sm" role="status">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="bg-red-50 border-b border-red-200 text-red-800 px-4 sm:px-6 py-3 text-sm" role="alert">
                    {{ session('error') }}
                </div>
            @endif

            <div class="flex flex-1 flex-col md:flex-row">
                <!-- Side menu: items from Role::menu(); routes not built yet are greyed out. -->
                <nav class="w-full md:w-60 shrink-0 bg-white border-b md:border-b-0 md:border-r border-gray-200 p-3">
                    <ul class="space-y-1 text-sm">
                        @foreach ($role->menu() as $label => $routeName)
                            <li>
                                @if (Route::has($routeName))
                                    <a href="{{ route($routeName) }}"
                                       @class([
                                           'block rounded px-3 py-2',
                                           'bg-navy text-white font-medium' => request()->routeIs($routeName),
                                           'text-gray-700 hover:bg-gray-100' => ! request()->routeIs($routeName),
                                       ])
                                       @if (request()->routeIs($routeName)) aria-current="page" @endif>
                                        {{ $label }}
                                    </a>
                                @else
                                    <span class="block rounded px-3 py-2 text-gray-400 cursor-not-allowed" aria-disabled="true">
                                        {{ $label }}
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </nav>

                <!-- Page content -->
                <main class="flex-1 min-w-0 p-4 sm:p-6">
                    @isset($header)
                        <div class="mb-6">
                            {{ $header }}
                        </div>
                    @endisset

                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
