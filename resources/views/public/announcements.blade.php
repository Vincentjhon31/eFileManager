<x-layouts.public title="Notices">
    <div class="space-y-6">

        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Public notices</h1>
            <p class="mt-1 text-sm text-slate-600">
                Everything the {{ config('lgu.name') }} has posted, newest first.
            </p>
        </div>

        {{-- An ordinary form with GET parameters: every result is a link that
             can be sent to somebody else, and none of it needs JavaScript. --}}
        <form method="GET" action="{{ route('public.announcements') }}"
              class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
            <div>
                <label for="q" class="block text-sm font-medium text-slate-700">Search</label>
                <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Keyword…"
                       class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-700 focus:ring-blue-700 sm:w-64">
            </div>

            <div>
                <label for="category" class="block text-sm font-medium text-slate-700">Kind</label>
                <select id="category" name="category"
                        class="mt-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-700 focus:ring-blue-700">
                    <option value="">All</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}" @selected($category === $option)>
                            {{ $option->label() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <button type="submit"
                    class="rounded-lg bg-blue-800 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-900">
                Search
            </button>

            @if ($search !== '' || $category)
                <a href="{{ route('public.announcements') }}" class="text-sm font-medium text-slate-600 hover:underline">
                    Clear
                </a>
            @endif
        </form>

        <ul class="space-y-4">
            @forelse ($announcements as $announcement)
                <li class="rounded-xl border border-slate-200 bg-white p-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-status-badge :tone="$announcement->category->tone()"
                                        :label="$announcement->category->label()" />
                        @if ($announcement->is_pinned)
                            <x-status-badge tone="amber" label="Important" />
                        @endif
                        <span class="text-xs text-slate-500">{{ ph_date($announcement->published_at) }}</span>
                    </div>

                    <h2 class="mt-2 text-lg font-semibold">
                        <a href="{{ route('public.announcement', $announcement) }}"
                           class="text-slate-900 hover:text-blue-800 hover:underline">
                            {{ $announcement->title }}
                        </a>
                    </h2>

                    <p class="mt-2 text-sm leading-relaxed text-slate-700">{{ $announcement->summary() }}</p>
                    <p class="mt-3 text-xs text-slate-500">{{ $announcement->issuedBy() }}</p>
                </li>
            @empty
                <li class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">
                    @if ($search !== '' || $category)
                        No notices match that search.
                    @else
                        There are no notices at the moment.
                    @endif
                </li>
            @endforelse
        </ul>

        {{ $announcements->links() }}
    </div>
</x-layouts.public>
