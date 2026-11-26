@props(['messages'])

@php
    use Illuminate\Support\Arr;

    $lines = array_values(array_filter(
        Arr::flatten(is_array($messages) || $messages instanceof \Traversable ? (array) $messages : [$messages]),
        fn ($line) => is_string($line) && $line !== '',
    ));
@endphp

@if ($lines !== [])
    <ul {{ $attributes->merge(['class' => 'text-sm text-red-600 space-y-1']) }}>
        @foreach ($lines as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
