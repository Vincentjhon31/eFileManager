{{--
    The public face of the municipality.

    Visually distinct from the staff interface on purpose: a citizen reading a
    notice should never be in any doubt that they are on the town's page rather
    than inside somebody's office system. No navigation into internal screens,
    no counts, no names.
--}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ isset($title) ? $title.' — ' : '' }}{{ config('lgu.name') }}</title>

    <meta name="description" content="{{ $description ?? 'Public notices and Full Disclosure Policy documents of the '.config('lgu.name').', '.config('lgu.province').'.' }}">

    {{-- A public government page has no business being indexed differently
         from any other, but it also has no business leaking a referrer to
         wherever a citizen clicks next. --}}
    <meta name="referrer" content="strict-origin-when-cross-origin">

    @vite(['resources/css/app.css'])
</head>
<body class="flex h-full flex-col bg-slate-50 text-slate-900 antialiased">

    <a href="#content"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-blue-700 focus:px-4 focus:py-2 focus:text-white">
        Skip to content
    </a>

    <header class="border-b-4 border-blue-800 bg-white">
        <div class="mx-auto max-w-5xl px-4 py-5 sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <a href="{{ route('public.home') }}" class="flex items-center gap-3">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-blue-800 text-lg font-bold text-white">
                        {{ config('lgu.code') }}
                    </span>
                    <span>
                        <span class="block text-xs uppercase tracking-wide text-slate-500">
                            Republic of the Philippines · {{ config('lgu.province') }}
                        </span>
                        <span class="block text-lg font-semibold leading-tight">{{ config('lgu.name') }}</span>
                    </span>
                </a>

                <a href="{{ auth()->check() ? route('dashboard') : route('login') }}"
                   class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    {{ auth()->check() ? 'My desk' : 'Staff sign in' }}
                </a>
            </div>
        </div>

        <nav aria-label="Public sections" class="border-t border-slate-200">
            <div class="mx-auto max-w-5xl px-4 sm:px-6">
                <ul class="-mb-px flex gap-6 overflow-x-auto text-sm">
                    @foreach ([
                        ['Home', 'public.home'],
                        ['Notices', 'public.announcements'],
                        ['Full Disclosure', 'public.disclosure'],
                    ] as [$label, $name])
                        @php $active = request()->routeIs($name) || ($name === 'public.announcements' && request()->routeIs('public.announcement')); @endphp
                        <li>
                            <a href="{{ route($name) }}"
                               @class([
                                   'inline-block whitespace-nowrap border-b-2 px-1 py-3 font-medium transition',
                                   'border-blue-800 text-blue-800' => $active,
                                   'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' => ! $active,
                               ])
                               @if ($active) aria-current="page" @endif>
                                {{ $label }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </nav>
    </header>

    {{-- Only the home page fills this — a full-width band above the
         constrained column, the same way the header's background already
         spans edge to edge while its content stays in the 5xl column. --}}
    {{ $hero ?? '' }}

    <main id="content" class="mx-auto w-full max-w-5xl grow px-4 py-8 sm:px-6">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto max-w-5xl px-4 py-8 text-sm text-slate-600 sm:px-6">
            <p class="font-medium text-slate-800">{{ config('lgu.name') }}</p>
            <p class="mt-1">Province of {{ config('lgu.province') }}, Republic of the Philippines</p>

            <p class="mt-4 max-w-2xl text-xs leading-relaxed text-slate-500">
                Documents on the Full Disclosure page are posted in accordance with the
                Department of the Interior and Local Government's Full Disclosure Policy.
                For records not published here, file a request with the office concerned.
            </p>
        </div>
    </footer>
</body>
</html>
