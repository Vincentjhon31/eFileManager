@php
    $appearance = auth()->user()?->preferences() ?? \App\Support\UserPreferences::fromArray(null);
@endphp
<!DOCTYPE html>
{{--
    The staff compound's layout.

    Not layouts.app, deliberately: that one is a persistent sidebar beside a
    scrolling column, and this screen is a single drawn view of the whole hall.
    A sidebar next to it would be the same list of destinations twice, side by
    side, with one of them pretending to be a place.

    It does keep layouts.app's appearance script, because the strip along the top
    and the bar down the bottom are ordinary interface, and somebody who asked
    for dark mode asked for it everywhere. The drawn world itself is daylit in
    both themes — it is a view out of a window, and the weather does not follow
    the operating system.
--}}
<html lang="en" class="h-full"
      data-theme-choice="{{ $appearance->theme() }}"
      data-density="{{ $appearance->density() }}"
      data-text="{{ $appearance->textSize() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>The Compound — {{ config('app.name') }}</title>

    {{-- In <head> and not in a bundle, so data-theme is on the element before
         the first paint. See layouts.app for the full reasoning. --}}
    <script>
        (function () {
            var el = document.documentElement;
            var media = window.matchMedia('(prefers-color-scheme: dark)');

            var apply = function () {
                var choice = el.dataset.themeChoice || 'system';
                el.dataset.theme = choice === 'dark' || (choice === 'system' && media.matches) ? 'dark' : 'light';
            };

            apply();
            media.addEventListener('change', apply);
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/css/world.css', 'resources/js/world.js'])
</head>
<body class="flex h-full flex-col bg-slate-50 text-slate-900 antialiased">

    <a href="#content"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[80] focus:rounded-lg focus:bg-blue-700 focus:px-4 focus:py-2 focus:text-white">
        Skip to the list of screens
    </a>

    <header class="flex flex-shrink-0 items-center gap-3 border-b border-slate-200 bg-white px-4 py-2.5">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-blue-800 text-xs font-bold text-white">
            {{ config('lgu.code') }}
        </span>
        <span class="min-w-0">
            <span class="block truncate text-sm font-semibold leading-tight">The Compound</span>
            <span class="block truncate text-xs text-slate-500">{{ config('lgu.name') }}</span>
        </span>

        <span class="grow"></span>

        {{-- The way out. This screen is the scenic route, and somebody who has
             seen enough of it should never have to hunt for the ordinary one. --}}
        <a href="{{ route('dashboard') }}"
           class="rounded-lg bg-blue-700 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-blue-800">
            Skip to Dashboard
        </a>
    </header>

    {{ $world }}

    {{--
        The same destinations as a plain list, below the drawing.

        Not a fallback — the fallback inside the component is for when the
        renderer never runs. This is for somebody who can see the compound
        perfectly well and would still rather read a list, which on a screen
        whose whole point is navigation is a reasonable thing to want.
    --}}
    <main id="content" class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Every screen you can open</h2>

        <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($links as $link)
                <li>
                    <a href="{{ $link['url'] }}"
                       class="block rounded-xl border border-slate-200 bg-white px-4 py-3 transition hover:border-blue-300 hover:shadow-sm">
                        <span class="block text-sm font-medium text-slate-900">{{ $link['name'] }}</span>
                        <span class="mt-0.5 block text-xs text-slate-500">{{ $link['blurb'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </main>
</body>
</html>
