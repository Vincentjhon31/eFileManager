<x-settings.shell heading="Security"
                  description="Your password, the machines still signed in as you, and a record of what has been done with this account.">

    @error('current_password')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    {{-- Change password --}}
    <form wire:submit="updatePassword" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-900">Change password</h3>
        <p class="mt-1 text-xs text-slate-500">
            At least 8 characters, with letters and numbers. Changing it signs out every other
            machine still holding your account open.
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="current_password" class="block text-sm font-medium text-slate-700">Current password</label>
                <input id="current_password" wire:model="current_password" type="password" autocomplete="current-password"
                       class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">New password</label>
                <input id="password" wire:model="password" type="password" autocomplete="new-password"
                       class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                @error('password') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-slate-700">Repeat new password</label>
                <input id="password_confirmation" wire:model="password_confirmation" type="password" autocomplete="new-password"
                       class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
            </div>
        </div>

        <div class="mt-6">
            <button type="submit"
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                Change password
            </button>
        </div>
    </form>

    {{-- Other sessions --}}
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-900">Where you are signed in</h3>
        <p class="mt-1 text-sm text-slate-600">
            @if ($otherSessions > 0)
                Besides this one, your account is open on
                <span class="font-semibold text-slate-900">{{ $otherSessions }}</span>
                other {{ $otherSessions === 1 ? 'machine' : 'machines' }}.
                If that is not you — or you used a shared computer at the counter and walked away —
                sign them out.
            @else
                This is the only machine your account is open on.
            @endif
        </p>

        @if ($otherSessions > 0)
            <form wire:submit="signOutOtherSessions" class="mt-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-slate-700">Confirm your password</label>
                    <input id="confirm_password" wire:model="current_password" type="password" autocomplete="current-password"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600 sm:w-64">
                </div>
                <button type="submit"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                    Sign out other sessions
                </button>
            </form>
        @endif
    </div>

    {{-- Sign-in methods --}}
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-900">How you sign in</h3>
        <p class="mt-1 text-xs text-slate-500">
            You can have more than one. Whichever you use, you reach the same account.
        </p>

        @error('google')
            <p class="mt-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p>
        @enderror

        <ul class="mt-4 divide-y divide-slate-100">
            {{-- Always available, and deliberately without a switch: an account
                 that could turn off its own password would be one bad Google
                 configuration away from being unreachable. --}}
            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                <span>
                    <span class="block text-sm font-medium text-slate-800">Email and password</span>
                    <span class="block text-xs text-slate-500">{{ $user->email }}</span>
                </span>
                <x-status-badge tone="green" label="Always on" />
            </li>

            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                <span>
                    <span class="block text-sm font-medium text-slate-800">Google</span>
                    <span class="block text-xs text-slate-500">
                        @if (! $googleConfigured)
                            Not set up on this server. Ask MIS if your office wants it.
                        @elseif ($user->google_id)
                            Linked — you can open this account from the Google sign-in button.
                        @else
                            Sign in with your municipal Google account instead of typing a password.
                        @endif
                    </span>
                </span>

                @if ($googleConfigured)
                    @if ($user->google_id)
                        <span class="flex items-center gap-3">
                            <x-status-badge tone="green" label="Linked" />
                            <button type="button" wire:click="unlinkGoogle"
                                    wire:confirm="Unlink Google sign-in? You will sign in with your email and password."
                                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                                Unlink
                            </button>
                        </span>
                    @else
                        <a href="{{ route('auth.google.redirect') }}"
                           class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                            Link Google
                        </a>
                    @endif
                @else
                    <x-status-badge tone="slate" label="Unavailable" />
                @endif
            </li>
        </ul>
    </div>

    {{-- Recent activity --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-6 py-4">
            <h3 class="text-sm font-semibold text-slate-900">Recent account activity</h3>
            <p class="mt-1 text-xs text-slate-500">
                Sign-ins and changes to how this account is secured. If you see something here you did
                not do, change your password and tell MIS.
            </p>
        </div>

        @if ($activity->isEmpty())
            <p class="px-6 py-8 text-center text-sm text-slate-500">Nothing recorded yet.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($activity as $entry)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-6 py-3">
                        <span class="text-sm text-slate-800">
                            {{ $entry->description ?? $entry->event }}
                            @if ($entry->ip_address)
                                <span class="text-xs text-slate-500">from {{ $entry->ip_address }}</span>
                            @endif
                        </span>
                        <span class="shrink-0 text-xs text-slate-500">{{ ph_datetime($entry->created_at) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-settings.shell>
