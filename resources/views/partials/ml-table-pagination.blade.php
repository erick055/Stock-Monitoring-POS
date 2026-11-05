<div class="ml-table-pagination" data-table-pagination data-table-anchor="{{ $anchor }}" data-table-page="{{ $pageName }}" data-table-size="{{ $sizeName }}" @if(($hideWhenEmpty ?? false) && $rows->total() === 0) hidden @endif>
    <form method="GET" action="{{ request()->url() }}#{{ $anchor }}">
        @foreach(request()->query() as $key => $value)
            @if($key !== $sizeName && $key !== $pageName && is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
        @endforeach
        <label for="{{ $sizeName }}">Rows per page</label>
        <select id="{{ $sizeName }}" name="{{ $sizeName }}">
            @foreach([20, 50, 100] as $size)<option value="{{ $size }}" @selected($rows->perPage() === $size)>{{ $size }}</option>@endforeach
        </select>
        <button type="submit" class="panel-action">Apply</button>
    </form>
    <span>{{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} of {{ number_format($rows->total()) }}</span>
    <nav aria-label="{{ $tableLabel }} pages">
        @if($rows->onFirstPage())<span aria-disabled="true">Previous</span>@else<a href="{{ $rows->previousPageUrl() }}" rel="prev">Previous</a>@endif
        <span>Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}</span>
        @if($rows->hasMorePages())<a href="{{ $rows->nextPageUrl() }}" rel="next">Next</a>@else<span aria-disabled="true">Next</span>@endif
    </nav>
</div>
