@props([
    // Everything App\Support\World or App\Support\Compound decided.
    'payload',

    // The plaque in the corner.
    'heading',
    'subheading',

    // The left-hand corner button. Icon is 'mailbox' or 'home' — see chrome.js.
    'cornerHref' => null,
    'cornerLabel' => null,
    'cornerIcon' => 'mailbox',

    // Where a guest is offered a way in, for the panels that have one. A route
    // name belongs in Blade, never in a renderer.
    'signIn' => null,

    // 'town' or 'compound'. Same markup, two projections: a side elevation
    // anchored to the bottom of the stage, or a map filling it. The stylesheet
    // needs to know which, and the renderer that is loaded decides everything
    // else.
    'scene' => 'town',

    // Whether this component draws the corner buttons and the settings
    // popover. The town does. The compound has a dock of its own — one bar of
    // icons rather than two lonely corners and a row of text in the masthead —
    // so it supplies them itself and switches these off.
    //
    // Whoever switches them off takes on #worldGear, #worldPop and
    // #worldMotion: the renderer looks all three up by id and will not run
    // without them.
    'controls' => true,
])

{{--
    A drawn world, wherever one is needed.

    Two screens use this — the public welcome page and the staff compound — and
    they differ only in the payload they are handed and the words on the plaque.
    Keeping it as one component rather than two near-identical templates is what
    stops the fallback list, the keyboard route or the splash quietly working on
    one of them and not the other.

    Two versions of the same list of places are in here, and exactly one is ever
    on screen. The <ul> in the fallback is the page as it arrives: real links, in
    reading order, that work with JavaScript switched off — the same promise every
    other page on the public side already makes.

    world.js sets data-world="on" and the stylesheet swaps them over. If that file
    never loads, never parses, or throws on an old browser, what is left is a
    plain list of links rather than a blank blue box.
--}}

<section class="world-stage" id="worldStage" data-scene="{{ $scene }}"
         aria-label="An illustrated map of {{ $heading }}"
         @if ($signIn) data-sign-in="{{ $signIn }}" @endif>
    <canvas class="world-canvas" id="worldCanvas" role="img"
            aria-label="A drawn view of {{ $subheading }}, showing {{ collect($payload['places'])->pluck('name')->join(', ', ' and ') }}."></canvas>

    <div class="world-labels" id="worldLabels" aria-hidden="true"></div>
    <div class="world-tag" id="worldTag" aria-hidden="true"></div>

    <div class="world-hero">
        <h1>{{ $heading }}</h1>
        @if ($subheading)
            <p>{{ $subheading }}</p>
        @endif
    </div>

    {{-- Every landmark, reachable by keyboard. Invisible until focused, then an
         ordinary readable control — see world.css for why these are not
         sr-only. --}}
    <nav class="world-keynav" id="worldKeynav" aria-label="Places here"></nav>

    <p class="world-hint" id="worldHint"></p>

    @if ($controls)
        @if ($cornerHref)
            <div class="world-corner left">
                <a class="world-btn" href="{{ $cornerHref }}" aria-label="{{ $cornerLabel }}">
                    <canvas id="worldTrackIcon" width="16" height="16" data-icon="{{ $cornerIcon }}"
                            aria-hidden="true"></canvas>
                </a>
                <span class="world-btn-label">{{ $cornerLabel }}</span>
            </div>
        @endif

        <div class="world-corner right">
            <button class="world-btn" id="worldGear" type="button" aria-label="Settings">
                <canvas id="worldGearIcon" width="16" height="16" aria-hidden="true"></canvas>
            </button>
            <span class="world-btn-label">Settings</span>

            <div class="world-pop" id="worldPop" hidden>
                <label>
                    <input type="checkbox" id="worldMotion">
                    Movement
                </label>
            </div>
        </div>
    @endif

    {{-- The guide. A button, because clicking him does something. --}}
    <button class="world-npc" id="worldNpc" type="button" aria-label="Mayor Mike. Click for a tip.">
        <span class="world-bubble" id="worldBubble" aria-live="polite">
            <span class="who">Mayor Mike</span>
            <span id="worldNpcText"></span>
        </span>
        <canvas id="worldNpcCanvas" width="48" height="72" aria-hidden="true"></canvas>
    </button>

</section>

{{--
    What the place actually looks like.

    Clicking a landmark opens this before it goes anywhere. The drawing is a
    drawing; the photographs are the covered court with its real roof, put there
    by somebody in the hall. A landmark that leads somewhere gets a button at the
    foot of the panel rather than losing its destination — one more click to
    reach the disclosure board, and a look at the plaza on the way.

    Outside the stage, like the wipe and the splash, because it covers the whole
    viewport rather than the drawing: a sheet the width of a canvas is a caption,
    and this is meant to be looked at.

    The photographs are the only images either renderer loads, and they are
    deliberately <img> elements over the canvas rather than drawImage into it.
    The canvas stays pure fillRect, and the browser gives us decoding, caching
    and alt text for nothing.
--}}
<div class="world-panel" id="worldPanel" role="dialog" aria-modal="true" aria-labelledby="worldPanelName" hidden>
    <div class="world-panel-veil" id="worldPanelVeil"></div>

    <div class="world-panel-in">
        <div class="world-panel-head">
            <div>
                <h2 id="worldPanelName"></h2>
                <p id="worldPanelBlurb"></p>
            </div>

            <button class="world-panel-x" id="worldPanelClose" type="button" aria-label="Close">
                <canvas width="16" height="16" data-icon="close" aria-hidden="true"></canvas>
            </button>
        </div>

        <figure class="world-frame" id="worldFrame">
            {{-- A button around the photograph rather than a click handler on
                 it: enlarging is a thing you can do, so it should be reachable
                 by keyboard and announced as such. --}}
            <button class="world-frame-zoom" id="worldPhotoZoom" type="button" hidden>
                <img id="worldPhoto" alt="" decoding="async">
                <span class="world-frame-hint">Click to enlarge</span>
            </button>

            <p class="world-frame-empty" id="worldPhotoEmpty">
                No photograph of this place yet.
            </p>

            <button class="world-frame-arrow prev" id="worldPhotoPrev" type="button" aria-label="Previous photograph">
                <canvas width="16" height="16" data-icon="prev" aria-hidden="true"></canvas>
            </button>

            <button class="world-frame-arrow next" id="worldPhotoNext" type="button" aria-label="Next photograph">
                <canvas width="16" height="16" data-icon="next" aria-hidden="true"></canvas>
            </button>

            <figcaption id="worldPhotoCaption" aria-live="polite"></figcaption>
        </figure>

        <div class="world-dots" id="worldPhotoDots"></div>

        <p class="world-panel-say" id="worldPanelSay"></p>

        {{-- Filled by the scene, or left empty. The town has nothing to put
             here; the compound puts the office's head, its notices and the
             doors a signed-in member of it may open. --}}
        <div class="world-panel-extra" id="worldPanelExtra" hidden></div>

        <a class="world-panel-go" id="worldPanelGo" href="#" hidden></a>
    </div>
</div>

{{-- The photograph on its own, as large as the screen allows. Above the panel
     rather than replacing it, so closing it puts you back where you were. --}}
<div class="world-lightbox" id="worldLightbox" role="dialog" aria-modal="true" aria-label="Photograph" hidden>
    <img id="worldLightboxImg" alt="">
    <p class="world-lightbox-cap" id="worldLightboxCap"></p>

    <button class="world-lightbox-x" id="worldLightboxClose" type="button" aria-label="Close the photograph">
        <canvas width="16" height="16" data-icon="close" aria-hidden="true"></canvas>
    </button>

    <button class="world-lightbox-arrow prev" id="worldLightboxPrev" type="button" aria-label="Previous photograph">
        <canvas width="16" height="16" data-icon="prev" aria-hidden="true"></canvas>
    </button>

    <button class="world-lightbox-arrow next" id="worldLightboxNext" type="button" aria-label="Next photograph">
        <canvas width="16" height="16" data-icon="next" aria-hidden="true"></canvas>
    </button>
</div>

{{-- The page without the drawing: what a visitor gets before world.js runs, and
     forever if it cannot. --}}
<section class="world-fallback bg-gradient-to-b from-blue-900 to-blue-800 text-white">
    <div class="mx-auto max-w-5xl px-4 py-12 sm:px-6">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-200">{{ $subheading }}</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $heading }}</h1>

        <ul class="mt-7 flex flex-wrap gap-3">
            @foreach ($payload['places'] as $place)
                @if (($place['kind'] ?? null) === 'link')
                    <li>
                        <a href="{{ $place['url'] }}"
                           class="inline-block rounded-lg bg-white/10 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/30 transition hover:bg-white/20">
                            {{ $place['name'] }}
                            <span class="block text-xs font-normal text-blue-100">{{ $place['blurb'] }}</span>
                        </a>
                    </li>
                @elseif (($place['kind'] ?? null) === 'office')
                    {{-- An office is not a link — it is a building with a panel
                         behind it, and with no JavaScript there is no panel. So
                         it appears as what it is: a name, a code and what the
                         office does. The doors inside it, where there are any,
                         are in the list below the drawing. --}}
                    <li class="max-w-xs rounded-lg bg-white/10 px-4 py-2.5 text-sm text-white ring-1 ring-white/30">
                        <b class="font-semibold">{{ $place['name'] }}</b>
                        <span class="block text-xs font-normal text-blue-100">{{ $place['say'] }}</span>
                    </li>
                @endif
            @endforeach
        </ul>
    </div>
</section>

{{-- Leaving: two ragged panels close over the page before a navigation. Outside
     the stage because it covers the whole viewport. --}}
<div class="world-wipe" id="worldWipe" aria-hidden="true">
    <span class="l"></span>
    <span class="r"></span>
</div>

{{-- Shown once per browser session. --}}
<div class="world-splash" id="worldSplash" role="presentation">
    <canvas id="worldSplashSeal" width="32" height="32" aria-hidden="true"></canvas>
    <div class="word" id="worldSplashTitle" aria-hidden="true"></div>
    <div class="word sub" id="worldSplashSub" aria-hidden="true"></div>
    <div class="bar"><i id="worldSplashBar"></i></div>
    <p class="skip">Tap to skip</p>
</div>

{{-- A JSON script tag rather than an inline assignment: the contents are never
     parsed as JavaScript, so an apostrophe in a landmark's name is an apostrophe
     and not a syntax error. --}}
<script type="application/json" id="worldData">@json($payload)</script>
