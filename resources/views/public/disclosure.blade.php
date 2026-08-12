<x-layouts.public title="Full Disclosure"
                 :description="'Budgets, procurement, financial statements and ordinances of the '.config('lgu.name').'.'">
    <div class="px-stack">

        <div>
            <h1 class="px-h1">Full Disclosure Policy</h1>
            <p class="px-lead">
                Documents posted by the {{ config('lgu.name') }} in accordance with the Department of
                the Interior and Local Government's Full Disclosure Policy. All files are free to
                download. For records not posted here, file a request with the office concerned.
            </p>
        </div>

        {{-- Shelves. Clicking the one already chosen clears it, which is why the
             href drops the category when it is current. --}}
        <ul class="px-grid three">
            @foreach ($shelves as $shelf)
                @php $isCurrent = $category === $shelf['category']; @endphp
                <li>
                    <a class="px-shelf"
                       href="{{ route('public.disclosure', array_filter(['category' => $isCurrent ? null : $shelf['category']->value, 'year' => $year])) }}"
                       @if ($isCurrent) aria-current="true" @endif>
                        <b>{{ $shelf['category']->label() }}</b>
                        <span>{{ $shelf['category']->description() }}</span>
                        <em>{{ $shelf['count'] }} document{{ $shelf['count'] === 1 ? '' : 's' }}</em>
                    </a>
                </li>
            @endforeach
        </ul>

        <form method="GET" action="{{ route('public.disclosure') }}" class="px-form">
            @if ($category)
                <input type="hidden" name="category" value="{{ $category->value }}">
            @endif

            <div class="px-field">
                <label for="q">Search</label>
                <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Title or description…">
            </div>

            @if ($years->isNotEmpty())
                <div class="px-field">
                    <label for="year">Fiscal year</label>
                    <select id="year" name="year">
                        <option value="">Any year</option>
                        @foreach ($years as $option)
                            <option value="{{ $option }}" @selected($year === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <button type="submit" class="px-btn go">Search</button>

            @if ($search !== '' || $year || $category)
                <a href="{{ route('public.disclosure') }}" class="px-btn quiet">Show everything</a>
            @endif
        </form>

        <div class="px-tablewrap">
            <table class="px-table">
                <caption class="sr-only">Documents posted under the Full Disclosure Policy</caption>
                <thead>
                    <tr>
                        <th scope="col">Document</th>
                        <th scope="col">Category</th>
                        <th scope="col">Year</th>
                        <th scope="col">Posted</th>
                        <th scope="col" class="right">File</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($files as $entry)
                        <tr>
                            <td>
                                <a href="{{ route('public.download', $entry) }}" class="px-link">
                                    {{ $entry->title }}
                                </a>
                                @if ($entry->description)
                                    <span class="px-meta" style="display: block; margin-top: 4px">
                                        {{ $entry->description }}
                                    </span>
                                @endif
                            </td>

                            <td>{{ $entry->category->label() }}</td>
                            <td class="num">{{ $entry->fiscal_year ?? '—' }}</td>
                            <td class="num">{{ ph_date($entry->published_at) }}</td>

                            <td class="num right">
                                {{ $entry->file?->kindLabel() }}
                                <span style="display: block">{{ $entry->file?->humanSize() }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="padding: 44px 16px; text-align: center">
                                <b style="display: block; margin-bottom: 8px; font-family: var(--display); font-size: 17px">
                                    @if ($search !== '' || $year || $category)
                                        Nothing matched
                                    @else
                                        Nothing posted yet
                                    @endif
                                </b>
                                <span style="color: #5c5442">
                                    @if ($search !== '' || $year || $category)
                                        Nothing posted matches that search.
                                    @else
                                        Nothing has been posted here yet.
                                    @endif
                                </span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $files->links('vendor.pagination.pixel') }}
    </div>
</x-layouts.public>
