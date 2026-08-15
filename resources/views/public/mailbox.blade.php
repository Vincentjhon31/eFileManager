<x-layouts.public title="Mailbox"
                 description="Recent notices and Full Disclosure documents from the {{ config('lgu.name') }}, newest first.">
    <div class="px-stack">

        <div>
            <h1 class="px-h1">The mailbox</h1>
            <p class="px-lead">
                Everything the {{ config('lgu.name') }} has put out lately — notices from the
                offices and documents posted to the Full Disclosure board — in the order it came
                out. Nothing here needs an account.
            </p>
        </div>

        {{-- One list, two kinds of thing. The badge says which, so a reader can
             tell a notice from a document without opening either. --}}
        <ul class="px-stack" style="list-style: none; margin: 0; padding: 0">
            @forelse ($items as $item)
                <li>
                    @if ($item instanceof \App\Models\Announcement)
                        <article class="px-card @if ($item->is_pinned) px-pinned @endif">
                            <div class="px-tags">
                                <x-px-badge tone="blue" label="Notice" />
                                <x-px-badge :tone="$item->category->tone()" :label="$item->category->label()" />
                                @if ($item->is_pinned)
                                    <x-px-badge tone="amber" label="Important" />
                                @endif
                                <span class="px-meta">{{ ph_date($item->published_at) }}</span>
                            </div>

                            <h2 class="px-card-title">
                                <a href="{{ route('public.announcement', $item) }}">{{ $item->title }}</a>
                            </h2>

                            <p>{{ $item->summary() }}</p>
                            <span class="px-meta">{{ $item->issuedBy() }}</span>
                        </article>
                    @else
                        <article class="px-card">
                            <div class="px-tags">
                                <x-px-badge tone="green" label="Document" />
                                <x-px-badge tone="slate" :label="$item->shelfLabel()" />
                                <span class="px-meta">{{ ph_date($item->published_at) }}</span>
                            </div>

                            <h2 class="px-card-title">
                                <a href="{{ route('public.download', $item) }}">{{ $item->title }}</a>
                            </h2>

                            @if ($item->description)
                                <p>{{ $item->description }}</p>
                            @endif

                            <span class="px-meta">
                                {{ $item->file?->kindLabel() }} · {{ $item->file?->humanSize() }} ·
                                downloads and saves to your device
                            </span>
                        </article>
                    @endif
                </li>
            @empty
                <li>
                    <p class="px-empty">
                        <b>The mailbox is empty</b>
                        <span>Nothing has been posted yet. Check back, or look through the board below.</span>
                    </p>
                </li>
            @endforelse
        </ul>

        {{-- Past the recent post is the archive, which is where searching and
             filtering properly live. --}}
        <div class="px-card">
            <h2 class="px-card-title">Looking for something older?</h2>
            <p>
                This is the recent post only. Everything the municipality has ever posted is on
                the two full lists, both of which can be searched and filtered.
            </p>
            <div class="px-tags">
                <a href="{{ route('public.announcements') }}" class="px-btn go">All notices</a>
                <a href="{{ route('public.disclosure') }}" class="px-btn quiet">Full Disclosure board</a>
            </div>
        </div>
    </div>
</x-layouts.public>
