<x-layouts.public title="Notices">
    <div class="px-stack">

        <div>
            <h1 class="px-h1">Public notices</h1>
            <p class="px-lead">Everything the {{ config('lgu.name') }} has posted, newest first.</p>
        </div>

        {{-- An ordinary form with GET parameters: every result is a link that can
             be sent to somebody else, and none of it needs JavaScript. --}}
        <form method="GET" action="{{ route('public.announcements') }}" class="px-form">
            <div class="px-field">
                <label for="q">Search</label>
                <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Keyword…">
            </div>

            <div class="px-field">
                <label for="category">Kind</label>
                <select id="category" name="category">
                    <option value="">All</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}" @selected($category === $option)>
                            {{ $option->label() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="px-btn go">Search</button>

            @if ($search !== '' || $category)
                <a href="{{ route('public.announcements') }}" class="px-btn quiet">Clear</a>
            @endif
        </form>

        <ul class="px-stack" style="list-style: none; margin: 0; padding: 0">
            @forelse ($announcements as $announcement)
                <li>
                    <article class="px-card @if ($announcement->is_pinned) px-pinned @endif">
                        <div class="px-tags">
                            <x-px-badge :tone="$announcement->category->tone()"
                                        :label="$announcement->category->label()" />
                            @if ($announcement->is_pinned)
                                <x-px-badge tone="amber" label="Important" />
                            @endif
                            <span class="px-meta">{{ ph_date($announcement->published_at) }}</span>
                        </div>

                        <h2 class="px-card-title">
                            <a href="{{ route('public.announcement', $announcement) }}">
                                {{ $announcement->title }}
                            </a>
                        </h2>

                        <p>{{ $announcement->summary() }}</p>
                        <span class="px-meta">{{ $announcement->issuedBy() }}</span>
                    </article>
                </li>
            @empty
                <li>
                    <p class="px-empty">
                        @if ($search !== '' || $category)
                            <b>Nothing matched</b>
                            <span>No notices match that search. Try a shorter word.</span>
                        @else
                            <b>Nothing posted yet</b>
                            <span>There are no notices at the moment.</span>
                        @endif
                    </p>
                </li>
            @endforelse
        </ul>

        {{ $announcements->links('vendor.pagination.pixel') }}
    </div>
</x-layouts.public>
