<x-layouts.public :title="$announcement->title" :description="$announcement->summary()">
    <div class="space-y-8">

        <nav aria-label="Breadcrumb" class="text-sm">
            <a href="{{ route('public.announcements') }}" class="text-blue-800 hover:underline">← All notices</a>
        </nav>

        <article class="rounded-xl border border-slate-200 bg-white p-6 sm:p-8">
            <div class="flex flex-wrap items-center gap-2">
                <x-status-badge :tone="$announcement->category->tone()" :label="$announcement->category->label()" />
                @if ($announcement->is_pinned)
                    <x-status-badge tone="amber" label="Important" />
                @endif
            </div>

            <h1 class="mt-3 text-2xl font-semibold leading-snug text-slate-900">{{ $announcement->title }}</h1>

            <p class="mt-2 text-sm text-slate-600">
                Posted {{ ph_datetime($announcement->published_at) }} by {{ $announcement->issuedBy() }}
            </p>

            @if ($announcement->expires_at)
                <p class="mt-3 rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-900">
                    This notice applies until {{ ph_datetime($announcement->expires_at) }}.
                </p>
            @endif

            {{-- Escaped and rendered as plain paragraphs. The body is typed by
                 staff into a textarea and shown to the public; letting markup
                 through would make every notice an opportunity to put a script
                 on a government page. --}}
            <div class="mt-6 space-y-4 leading-relaxed text-slate-800">
                @foreach (preg_split('/\R{2,}/', trim($announcement->body)) as $paragraph)
                    <p class="whitespace-pre-line">{{ $paragraph }}</p>
                @endforeach
            </div>

            @if ($attachments->isNotEmpty())
                <div class="mt-8 border-t border-slate-100 pt-6">
                    <h2 class="text-sm font-semibold text-slate-900">Attachments</h2>
                    <ul class="mt-3 space-y-2">
                        @foreach ($attachments as $entry)
                            <li>
                                <a href="{{ route('public.download', $entry) }}"
                                   class="inline-flex items-baseline gap-2 text-sm font-medium text-blue-800 hover:underline">
                                    {{ $entry->title }}
                                    <span class="text-xs font-normal text-slate-500">
                                        {{ $entry->file?->kindLabel() }} · {{ $entry->file?->humanSize() }}
                                    </span>
                                </a>
                                @if ($entry->description)
                                    <span class="mt-0.5 block text-xs text-slate-600">{{ $entry->description }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </article>

        @if ($more->isNotEmpty())
            <section aria-labelledby="more-heading">
                <h2 id="more-heading" class="text-lg font-semibold text-slate-900">More notices</h2>
                <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach ($more as $other)
                        <li class="rounded-xl border border-slate-200 bg-white p-4">
                            <a href="{{ route('public.announcement', $other) }}"
                               class="font-medium text-slate-900 hover:text-blue-800 hover:underline">
                                {{ $other->title }}
                            </a>
                            <span class="mt-1 block text-xs text-slate-500">{{ ph_date($other->published_at) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts.public>
