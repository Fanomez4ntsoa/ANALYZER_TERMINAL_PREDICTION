{{-- Pagination du terminal : $paginator->links('terminal.partials.pagination') --}}
@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="pager-item" aria-disabled="true">‹ Précédente</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Précédente</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pager-item" aria-disabled="true">{{ $element }}</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pager-item num" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="num" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">Suivante ›</a>
        @else
            <span class="pager-item" aria-disabled="true">Suivante ›</span>
        @endif
    </nav>
@endif
