@props([
    'status' => null,
    'label' => null,
])

@php
    $text = $label;
    $key = null;

    if ($status instanceof \BackedEnum) {
        $text ??= method_exists($status, 'label') ? $status->label() : $status->value;
        $key = $status->value;
    } elseif (is_string($status)) {
        $text ??= $status;
        $key = $status;
    }

    $text ??= '—';
    $key = strtolower((string) $key);

    $style = match (true) {
        in_array($key, ['boarded', 'offloaded', 'succeeded', 'checkinopen', 'check-in open'], true) => 'background:#E0F2E7;color:var(--arcs-boarded);',
        in_array($key, ['checkedin', 'checked_in', 'boarding'], true) => 'background:var(--arcs-accent-soft);color:var(--arcs-accent-text);border:1px solid var(--arcs-accent-text);',
        in_array($key, ['booked', 'scheduled', 'delayed'], true) => 'background:var(--arcs-booked);color:#5C4810;',
        in_array($key, ['noshow', 'no_show', 'no-show', 'cancelled', 'closed', 'checkinclosed', 'departed', 'failed'], true) => 'background:#FCE8E6;color:var(--arcs-error);',
        default => 'background:#EEF0F4;color:#3A4156;',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold']) }} style="{{ $style }}">
    {{ $text }}
</span>
