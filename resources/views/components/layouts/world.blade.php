{{--
    The welcome page's own layout.

    A near-copy of layouts.public in its head and footer, and deliberately not a
    variant of it. The public layout opens with a masthead and a tab strip, and
    the whole point of this screen is that the first thing on it is the town —
    a nav bar above the sky would put a chrome band between the visitor and the
    only part of this system built to be looked at.

    What is not different: the same <head>, the same description meta, the same
    footer, the same referrer policy. A citizen who lands here and one who lands
    on /notices are on the same site, and the machinery underneath says so.
--}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('lgu.name') }}</title>

    <meta name="description"
          content="{{ 'Public notices and Full Disclosure Policy documents of the '.config('lgu.name').', '.config('lgu.province').'.' }}">

    <meta name="referrer" content="strict-origin-when-cross-origin">

    {{-- world.css and world.js are loaded here and nowhere else. The staff
         interface has no use for a pixel palette or a canvas renderer, and this
         page has no use for Alpine or Livewire. --}}
    @vite(['resources/css/app.css', 'resources/css/world.css', 'resources/js/world.js'])
</head>
<body class="flex h-full flex-col bg-slate-50 text-slate-900 antialiased">

    <a href="#content"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[80] focus:rounded-lg focus:bg-blue-700 focus:px-4 focus:py-2 focus:text-white">
        Skip to the notices
    </a>

    {{ $world }}

    <main id="content" class="mx-auto w-full max-w-5xl grow px-4 py-10 sm:px-6">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto max-w-5xl px-4 py-8 text-sm text-slate-600 sm:px-6">
            <p class="font-medium text-slate-800">{{ config('lgu.name') }}</p>
            <p class="mt-1">Province of {{ config('lgu.province') }}, Republic of the Philippines</p>

            <nav aria-label="Public sections" class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                <a href="{{ route('public.announcements') }}" class="font-medium text-blue-800 hover:underline">
                    All notices
                </a>
                <a href="{{ route('public.disclosure') }}" class="font-medium text-blue-800 hover:underline">
                    Full Disclosure board
                </a>
                <a href="{{ auth()->check() ? route('dashboard') : route('login') }}"
                   class="font-medium text-slate-600 hover:underline">
                    {{ auth()->check() ? 'My desk' : 'Staff sign in' }}
                </a>
            </nav>

            <p class="mt-5 max-w-2xl text-xs leading-relaxed text-slate-500">
                Documents on the Full Disclosure page are posted in accordance with the
                Department of the Interior and Local Government's Full Disclosure Policy.
                For records not published here, file a request with the office concerned.
            </p>
        </div>
    </footer>
</body>
</html>
