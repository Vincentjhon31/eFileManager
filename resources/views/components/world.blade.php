@props([
    // Everything App\Support\World or App\Support\Compound decided.
    'payload',

    // The plaque in the corner.
    'heading',
    'subheading',

    // The left-hand corner button. Icon is 'track' or 'home' — see world.js.
    'cornerHref' => null,
    'cornerLabel' => null,
    'cornerIcon' => 'track',
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

<section class="world-stage" id="worldStage" aria-label="An illustrated map of {{ $heading }}">
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

    {{-- The guide. A button, because clicking him does something. --}}
    <button class="world-npc" id="worldNpc" type="button" aria-label="Mayor Mike. Click for a tip.">
        <span class="world-bubble" id="worldBubble" aria-live="polite">
            <span class="who">Mayor Mike</span>
            <span id="worldNpcText"></span>
        </span>
        <canvas id="worldNpcCanvas" width="48" height="72" aria-hidden="true"></canvas>
    </button>
</section>

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
