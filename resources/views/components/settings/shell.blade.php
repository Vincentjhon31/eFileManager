@props(['heading', 'description' => null])

{{--
    The frame every settings screen sits in.

    The tabs are filtered the same way the sidebar is — by what the signed-in
    user may actually do — and for the same reason: hiding a tab is presentation
    only. Every one of these routes is independently guarded, and System is
    refused by middleware to anyone who types its URL.
--}}
@php
    $tabs = collect([
        ['route' => 'settings.profile', 'label' => 'Profile', 'visible' => true],
        ['route' => 'settings.security', 'label' => 'Security', 'visible' => true],
        ['route' => 'settings.appearance', 'label' => 'Appearance', 'visible' => true],
        ['route' => 'settings.preferences', 'label' => 'Preferences', 'visible' => true],
        ['route' => 'settings.notifications', 'label' => 'Notifications', 'visible' => true],
        [
            'route' => 'settings.system',
            'label' => 'System',
            'visible' => auth()->user()?->can(\App\Enums\Permission::SettingsManage->value) ?? false,
        ],
    ])->filter(fn ($tab) => $tab['visible'] && \Illuminate\Support\Facades\Route::has($tab['route']));
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Settings</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-600">
            Your account, how the system looks to you, and — for administrators — how it behaves
            for everybody.
        </p>
    </div>

    {{-- role="tablist" is honest here: these buttons swap which panel fills the
         page below them, and it gets aria-current for free instead of leaving
         colour alone to say which is open. --}}
    <div class="overflow-x-auto border-b border-slate-200">
        <nav class="-mb-px flex gap-1" aria-label="Settings sections">
            @foreach ($tabs as $tab)
                @php($active = request()->routeIs($tab['route']))
                <a href="{{ route($tab['route']) }}" wire:navigate
                   @if ($active) aria-current="page" @endif
                   @class([
                       'whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition',
                       'border-blue-700 text-blue-700' => $active,
                       'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' => ! $active,
                   ])>
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </nav>
    </div>

    <div class="max-w-3xl space-y-6">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ $heading }}</h2>
            @if ($description)
                <p class="mt-1 text-sm text-slate-600">{{ $description }}</p>
            @endif
        </div>

        {{--
            Inside the component, not only in the layout.

            Saving here is a Livewire action, and a Livewire action re-renders
            the component alone — the layout, and the status banner that lives
            in it, are left exactly as they were. A confirmation that only
            appears on the next full page load is a confirmation nobody sees,
            and a save with no acknowledgement reads as a save that failed.
        --}}
        @if (session('status'))
            <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800" role="status">
                {{ session('status') }}
            </div>
        @endif

        {{ $slot }}
    </div>
</div>
