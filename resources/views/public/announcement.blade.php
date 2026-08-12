<x-layouts.public :title="$announcement->title" :description="$announcement->summary()">
    <div class="px-stack">

        <nav aria-label="Breadcrumb">
            <a href="{{ route('public.announcements') }}" class="px-link">← All notices</a>
        </nav>

        <article class="px-card" style="padding: 24px">
            <div class="px-tags">
                <x-px-badge :tone="$announcement->category->tone()" :label="$announcement->category->label()" />
                @if ($announcement->is_pinned)
                    <x-px-badge tone="amber" label="Important" />
                @endif
            </div>

            <h1 class="px-h1" style="margin-top: 12px">{{ $announcement->title }}</h1>

            <p class="px-meta" style="margin-top: 10px">
                Posted {{ ph_datetime($announcement->published_at) }} by {{ $announcement->issuedBy() }}
            </p>

            @if ($announcement->expires_at)
                <p class="px-badge t-amber" style="margin-top: 14px; padding: 9px 12px; white-space: normal">
                    This notice applies until {{ ph_datetime($announcement->expires_at) }}.
                </p>
            @endif

            {{-- Escaped and rendered as plain paragraphs. The body is typed by
                 staff into a textarea and shown to the public; letting markup
                 through would make every notice an opportunity to put a script on
                 a government page. --}}
            <div class="px-body" style="margin-top: 22px">
                @foreach (preg_split('/\R{2,}/', trim($announcement->body)) as $paragraph)
                    <p style="white-space: pre-line">{{ $paragraph }}</p>
                @endforeach
            </div>

            @if ($attachments->isNotEmpty())
                <hr style="height: 3px; margin: 26px 0 18px; border: 0;
                           background: repeating-linear-gradient(90deg, #c3b79f 0 6px, transparent 6px 12px)">

                <h2 class="px-h3">Attachments</h2>

                <ul class="px-rows" style="margin-top: 12px">
                    @foreach ($attachments as $entry)
                        <li>
                            <div class="px-row">
                                <span class="px-grow">
                                    <a href="{{ route('public.download', $entry) }}" class="px-link">
                                        {{ $entry->title }}
                                    </a>
                                    @if ($entry->description)
                                        <span class="px-meta" style="display: block; margin-top: 4px">
                                            {{ $entry->description }}
                                        </span>
                                    @endif
                                </span>
                                <span class="px-meta">
                                    {{ $entry->file?->kindLabel() }} · {{ $entry->file?->humanSize() }}
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </article>

        @if ($more->isNotEmpty())
            <section aria-labelledby="more-heading">
                <h2 class="px-eyebrow" id="more-heading">More notices</h2>

                <ul class="px-grid two">
                    @foreach ($more as $other)
                        <li>
                            <a class="px-card" href="{{ route('public.announcement', $other) }}">
                                <b style="font-family: var(--display); font-size: 16px">{{ $other->title }}</b>
                                <span class="px-meta" style="display: block; margin-top: 8px">
                                    {{ ph_date($other->published_at) }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts.public>
