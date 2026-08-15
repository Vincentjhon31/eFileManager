@props(['name'])
{{--
    A small, fixed set of hand-drawn line icons, not an icon package. The
    sidebar is the one place in the app where a label alone would not be
    recognized at a glance the way it is on a tab; everywhere else the app
    still leans on text and color, on purpose.
--}}
@php
    $paths = [
        'dashboard' => 'M4 4h6.5v7H4V4Zm9.5 0H20v4.5h-6.5V4ZM4 13.5h6.5V20H4v-6.5Zm9.5 2H20V20h-6.5v-4.5Z',
        'desk' => 'M4 8h16M4 8l1.5-4h13L20 8M4 8v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V8M9 12h6',
        'documents' => 'M8 3h6l4 4v13a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm6 0v4h4M9 12h6M9 16h6',
        'workspace' => 'M4 4h6v6H4V4Zm10 0h6v6h-6V4ZM4 14h6v6H4v-6Zm10 0h6v6h-6v-6Z',
        'drive' => 'M4 7a2 2 0 0 1 2-2h3.5l1.8 2H18a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7Z',
        'offices' => 'M4 21h16M6 21V9l6-4 6 4v12M10 21v-5h4v5M10 12h.01M14 12h.01M10 15h.01M14 15h.01',
        'users' => 'M16.5 14.5a4 4 0 1 0-9 0M12 11a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4ZM4 20a8 8 0 0 1 16 0',
        'notices' => 'M17 17h4l-1.7-2.3A3 3 0 0 1 18.7 13V10a6.7 6.7 0 0 0-13.4 0v3a3 3 0 0 1-.6 1.7L3 17h4m10 0v1a3 3 0 0 1-6 0v-1m6 0H7',
        'disclosure' => 'M12 3l7 3v5c0 5-3 8.5-7 10-4-1.5-7-5-7-10V6l7-3Z',
        'audit' => 'M9 12.5l2 2 4.5-4.5M8 3h8l4 4v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7l4-4Z',
        'storage' => 'M4 6a8 3 0 0 0 16 0 8 3 0 0 0-16 0Zm0 0v12a8 3 0 0 0 16 0V6M4 12a8 3 0 0 0 16 0',
        // The workspace grid with a plus: the catalog is the workspace, curated.
        'apps' => 'M4 4h6v6H4V4Zm10 0h6v6h-6V4ZM4 14h6v6H4v-6Zm13 0v6m-3-3h6',
        // Two roofs behind a gate: the compound is the rest of this list, drawn.
        'compound' => 'M3 20h18M5 20v-7l4-3 4 3v7M15 20v-5l3-2 3 2v5M7 16h.01M11 16h.01M18 17h.01',
        // A picture with a horizon and a sun in it: the town, photographed.
        'town' => 'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm12.5 4.5h.01M3 15l4.5-4 4 3.5L15 11l6 5',
    ];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
     stroke-linecap="round" stroke-linejoin="round" class="size-5 shrink-0" aria-hidden="true">
    <path d="{{ $paths[$name] ?? $paths['dashboard'] }}" />
</svg>
