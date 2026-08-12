<x-layouts.public>
    <x-slot:hero>
        <section class="bg-gradient-to-b from-blue-900 to-blue-800 text-white">
            <div class="mx-auto max-w-5xl px-4 py-12 sm:px-6 lg:py-16">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-200">
                    Republic of the Philippines · {{ config('lgu.province') }}
                </p>

                <h1 class="mt-3 max-w-2xl text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
                    Welcome to the {{ config('lgu.name') }}
                </h1>

                <p class="mt-4 max-w-xl text-base leading-relaxed text-blue-100">
                    Public notices and the town's Full Disclosure Policy board — open to everyone the
                    town serves, no account needed.
                </p>

                <div class="mt-7 flex flex-wrap gap-3">
                    <a href="{{ route('public.announcements') }}"
                       class="rounded-lg bg-white px-5 py-2.5 text-sm font-semibold text-blue-900 transition hover:bg-blue-50">
                        Read notices
                    </a>
                    <a href="{{ route('public.disclosure') }}"
                       class="rounded-lg border border-white/40 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10">
                        Full Disclosure board
                    </a>
                </div>

                <dl class="mt-9 flex flex-wrap gap-x-10 gap-y-3 border-t border-white/15 pt-6">
                    <div>
                        <dt class="text-xs text-blue-200">Active notices</dt>
                        <dd class="text-lg font-semibold">{{ $noticeCount }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-blue-200">Documents disclosed</dt>
                        <dd class="text-lg font-semibold">{{ $disclosureCount }}</dd>
                    </div>
                </dl>
            </div>
        </section>
    </x-slot:hero>

    <div class="space-y-10">

        @if ($pinned->isNotEmpty())
            <section aria-labelledby="pinned-heading" class="space-y-4">
                <h2 id="pinned-heading" class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Pinned notices
                </h2>

                @foreach ($pinned as $announcement)
                    <article class="rounded-xl border-l-4 border-blue-800 bg-white p-5 shadow-sm ring-1 ring-slate-900/5">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-status-badge :tone="$announcement->category->tone()"
                                            :label="$announcement->category->label()" />
                            <span class="text-xs text-slate-500">{{ ph_date($announcement->published_at) }}</span>
                        </div>

                        <h3 class="mt-2 text-lg font-semibold">
                            <a href="{{ route('public.announcement', $announcement) }}"
                               class="text-slate-900 hover:text-blue-800 hover:underline">
                                {{ $announcement->title }}
                            </a>
                        </h3>

                        <p class="mt-2 text-sm leading-relaxed text-slate-700">{{ $announcement->summary() }}</p>
                        <p class="mt-3 text-xs text-slate-500">{{ $announcement->issuedBy() }}</p>
                    </article>
                @endforeach
            </section>
        @endif

        <section aria-labelledby="latest-heading">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 id="latest-heading" class="text-lg font-semibold text-slate-900">Latest notices</h2>
                <a href="{{ route('public.announcements') }}" class="text-sm font-medium text-blue-800 hover:underline">
                    All notices →
                </a>
            </div>

            @if ($latest->isEmpty() && $pinned->isEmpty())
                <p class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
                    There are no notices at the moment.
                </p>
            @elseif ($latest->isEmpty())
                <p class="mt-4 text-sm text-slate-500">
                    Nothing further right now — the pinned notice above is current.
                </p>
            @else
                <ul class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ($latest as $announcement)
                        <li class="rounded-xl border border-slate-200 bg-white p-5 transition hover:border-blue-300 hover:shadow-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-status-badge :tone="$announcement->category->tone()"
                                                :label="$announcement->category->label()" />
                                <span class="text-xs text-slate-500">{{ ph_date($announcement->published_at) }}</span>
                            </div>

                            <h3 class="mt-2 font-semibold">
                                <a href="{{ route('public.announcement', $announcement) }}"
                                   class="text-slate-900 hover:text-blue-800 hover:underline">
                                    {{ $announcement->title }}
                                </a>
                            </h3>

                            <p class="mt-2 text-sm text-slate-700">{{ Str::limit($announcement->summary(), 130) }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="disclosure-heading" class="rounded-xl border border-amber-200 bg-amber-50/60 p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">DILG compliance</p>
                    <h2 id="disclosure-heading" class="mt-1 text-lg font-semibold text-slate-900">
                        Full Disclosure Policy
                    </h2>
                    <p class="mt-1 max-w-2xl text-sm text-slate-600">
                        Budgets, procurement, financial statements and ordinances, posted as required
                        by the Department of the Interior and Local Government.
                    </p>
                </div>

                <a href="{{ route('public.disclosure') }}"
                   class="shrink-0 rounded-lg bg-amber-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-800">
                    Open the board →
                </a>
            </div>

            <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($shelves as $shelf)
                    <li>
                        <a href="{{ route('public.disclosure', ['category' => $shelf['category']->value]) }}"
                           class="block rounded-lg border border-amber-200/70 bg-white px-4 py-3 transition hover:border-amber-400 hover:shadow-sm">
                            <span class="block text-sm font-medium text-slate-900">{{ $shelf['category']->label() }}</span>
                            <span class="mt-0.5 block text-xs text-slate-500">
                                {{ $shelf['count'] }} document{{ $shelf['count'] === 1 ? '' : 's' }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($recentDisclosures->isNotEmpty())
                <div class="mt-6 border-t border-amber-200/70 pt-5">
                    <h3 class="text-sm font-semibold text-slate-900">Recently posted</h3>
                    <ul class="mt-3 space-y-2">
                        @foreach ($recentDisclosures as $entry)
                            <li class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                                <a href="{{ route('public.download', $entry) }}"
                                   class="font-medium text-blue-800 hover:underline">
                                    {{ $entry->title }}
                                </a>
                                <span class="text-xs text-slate-500">
                                    {{ $entry->shelfLabel() }} · {{ ph_date($entry->published_at) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>
    </div>
</x-layouts.public>
