@props(['tone' => 'slate', 'label' => ''])

{{--
    Tone comes from the enums (DocumentStatus, RouteStatus, DocumentEvent,
    Confidentiality) so one status looks the same everywhere it appears.

    The class strings are spelled out rather than interpolated because Tailwind
    scans the source for literal names — "bg-{{ $tone }}-100" would compile to
    nothing and the badge would render unstyled.
--}}
@php
    $classes = match ($tone) {
        'green' => 'bg-green-100 text-green-800',
        'amber' => 'bg-amber-100 text-amber-800',
        'blue' => 'bg-blue-100 text-blue-800',
        'red' => 'bg-red-100 text-red-800',
        default => 'bg-slate-100 text-slate-700',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium', $classes]) }}>
    {{ $label ?: $slot }}
</span>
