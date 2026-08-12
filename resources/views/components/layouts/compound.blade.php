<!DOCTYPE html>
{{--
    The staff compound's layout.

    Not layouts.app, deliberately: that one is a persistent sidebar beside a
    scrolling column, and this screen is a single drawn view of the whole hall. A
    sidebar next to it would be the same list of destinations twice, side by side,
    with one of them pretending to be a place.

    Pixel throughout, like the public side. It was half and half for a while — a
    drawn compound above a set of rounded Tailwind cards — and the join looked like
    an oversight rather than a decision. This page is reached by walking through
    the front door of the drawn town, so it keeps the town's furniture.

    No appearance script here, unlike layouts.app. The pixel palette is a fixed
    set of colours with no dark variant, so data-theme would be a promise this
    page cannot keep. Somebody who wants their preferences honoured is one click
    from the Dashboard, which does.
--}}
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>The Compound — {{ config('app.name') }}</title>

    {{-- world.css imports pixel.css, so this one entry brings the design system
         and the drawn compound together. --}}
    @vite(['resources/css/app.css', 'resources/css/world.css', 'resources/js/world.js'])
</head>
<body class="px-page flex h-full flex-col antialiased">

    <a href="#content"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[80] focus:bg-blue-700 focus:px-4 focus:py-2 focus:text-white">
        Skip to the list of screens
    </a>

    <header class="px-head">
        <div class="px-head-in">
            <span class="px-seal" aria-hidden="true">{{ config('lgu.code') }}</span>
            <span class="px-brand-text">
                <b>The Compound</b>
                <span>{{ config('lgu.name') }}</span>
            </span>

            <span class="px-grow"></span>

            {{-- The way out. This screen is the scenic route, and somebody who has
                 seen enough of it should never have to hunt for the ordinary
                 one. --}}
            <a href="{{ route('dashboard') }}" class="px-btn go">Skip to Dashboard</a>
        </div>
    </header>

    {{ $world }}

    {{--
        The same destinations as a plain list, below the drawing.

        Not a fallback — the fallback inside the component is for when the renderer
        never runs. This is for somebody who can see the compound perfectly well
        and would still rather read a list, which on a screen whose whole point is
        navigation is a reasonable thing to want.
    --}}
    <main id="content" class="px-wrap grow">
        <h2 class="px-eyebrow">Every screen you can open</h2>

        <ul class="px-grid three">
            @foreach ($links as $link)
                <li>
                    <a class="px-shelf" href="{{ $link['url'] }}">
                        <b>{{ $link['name'] }}</b>
                        <span>{{ $link['blurb'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </main>

    <footer class="px-foot">
        <div class="px-foot-in">
            <b>{{ config('lgu.name') }}</b>
            <p>Signed in as {{ auth()->user()?->name }}</p>

            <nav aria-label="Elsewhere">
                <a href="{{ route('dashboard') }}">Dashboard</a>
                <a href="{{ route('public.home') }}">The public page</a>
            </nav>
        </div>
    </footer>
</body>
</html>
