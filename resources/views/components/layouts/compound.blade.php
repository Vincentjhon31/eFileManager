@props(['officeCount' => 0])
<!DOCTYPE html>
{{--
    The compound's layout.

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

    Reachable without an account, since the buildings became the offices rather
    than the signed-in employee's screens. So nothing in this file may assume
    there is a user.

    Exactly one viewport tall, and nothing under it. There used to be the whole
    compound again as a list of cards below the map, which made this a screen
    you scrolled away from the only thing on it. Searching replaced that list —
    the reason anybody read it was to find one office in it — and the page no
    longer scrolls at all.
--}}
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>The Compound — {{ config('lgu.name') }}</title>

    <meta name="description"
          content="The offices of the {{ config('lgu.name') }}: what each one does, who heads it, and what it has posted.">

    {{-- world.css imports pixel.css, so this one entry brings the design system
         and the drawn compound together. compound.js is the isometric renderer;
         world.js draws the town and has nothing to do here. --}}
    @vite(['resources/css/app.css', 'resources/css/world.css', 'resources/js/compound.js'])
</head>
<body class="px-page antialiased">

    {{-- Straight to the keyboard route through the buildings, which is the
         list of offices as far as anybody not using the map is concerned. --}}
    <a href="#worldKeynav"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[80] focus:bg-blue-700 focus:px-4 focus:py-2 focus:text-white">
        Skip to the offices
    </a>

    {{-- The strip and the compound together are exactly one viewport, so the
         drawn hall gets everything the header does not — see .px-screen. --}}
    <div class="px-screen">
        <header class="px-head">
            <div class="px-head-in">
                <span class="px-seal" aria-hidden="true">{{ config('lgu.code') }}</span>
                <span class="px-brand-text">
                    <b>The Compound</b>
                    <span>{{ config('lgu.name') }}</span>
                </span>

                <span class="px-grow"></span>

                {{-- What is left in the masthead is what the compound *is*.
                     Everything you can *do* moved to the dock below, where a
                     hand on the map can reach it. --}}
                <span class="px-meta">{{ $officeCount }} offices</span>
            </div>
        </header>

        {{ $world }}

        {{--
            The dock.

            One bar of drawn icons along the bottom of the map, rather than a row
            of text buttons in the masthead and two more stranded in opposite
            corners of the screen. Three reasons it is here and not up there: the
            hand is already on the map, a map's controls belong on the map, and a
            word in a header is not something you press while looking somewhere
            else.

            The ids matter — the renderer finds every one of these by id.

            No movement toggle. It was here, it did not work, and it was the
            wrong thing to offer anyway: the compound is meant to look alive, so
            movement is simply on. A machine that asks for reduced motion still
            gets it — that is honoured without anybody having to press anything.
        --}}
        <nav class="world-dock" aria-label="The compound">
            <a class="world-dock-btn" href="{{ route('public.home') }}">
                <canvas width="16" height="16" data-icon="town" aria-hidden="true"></canvas>
                <span>The town</span>
            </a>

            {{-- Shown by the renderer once it knows the signed-in user has an
                 office standing here, and removed outright when they have not.
                 Never a button that does nothing. --}}
            <button type="button" class="world-dock-btn" id="compoundTakeMe" hidden>
                <canvas width="16" height="16" data-icon="mine" aria-hidden="true"></canvas>
                <span>My office</span>
            </button>

            @can('settings.manage')
                {{-- The compound is the municipality's own picture of itself, so
                     arranging it sits behind the same permission as System
                     settings rather than behind any one office's. --}}
                <button type="button" class="world-dock-btn" id="compoundArrange"
                        data-url="{{ route('compound.layout') }}" aria-pressed="false">
                    <canvas width="16" height="16" data-icon="arrange" aria-hidden="true"></canvas>
                    <span>Arrange</span>
                </button>
            @endcan

            {{-- What the list of offices under the map used to be for. --}}
            <button type="button" class="world-dock-btn" id="compoundFind">
                <canvas width="16" height="16" data-icon="find" aria-hidden="true"></canvas>
                <span>Find</span>
            </button>

            @can('settings.manage')
                <button type="button" class="world-dock-btn" id="compoundAdd"
                        data-url="{{ route('compound.buildings.store') }}"
                        data-land-url="{{ route('compound.land') }}"
                        data-ground-url="{{ route('compound.tiles') }}">
                    <canvas width="16" height="16" data-icon="add" aria-hidden="true"></canvas>
                    <span>Add</span>
                </button>
            @endcan

            @auth
                {{-- The way out. This screen is the scenic route, and somebody who
                     has seen enough of it should never have to hunt for the
                     ordinary one. --}}
                <a class="world-dock-btn" href="{{ route('dashboard') }}">
                    <canvas width="16" height="16" data-icon="dashboard" aria-hidden="true"></canvas>
                    <span>Dashboard</span>
                </a>
            @else
                <a class="world-dock-btn" href="{{ route('login') }}">
                    <canvas width="16" height="16" data-icon="signin" aria-hidden="true"></canvas>
                    <span>Sign in</span>
                </a>
            @endauth

            <div class="world-dock-sep" aria-hidden="true"></div>

            <button type="button" class="world-dock-btn tight" id="compoundZoomOut" aria-label="Zoom out">
                <canvas width="16" height="16" data-icon="minus" aria-hidden="true"></canvas>
            </button>

            <button type="button" class="world-dock-btn tight" id="compoundZoomIn" aria-label="Zoom in">
                <canvas width="16" height="16" data-icon="plus" aria-hidden="true"></canvas>
            </button>
        </nav>
    </div>

    {{--
        One panel, three jobs.

        Finding an office, putting a building up, and taking one down are three
        different things that all want the same thing: a column down one side of
        the map, with the map still visible beside it. Three separate overlays
        would be three sets of the same chrome and three chances for two of them
        to be open at once.

        The renderer fills the body; everything here is the frame around it.
    --}}
    <div class="compound-sheet" id="compoundSheet" role="dialog" aria-labelledby="compoundSheetTitle" hidden>
        <div class="compound-sheet-head">
            <h2 id="compoundSheetTitle"></h2>

            <button type="button" class="compound-sheet-x" id="compoundSheetClose" aria-label="Close">
                <canvas width="16" height="16" data-icon="close" aria-hidden="true"></canvas>
            </button>
        </div>

        <div class="compound-sheet-body" id="compoundSheetBody"></div>
    </div>

    {{--
        What is on the brush, while there is anything on it.

        A loaded brush used to be invisible the moment the panel closed, and the
        only way to put it down was to find the panel again and press a button
        at the bottom of it — so the next click anywhere on the map laid a path
        somebody had stopped meaning to lay. This sits above the dock for as
        long as the brush is loaded and cannot be missed, and pressing it is the
        way out. So is Escape.
    --}}
    <div class="compound-brushchip" id="compoundBrushChip" hidden>
        <canvas width="34" height="22" id="compoundBrushSwatch" aria-hidden="true"></canvas>
        <span id="compoundBrushName"></span>
        <button type="button" id="compoundBrushStop">Done</button>
    </div>

    {{-- What the server said about the last arrangement. Empty and invisible
         until there is something to say. --}}
    <p class="compound-toast" id="compoundToast" role="status" aria-live="polite"></p>

</body>
</html>
