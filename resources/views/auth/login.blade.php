<x-layouts.guest title="Sign in">
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">

        @if (session('status'))
            <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @error('email')
            <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                {{ $message }}
            </div>
        @enderror

        <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">
                    Email address
                </label>
                <input id="email"
                       name="email"
                       type="email"
                       value="{{ old('email') }}"
                       required
                       autofocus
                       autocomplete="username"
                       class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">
                    Password
                </label>
                <input id="password"
                       name="password"
                       type="password"
                       required
                       autocomplete="current-password"
                       class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                @error('password')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox"
                       name="remember"
                       value="1"
                       class="rounded border-slate-300 text-blue-700 focus:ring-blue-600">
                Keep me signed in on this device
            </label>

            <button type="submit"
                    class="w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2">
                Sign in
            </button>
        </form>

        {{-- Only rendered when Google credentials are actually configured, so
             the button never appears as a dead end. Google sign-in links to an
             existing account; it cannot create one. --}}
        @if (config('services.google.client_id'))
            <div class="my-6 flex items-center gap-3">
                <div class="h-px flex-1 bg-slate-200"></div>
                <span class="text-xs uppercase tracking-wide text-slate-500">or</span>
                <div class="h-px flex-1 bg-slate-200"></div>
            </div>

            <a href="{{ route('auth.google.redirect') }}"
               class="flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                Continue with Google
            </a>
        @endif

        <p class="mt-6 text-center text-sm text-slate-600">
            Accounts are created by your system administrator.
            <br class="hidden sm:inline">
            Contact the MIS Office if you cannot sign in.
        </p>
    </div>
</x-layouts.guest>
