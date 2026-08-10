<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">
<div class="min-h-full">

    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">

            <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-blue-700 text-sm font-bold text-white">eF</span>
                <span class="hidden sm:block">
                    <span class="block text-sm font-semibold leading-tight">{{ config('app.name') }}</span>
                    <span class="block text-xs text-slate-500">{{ config('lgu.name') }}</span>
                </span>
            </a>

            <div class="flex items-center gap-4">
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

                <div class="hidden text-right sm:block">
                    <span class="block text-sm font-medium leading-tight">{{ auth()->user()->name }}</span>
                    <span class="block text-xs text-slate-500">
                        {{ auth()->user()->department?->displayName() ?? 'No office assigned' }}
                    </span>
                </div>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                        Sign out
                    </button>
                </form>
            </div>
        </div>

        <nav class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <ul class="-mb-px flex gap-6 overflow-x-auto text-sm">
                @foreach ($navigation as $item)
                    <li>
                        <a href="{{ $item['url'] }}"
                           @class([
                               'inline-block whitespace-nowrap border-b-2 px-1 py-3 font-medium transition',
                               'border-blue-700 text-blue-700' => $item['active'],
                               'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' => ! $item['active'],
                           ])>
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="mb-6 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        {{ $slot }}
    </main>
</div>
</body>
</html>
