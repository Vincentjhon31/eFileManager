{{--
    Pagination, in the pixel palette.

    Laravel's bundled views are Tailwind, and on a page where everything else has
    hard 3px edges a row of rounded grey pills is the one thing that looks like it
    wandered in from another site. Small enough to be worth owning outright.

    Kept as close to the default's behaviour as possible: the same window of page
    numbers, the same ellipses, the same disabled states, the same aria. Only the
    markup and the classes are ours.
--}}
@if ($paginator->hasPages())
    <nav class="px-pager" role="navigation" aria-label="{{ __('Pagination Navigation') }}">
        @if ($paginator->onFirstPage())
            <span class="step off" aria-disabled="true">‹ Back</span>
        @else
            <a class="step" href="{{ $paginator->previousPageUrl() }}" rel="prev"
               aria-label="{{ __('pagination.previous') }}">‹ Back</a>
        @endif

        @foreach ($elements as $element)
            {{-- An ellipsis, which Laravel hands over as a bare string. --}}
            @if (is_string($element))
                <span class="gap" aria-hidden="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="page" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="page" href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                            {{ $page }}
                        </a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="step" href="{{ $paginator->nextPageUrl() }}" rel="next"
               aria-label="{{ __('pagination.next') }}">Next ›</a>
        @else
            <span class="step off" aria-disabled="true">Next ›</span>
        @endif

        <span class="count">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </span>
    </nav>
@endif
