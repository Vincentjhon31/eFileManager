@props(['file', 'size' => 'md'])

{{--
    One shape per kind rather than a single generic page for everything —
    tinted by kindLabel() so a folder full of scans and spreadsheets is
    scannable at a glance instead of reading as a list of identical pages.
    The extension badge is the fallback for kinds that get the generic shape.
--}}
@php
    $kind = $file->kindLabel();
    $ext = $file->extension();

    [$bg, $fg] = match ($kind) {
        'PDF' => ['bg-red-50', 'text-red-600'],
        'Image' => ['bg-purple-50', 'text-purple-600'],
        'Video' => ['bg-pink-50', 'text-pink-600'],
        'Audio' => ['bg-teal-50', 'text-teal-600'],
        'Sheet' => ['bg-green-50', 'text-green-600'],
        'Slides' => ['bg-orange-50', 'text-orange-600'],
        'Document' => ['bg-blue-50', 'text-blue-600'],
        'Archive' => ['bg-amber-50', 'text-amber-600'],
        default => ['bg-slate-100', 'text-slate-500'],
    };

    $box = $size === 'lg' ? 'size-12' : 'size-10';
    $icon = $size === 'lg' ? 'size-6' : 'size-5';
@endphp

<div {{ $attributes->class(["relative flex shrink-0 items-center justify-center rounded-lg $bg $box"]) }}>
    @if ($kind === 'Image')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
             stroke-linecap="round" stroke-linejoin="round" class="{{ $icon }} {{ $fg }}">
            <path d="M4 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5Z" />
            <circle cx="9" cy="10" r="1.6" />
            <path d="m4 17 5-5 3.5 3.5L17 10l3 3" />
        </svg>
    @elseif ($kind === 'Sheet')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
             stroke-linecap="round" stroke-linejoin="round" class="{{ $icon }} {{ $fg }}">
            <path d="M8 3h6l4 4v13a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm6 0v4h4" />
            <path d="M8 12h11M8 16h11M12 12v8" />
        </svg>
    @elseif ($kind === 'Slides')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
             stroke-linecap="round" stroke-linejoin="round" class="{{ $icon }} {{ $fg }}">
            <path d="M8 3h6l4 4v13a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm6 0v4h4" />
            <rect x="9" y="12" width="9" height="6" rx="1" />
        </svg>
    @elseif ($kind === 'Video')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
             stroke-linecap="round" stroke-linejoin="round" class="{{ $icon }} {{ $fg }}">
            <rect x="3" y="6" width="13" height="12" rx="1.5" />
            <path d="m16 10.5 5-2.75v8.5l-5-2.75Z" />
        </svg>
    @elseif ($kind === 'Audio')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
             stroke-linecap="round" stroke-linejoin="round" class="{{ $icon }} {{ $fg }}">
            <path d="M9 17V5.5l10-2v11.5" />
            <circle cx="6.5" cy="17" r="2.5" />
            <circle cx="16.5" cy="14.5" r="2.5" />
        </svg>
    @elseif ($kind === 'Archive')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
             stroke-linecap="round" stroke-linejoin="round" class="{{ $icon }} {{ $fg }}">
            <path d="M8 3h6l4 4v13a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm6 0v4h4" />
            <path d="M12 8v1.5M12 11v1.5M12 14v1.5" />
        </svg>
    @else
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
             stroke-linecap="round" stroke-linejoin="round" class="{{ $icon }} {{ $fg }}">
            <path d="M8 3h6l4 4v13a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm6 0v4h4M9 13h6M9 16.5h6" />
        </svg>
    @endif

    @if ($ext)
        <span class="absolute -bottom-1.5 -right-1.5 rounded bg-white px-1 py-px text-[9px] font-bold leading-tight {{ $fg }} shadow ring-1 ring-slate-200">
            {{ mb_strtoupper($ext) }}
        </span>
    @endif
</div>
