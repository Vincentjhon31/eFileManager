<x-layouts.public>
    <x-slot:world>
        <x-world :payload="$world"
                 heading="Welcome"
                 :subheading="config('lgu.name')"
                 :corner-href="route('public.announcements')"
                 corner-label="Notices"
                 corner-icon="track" />
    </x-slot:world>

    {{--
        Below the town, the reason the town is there.

        Same content as before — pinned notices, latest notices, the disclosure
        shelves — in the same order, now in the same visual language as the
        drawing above it rather than in the staff interface's. Somebody who
        scrolled past the artwork is here to read something, so the type is
        ordinary readable type; it is the furniture around it that is pixellated.
    --}}
    <div class="px-stack">

        @if ($pinned->isNotEmpty())
            <section aria-labelledby="pinned-heading">
                <h2 class="px-eyebrow" id="pinned-heading">Pinned notices</h2>

                <div class="px-stack">
                    @foreach ($pinned as $announcement)
                        <article class="px-card px-pinned">
                            <div class="px-tags">
                                <x-px-badge :tone="$announcement->category->tone()"
                                            :label="$announcement->category->label()" />
                                <span class="px-meta">{{ ph_date($announcement->published_at) }}</span>
                            </div>

                            <h3 class="px-card-title">
                                <a href="{{ route('public.announcement', $announcement) }}">
                                    {{ $announcement->title }}
                                </a>
                            </h3>

                            <p>{{ $announcement->summary() }}</p>
                            <span class="px-meta">{{ $announcement->issuedBy() }}</span>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <section aria-labelledby="latest-heading">
            <div class="px-panel-top" style="margin-bottom: 16px">
                <h2 class="px-h2" id="latest-heading">Latest notices</h2>
                <a href="{{ route('public.announcements') }}" class="px-link">All notices →</a>
            </div>

            @if ($latest->isEmpty() && $pinned->isEmpty())
                <p class="px-empty">
                    <b>Nothing posted yet</b>
                    <span>There are no notices at the moment.</span>
                </p>
            @elseif ($latest->isEmpty())
                <p class="px-empty">
                    <b>Nothing further right now</b>
                    <span>The pinned notice above is current.</span>
                </p>
            @else
                <ul class="px-grid two">
                    @foreach ($latest as $announcement)
                        <li>
                            <article class="px-card" style="height: 100%">
                                <div class="px-tags">
                                    <x-px-badge :tone="$announcement->category->tone()"
                                                :label="$announcement->category->label()" />
                                    <span class="px-meta">{{ ph_date($announcement->published_at) }}</span>
                                </div>

                                <h3 class="px-card-title">
                                    <a href="{{ route('public.announcement', $announcement) }}">
                                        {{ $announcement->title }}
                                    </a>
                                </h3>

                                <p>{{ Str::limit($announcement->summary(), 130) }}</p>
                            </article>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="px-panel" aria-labelledby="disclosure-heading">
            <div class="px-panel-top">
                <div>
                    <p class="px-eyebrow" style="margin-bottom: 6px">DILG compliance</p>
                    <h2 class="px-h2" id="disclosure-heading">Full Disclosure Policy</h2>
                    <p class="px-lead">
                        Budgets, procurement, financial statements and ordinances, posted as required
                        by the Department of the Interior and Local Government.
                    </p>
                </div>

                <a href="{{ route('public.disclosure') }}" class="px-btn go">Open the board →</a>
            </div>

            <ul class="px-grid three" style="margin-top: 20px">
                @foreach ($shelves as $shelf)
                    <li>
                        <a class="px-shelf"
                           href="{{ route('public.disclosure', ['category' => $shelf['category']->value]) }}">
                            <b>{{ $shelf['category']->label() }}</b>
                            <em>{{ $shelf['count'] }} document{{ $shelf['count'] === 1 ? '' : 's' }}</em>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($recentDisclosures->isNotEmpty())
                <hr>

                <h3 class="px-h3">Recently posted</h3>

                <ul class="px-rows" style="margin-top: 12px">
                    @foreach ($recentDisclosures as $entry)
                        <li>
                            <div class="px-row">
                                {{-- The link is wrapped rather than being the
                                     flex child itself: .px-link draws its
                                     underline with an inset shadow, and on a
                                     grown flex item that underline stretches the
                                     whole cell instead of sitting under the
                                     words. --}}
                                <span class="px-grow">
                                    <a href="{{ route('public.download', $entry) }}" class="px-link">
                                        {{ $entry->title }}
                                    </a>
                                </span>
                                <span class="px-meta">
                                    {{ $entry->shelfLabel() }} · {{ ph_date($entry->published_at) }}
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-layouts.public>
