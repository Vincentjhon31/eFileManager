<x-layouts.public title="Full Disclosure"
                 description="Budgets, procurement, financial statements and ordinances of the {{ config('lgu.name') }}.">
    <div class="space-y-6">

        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Full Disclosure Policy</h1>
            <p class="mt-1 max-w-3xl text-sm leading-relaxed text-slate-600">
                Documents posted by the {{ config('lgu.name') }} in accordance with the Department of
                the Interior and Local Government's Full Disclosure Policy. All files are free to
                download. For records not posted here, file a request with the office concerned.
            </p>
        </div>

        {{-- Shelves --}}
        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($shelves as $shelf)
                @php $isCurrent = $category === $shelf['category']; @endphp
                <li>
                    <a href="{{ route('public.disclosure', array_filter(['category' => $isCurrent ? null : $shelf['category']->value, 'year' => $year])) }}"
                       @class([
                           'block rounded-lg border px-4 py-3 transition',
                           'border-blue-700 bg-blue-50' => $isCurrent,
                           'border-slate-200 bg-white hover:border-blue-700 hover:bg-blue-50' => ! $isCurrent,
                       ])
                       @if ($isCurrent) aria-current="true" @endif>
                        <span class="block text-sm font-medium text-slate-900">{{ $shelf['category']->label() }}</span>
                        <span class="mt-0.5 block text-xs text-slate-600">{{ $shelf['category']->description() }}</span>
                        <span class="mt-1 block text-xs font-medium text-slate-500">
                            {{ $shelf['count'] }} document{{ $shelf['count'] === 1 ? '' : 's' }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        <form method="GET" action="{{ route('public.disclosure') }}"
              class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
            @if ($category)
                <input type="hidden" name="category" value="{{ $category->value }}">
            @endif

            <div>
                <label for="q" class="block text-sm font-medium text-slate-700">Search</label>
                <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Title or description…"
                       class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-700 focus:ring-blue-700 sm:w-64">
            </div>

            @if ($years->isNotEmpty())
                <div>
                    <label for="year" class="block text-sm font-medium text-slate-700">Fiscal year</label>
                    <select id="year" name="year"
                            class="mt-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-700 focus:ring-blue-700">
                        <option value="">Any year</option>
                        @foreach ($years as $option)
                            <option value="{{ $option }}" @selected($year === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <button type="submit"
                    class="rounded-lg bg-blue-800 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-900">
                Search
            </button>

            @if ($search !== '' || $year || $category)
                <a href="{{ route('public.disclosure') }}" class="text-sm font-medium text-slate-600 hover:underline">
                    Show everything
                </a>
            @endif
        </form>

        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <caption class="sr-only">Documents posted under the Full Disclosure Policy</caption>
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">Document</th>
                        <th scope="col" class="px-4 py-3">Category</th>
                        <th scope="col" class="px-4 py-3">Year</th>
                        <th scope="col" class="px-4 py-3">Posted</th>
                        <th scope="col" class="px-4 py-3 text-right">File</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($files as $entry)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 align-top">
                                <a href="{{ route('public.download', $entry) }}"
                                   class="font-medium text-blue-800 hover:underline">
                                    {{ $entry->title }}
                                </a>
                                @if ($entry->description)
                                    <span class="mt-0.5 block text-xs text-slate-600">{{ $entry->description }}</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 align-top text-slate-600">{{ $entry->category->label() }}</td>

                            <td class="whitespace-nowrap px-4 py-3 align-top text-slate-600">
                                {{ $entry->fiscal_year ?? '—' }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-3 align-top text-slate-600">
                                {{ ph_date($entry->published_at) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-3 text-right align-top text-slate-600">
                                {{ $entry->file?->kindLabel() }}
                                <span class="block text-xs text-slate-500">{{ $entry->file?->humanSize() }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-slate-500">
                                @if ($search !== '' || $year || $category)
                                    Nothing posted matches that search.
                                @else
                                    Nothing has been posted here yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $files->links() }}
    </div>
</x-layouts.public>
