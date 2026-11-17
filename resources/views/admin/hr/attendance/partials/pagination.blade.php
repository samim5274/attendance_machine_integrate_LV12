{{-- Footer for a LengthAwarePaginator. Laravel's bootstrap-5 view already shows "Showing x to y of n". --}}
@if ($page->hasPages())
    <div class="card-footer pt-3">
        {{ $page->onEachSide(1)->links('pagination::bootstrap-5') }}
    </div>
@elseif ($page->total() > 0)
    <div class="card-footer small text-body-secondary py-2">
        Showing {{ $page->firstItem() }}–{{ $page->lastItem() }} of {{ $page->total() }}
    </div>
@endif
