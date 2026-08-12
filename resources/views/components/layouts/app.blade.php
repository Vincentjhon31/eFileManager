@php
    // Block form, not @php(...): the inline directive compiles to an unclosed
    // <?php tag in this Blade version and swallows the rest of the template.
    $appearance = auth()->user()?->preferences() ?? \App\Support\UserPreferences::fromArray(null);
@endphp
<!DOCTYPE html>
{{--
    The appearance choices from Settings ride on <html>, where the stylesheet
    can read them. data-theme carries the *resolved* answer (light or dark);
    data-theme-choice remembers what was actually asked for, because "match my
    device" has to be re-resolved when the device changes its mind.
--}}
{{--
    data-skin is what turns the whole staff interface pixel.

    Nothing below it in this file, and nothing in any of the thirty screens it
    frames, knows that: skin.css redefines the variables Tailwind's utilities
    already point at, exactly as app.css does for dark mode. See the note at the
    top of that file for why this beats rewriting every template.
--}}
<html lang="en" class="h-full"
      data-skin="pixel"
      data-theme-choice="{{ $appearance->theme() }}"
      data-density="{{ $appearance->density() }}"
      data-text="{{ $appearance->textSize() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }} — {{ config('app.name') }}</title>

    {{--
        Before the stylesheet, and deliberately not in app.js.

        This runs synchronously in <head>, so data-theme is on the element
        before the first paint. Deferred to the bundle it would run after the
        page had already been drawn, and somebody who asked for dark would get
        a white flash on every single navigation.
    --}}
    <script>
        (function () {
            var el = document.documentElement;
            var media = window.matchMedia('(prefers-color-scheme: dark)');

            var apply = function () {
                var choice = el.dataset.themeChoice || 'system';
                var dark = choice === 'dark' || (choice === 'system' && media.matches);

                el.dataset.theme = dark ? 'dark' : 'light';
            };

            apply();

            // Follows the machine while the page is open, for anyone on
            // "match my device" whose laptop switches at sunset.
            media.addEventListener('change', apply);

            /*
             * Settings → Appearance announces a change as it is made.
             *
             * A Livewire action re-renders only the component that handled it,
             * never the layout — and these three attributes live on <html>,
             * which is the layout. Without this, choosing Dark and pressing
             * Save would write the database and change nothing on screen until
             * the next full page load.
             */
            window.addEventListener('appearance-changed', function (event) {
                var chosen = event.detail || {};

                if (chosen.theme) el.dataset.themeChoice = chosen.theme;
                if (chosen.density) el.dataset.density = chosen.density;
                if (chosen.text) el.dataset.text = chosen.text;

                apply();
            });

            /*
             * wire:navigate copies the incoming page's <html> attributes over,
             * which brings data-theme-choice with it — but data-theme is worked
             * out here and never rendered by the server, so it has to be
             * recomputed once the new attributes have landed.
             */
            document.addEventListener('livewire:navigated', apply);
        })();
    </script>

    {{-- After app.css, so the skin's token overrides win. skin.css imports
         pixel.css, which is where the palette and the display face come from. --}}
    @vite(['resources/css/app.css', 'resources/css/skin.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
<div x-data="{ sidebarOpen: false }" class="flex h-full">

    {{--
        A persistent sidebar rather than the old top tab strip. The same
        permission-filtered list from Navigation::forCurrentUser() drives it —
        this is a different shelf to put the links on, not a different set of
        links or a different rule for who sees them.
    --}}
    <aside
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
        class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col border-r border-slate-200 bg-white transition-transform duration-200 ease-out lg:static lg:translate-x-0"
    >
        <a href="{{ route('dashboard') }}" wire:navigate class="flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 px-5">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-blue-700 text-sm font-bold text-white">eF</span>
            <span class="min-w-0">
                <span class="block truncate text-sm font-semibold leading-tight">{{ config('app.name') }}</span>
                <span class="block truncate text-xs text-slate-500">{{ config('lgu.name') }}</span>
            </span>
        </a>

        {{--
            Dimmed and unclickable while the tour is open: every stop on it is
            one of these links, so letting a click jump the page away mid-tour
            would strand the walkthrough on whatever page it lands on.
        --}}
        <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5 transition"
             :class="$store.tour.open ? 'pointer-events-none opacity-60' : ''">
            @foreach (collect($navigation)->groupBy('group') as $group => $items)
                <div>
                    @if ($group === 'admin')
                        <p class="mb-2 px-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Administration</p>
                    @endif
                    <ul class="space-y-1">
                        @foreach ($items as $item)
                            <li>
                                <a href="{{ $item['url'] }}" @if ($item['navigate']) wire:navigate @endif
                                   data-tour="{{ $item['icon'] }}"
                                   @class([
                                       'flex items-center gap-3 rounded-full px-3 py-2 text-sm font-medium transition',
                                       'bg-blue-50 text-blue-700' => $item['active'],
                                       'text-slate-700 hover:bg-slate-100' => ! $item['active'],
                                   ])>
                                    <x-nav-icon :name="$item['icon']" />
                                    <span class="truncate">{{ $item['label'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>
    </aside>

    {{-- Mobile backdrop: closes the sidebar with a tap, same as Drive/Calendar's. --}}
    <div
        x-cloak x-show="sidebarOpen"
        x-transition.opacity
        @click="sidebarOpen = false"
        class="fixed inset-0 z-30 bg-slate-900/30 lg:hidden"
    ></div>

    <div class="flex min-h-full min-w-0 flex-1 flex-col">
        <header class="flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 bg-white px-4 sm:px-6">
            <button type="button" @click="sidebarOpen = ! sidebarOpen"
                    class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden"
                    aria-label="Toggle navigation">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" class="size-5">
                    <path d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <div class="flex-1"></div>

            <button type="button" @click="window.dispatchEvent(new CustomEvent('tour:start'))"
                    class="hidden rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 sm:inline-block">
                Take the tour
            </button>

            @php $unreadAlerts = auth()->user()->unreadNotifications()->count(); @endphp

            <a href="{{ route('alerts') }}" wire:navigate
               class="relative rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
               aria-label="Alerts{{ $unreadAlerts ? ", {$unreadAlerts} unread" : '' }}">
                Alerts
                @if ($unreadAlerts > 0)
                    <span class="absolute -right-1.5 -top-1.5 inline-flex min-w-5 justify-center rounded-full bg-red-600 px-1.5 py-0.5 text-xs font-semibold leading-none text-white">
                        {{ $unreadAlerts > 9 ? '9+' : $unreadAlerts }}
                    </span>
                @endif
            </a>

            {{--
                The account menu, where every website keeps it. Settings lives
                here rather than in the sidebar because it is about the person
                using the system, not about the work the sidebar lists.
            --}}
            <x-dropdown align="right" label="Account menu">
                <x-slot:trigger>
                    <button type="button" :aria-expanded="menuOpen" aria-haspopup="true"
                            class="flex items-center gap-2 rounded-lg border border-slate-300 py-1.5 pl-2 pr-3 text-left transition hover:bg-slate-50">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-blue-700 text-xs font-semibold text-white">
                            {{ \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}
                        </span>
                        <span class="hidden sm:block">
                            <span class="block text-sm font-medium leading-tight">{{ auth()->user()->name }}</span>
                            <span class="block text-xs text-slate-500">
                                {{ auth()->user()->department?->displayName() ?? 'No office assigned' }}
                            </span>
                        </span>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-4 shrink-0 text-slate-400">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </button>
                </x-slot:trigger>

                <div class="border-b border-slate-100 px-3 py-2 sm:hidden">
                    <p class="truncate text-sm font-medium text-slate-900">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</p>
                </div>

                <a href="{{ route('settings.profile') }}" wire:navigate
                   class="block px-3 py-2 text-slate-700 hover:bg-slate-50">Settings</a>
                <a href="{{ route('settings.security') }}" wire:navigate
                   class="block px-3 py-2 text-slate-700 hover:bg-slate-50">Password &amp; security</a>
                <a href="{{ route('alerts') }}" wire:navigate
                   class="block px-3 py-2 text-slate-700 hover:bg-slate-50 sm:hidden">Alerts</a>

                <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100">
                    @csrf
                    <button type="submit"
                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">
                        Sign out
                    </button>
                </form>
            </x-dropdown>
        </header>

        <main class="flex-1 overflow-y-auto px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                @if (session('status'))
                    <div class="mb-6 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">
                        {{ session('status') }}
                    </div>
                @endif

                {{ $slot }}
            </div>
        </main>
    </div>
</div>

<x-tour :steps="$tourSteps" :auto-start="$tourAutoStart" />
</body>
</html>
