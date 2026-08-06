<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">
    <div class="flex min-h-full flex-col justify-center px-4 py-12">
        <div class="mx-auto w-full max-w-md">

            <div class="mb-8 text-center">
                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-xl bg-blue-700 text-2xl font-bold text-white">
                    eF
                </div>
                <h1 class="text-xl font-semibold text-slate-900">{{ config('app.name') }}</h1>
                <p class="mt-1 text-sm text-slate-600">
                    {{ config('lgu.name') }}
                </p>
            </div>

            {{ $slot }}

            <p class="mt-8 text-center text-xs text-slate-500">
                For authorised personnel only. All activity on this system is logged.
            </p>
        </div>
    </div>
</body>
</html>
