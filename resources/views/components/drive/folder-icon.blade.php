@props(['system' => false, 'size' => 'md'])

@php
    $box = $size === 'lg' ? 'size-12' : 'size-10';
    $icon = $size === 'lg' ? 'size-7' : 'size-6';
    [$bg, $fg] = $system ? ['bg-slate-100', 'text-slate-400'] : ['bg-amber-50', 'text-amber-500'];
@endphp

<div {{ $attributes->class(["flex shrink-0 items-center justify-center rounded-lg $bg $box"]) }}>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="{{ $icon }} {{ $fg }}">
        <path d="M3.5 6A1.5 1.5 0 0 1 5 4.5h4.4c.5 0 1 .2 1.3.6l1.1 1.4H19A1.5 1.5 0 0 1 20.5 8v10A1.5 1.5 0 0 1 19 19.5H5A1.5 1.5 0 0 1 3.5 18V6Z" />
    </svg>
</div>
