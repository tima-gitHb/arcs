{{-- Manager sidebar groups + sub-items (UI4). Uses Role::menu() labels for the main links. --}}
@php
    $menu = auth()->user()->role->menu();
    $section = match (true) {
        request()->routeIs('manager.dashboard') => 'overview',
        request()->routeIs('manager.home') || request()->routeIs('manager.flight-lists.*') => 'lists',
        request()->routeIs('manager.flights*') || request()->routeIs('manager.aircraft*') => 'fleet',
        request()->routeIs('manager.airports*') => 'airports',
        request()->routeIs('manager.accounts*') => 'staff',
        request()->routeIs('manager.rules*') => 'rules',
        request()->routeIs('manager.fares*') || request()->routeIs('manager.trip-fares*') => 'fares',
        request()->routeIs('manager.sales*') => 'sales',
        request()->routeIs('manager.payments*') => 'payments',
        request()->routeIs('manager.audit*') => 'audit',
        default => null,
    };

    $link = function (string $label, string $routeName, bool $active, ?string $href = null) {
        $href ??= Route::has($routeName) ? route($routeName) : '#';
        $class = 'flex min-h-[44px] items-center rounded-full px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/40 '.($active ? 'font-medium' : 'text-white/75 hover:bg-white/5');
        $style = $active ? 'background: #353B4F' : '';

        return compact('label', 'href', 'class', 'style', 'active');
    };

    $groups = [
        'Overview' => [
            $link('Dashboard', 'manager.dashboard', $section === 'overview'),
        ],
        'Operations' => [
            $link('Flight lists', 'manager.home', $section === 'lists'),
            $link('Flights and aircraft', 'manager.flights', $section === 'fleet'),
            $link('Airports', 'manager.airports', $section === 'airports'),
        ],
        'People' => [
            $link('Staff accounts', 'manager.accounts', $section === 'staff'),
        ],
        'Pricing' => [
            $link('Rules and allowances', 'manager.rules', $section === 'rules'),
            $link('Fares and fees', 'manager.fares', $section === 'fares'),
        ],
        'Reports' => [
            $link('Sales per flight', 'manager.sales', $section === 'sales'),
            $link('Payments', 'manager.payments', $section === 'payments'),
            $link('Audit log', 'manager.audit', $section === 'audit'),
        ],
    ];

    $subs = [
        'fleet' => [
            $link('Flights', 'manager.flights', request()->routeIs('manager.flights') && ! request()->routeIs('manager.flights.create') && ! request()->routeIs('manager.flights.show')),
            $link('New flight', 'manager.flights.create', request()->routeIs('manager.flights.create')),
            $link('Aircraft fleet', 'manager.aircraft.index', request()->routeIs('manager.aircraft.index')),
            $link('New aircraft', 'manager.aircraft.create', request()->routeIs('manager.aircraft.create')),
        ],
        'staff' => [
            $link('Accounts', 'manager.accounts', request()->routeIs('manager.accounts') && ! request()->routeIs('manager.accounts.create') && ! request()->routeIs('manager.accounts.show')),
            $link('New account', 'manager.accounts.create', request()->routeIs('manager.accounts.create')),
        ],
        'fares' => [
            $link('Route fares', 'manager.fares', request()->routeIs('manager.fares') && request('tab', 'routes') === 'routes'),
            $link('Trip fares', 'manager.fares', (request()->routeIs('manager.fares') && request('tab') === 'trips') || request()->routeIs('manager.trip-fares.*'), route('manager.fares', ['tab' => 'trips'])),
            $link('Fees', 'manager.fares', request()->routeIs('manager.fares') && request('tab') === 'fees', route('manager.fares', ['tab' => 'fees'])),
        ],
    ];
@endphp

<nav class="space-y-4" data-manager-nav>
    @foreach ($groups as $group => $items)
        <div>
            <p class="mb-1.5 px-2 text-[10px] font-semibold uppercase tracking-wider text-white/35">{{ $group }}</p>
            <ul class="space-y-1 text-sm">
                @foreach ($items as $item)
                    <li>
                        <a href="{{ $item['href'] }}" class="{{ $item['class'] }}" style="{{ $item['style'] }}" {!! $item['active'] ? 'aria-current="page"' : '' !!}>
                            {{ $item['label'] }}
                        </a>
                        @if ($item['label'] === 'Flights and aircraft' && $section === 'fleet')
                            <ul class="mt-1 space-y-1 border-l border-white/10 pl-3" data-manager-subnav="fleet">
                                @foreach ($subs['fleet'] as $sub)
                                    <li>
                                        <a href="{{ $sub['href'] }}" class="{{ $sub['class'] }} !min-h-[36px] text-xs" style="{{ $sub['style'] }}" {!! $sub['active'] ? 'aria-current="page"' : '' !!}>{{ $sub['label'] }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($item['label'] === 'Staff accounts' && $section === 'staff')
                            <ul class="mt-1 space-y-1 border-l border-white/10 pl-3" data-manager-subnav="staff">
                                @foreach ($subs['staff'] as $sub)
                                    <li>
                                        <a href="{{ $sub['href'] }}" class="{{ $sub['class'] }} !min-h-[36px] text-xs" style="{{ $sub['style'] }}" {!! $sub['active'] ? 'aria-current="page"' : '' !!}>{{ $sub['label'] }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($item['label'] === 'Fares and fees' && $section === 'fares')
                            <ul class="mt-1 space-y-1 border-l border-white/10 pl-3" data-manager-subnav="fares">
                                @foreach ($subs['fares'] as $sub)
                                    <li>
                                        <a href="{{ $sub['href'] }}" class="{{ $sub['class'] }} !min-h-[36px] text-xs" style="{{ $sub['style'] }}" {!! $sub['active'] ? 'aria-current="page"' : '' !!}>{{ $sub['label'] }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
