@props(['title' => null, 'description' => null])

{{--
    The public face of the municipality — all four pages of it.

    Visually distinct from the staff interface on purpose: a citizen reading a
    notice should never be in any doubt that they are on the town's page rather
    than inside somebody's office system. No navigation into internal screens, no
    counts, no names.

    One layout rather than two. The welcome page used to have its own, because it
    opens on the drawn town and a masthead above the sky would put a band of
    chrome between the visitor and the only part of this system built to be looked
    at. That is still true — but it is a difference of one slot, not of a whole
    file: pass $world and the town becomes the header, with the tab strip beneath
    it; leave it out and you get the ordinary masthead. Everything else — the
    head, the palette, the footer, the referrer policy — is then shared by
    construction rather than by somebody remembering to copy it across.
--}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ? $title.' — ' : '' }}{{ config('lgu.name') }}</title>

    <meta name="description" content="{{ $description ?? 'Public notices and Full Disclosure Policy documents of the '.config('lgu.name').', '.config('lgu.province').'.' }}">

    {{-- A public government page has no business being indexed differently from
         any other, but it also has no business leaking a referrer to wherever a
         citizen clicks next. --}}
    <meta name="referrer" content="strict-origin-when-cross-origin">

    @if (isset($world))
        {{-- world.css imports pixel.css, so this one entry brings the design
             system and the town together. world.js is the renderer, and only
             this page has anything for it to draw. --}}
        @vite(['resources/css/app.css', 'resources/css/world.css', 'resources/js/world.js'])
    @else
        @vite(['resources/css/app.css', 'resources/css/pixel.css'])
    @endif
</head>
<body class="px-page antialiased">

    <a href="#content"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[80] focus:bg-blue-700 focus:px-4 focus:py-2 focus:text-white">
        Skip to content
    </a>

    @isset($world)
        {{-- The town is the masthead, and it gets the whole first screen: a
             half-height stage had to crop the sky to keep the ground, and the
             sky is where the clouds and the birds are. The tab strip and the
             notices follow it in ordinary flow, one scroll down. --}}
        <div class="px-screen">
            {{ $world }}
        </div>
    @else
        <header class="px-head">
            <div class="px-head-in">
                <a href="{{ route('public.home') }}" class="px-brand">
                    <span class="px-seal" aria-hidden="true">{{ config('lgu.code') }}</span>
                    <span class="px-brand-text">
                        <b>{{ config('lgu.name') }}</b>
                        <span>Republic of the Philippines · {{ config('lgu.province') }}</span>
                    </span>
                </a>

                <span class="px-grow"></span>

                <a href="{{ auth()->check() ? route('compound') : route('login') }}" class="px-btn quiet on-dark">
                    {{ auth()->check() ? 'My desk' : 'Staff sign in' }}
                </a>
            </div>
        </header>
    @endisset

    {{-- Below the town on the welcome page, below the masthead everywhere else.
         Either way it is the same strip in the same place relative to the
         content, so somebody arriving from the front page does not have to find
         the navigation again. --}}
    <nav class="px-nav" aria-label="Public sections">
        <ul>
            @foreach ([
                ['Home', 'public.home'],
                ['Mailbox', 'public.mailbox'],
                ['Notices', 'public.announcements'],
                ['Full Disclosure', 'public.disclosure'],
            ] as [$label, $name])
                @php
                    $active = request()->routeIs($name)
                        || ($name === 'public.announcements' && request()->routeIs('public.announcement'));
                @endphp
                <li>
                    <a href="{{ route($name) }}" @if ($active) aria-current="page" @endif>{{ $label }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    <main id="content" class="px-wrap">
        {{ $slot }}
    </main>

    <footer class="px-foot">
        <div class="px-foot-in">
            <b>{{ config('lgu.name') }}</b>
            <p>Province of {{ config('lgu.province') }}, Republic of the Philippines</p>

            <nav aria-label="Elsewhere on this site">
                <a href="{{ route('public.announcements') }}">All notices</a>
                <a href="{{ route('public.disclosure') }}">Full Disclosure board</a>
                <a href="{{ auth()->check() ? route('compound') : route('login') }}">
                    {{ auth()->check() ? 'My desk' : 'Staff sign in' }}
                </a>
            </nav>

            <p class="fine">
                Documents on the Full Disclosure page are posted in accordance with the
                Department of the Interior and Local Government's Full Disclosure Policy.
                For records not published here, file a request with the office concerned.
            </p>
        </div>
    </footer>
</body>
</html>
