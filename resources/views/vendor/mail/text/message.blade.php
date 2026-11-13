{{-- The ARCS email layout, plain-text part (B13c). Same header and footer as html/message.blade.php. --}}
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        ARCS · Airline Reservation & Check-in System
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        Sent by ARCS to airline staff. Please do not reply to this email.
    </x-slot:footer>
</x-mail::layout>
