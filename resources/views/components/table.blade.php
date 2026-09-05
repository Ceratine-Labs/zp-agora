{{--
    A rendered result set.

    This is NOT <x-data-grid> (T014) — it has no header filters, no column
    persistence and no export, and a screen that needs those must wait for the
    grid rather than grow them here. What it does carry is the part of
    feature-rules §3 that applies to any table at all:

      - it scrolls inside its own container, so a wide result set never makes
        the page scroll sideways;
      - it says which procedure produced it (§3.4), because the customer is
        meant to be able to go and open that procedure;
      - it says how many rows it is showing, so a partial answer is never
        mistaken for the whole one;
      - it has a deliberate empty state rather than rendering as a header with
        nothing under it.

    `head` and the default slot are markup: the caller writes its own <th> and
    <tr>, because a column set is the screen's business.
--}}
@props([
    'procedure' => null,
    'count' => null,
    'total' => null,
    'empty' => 'Nothing to show.',
    'dense' => false,
])

{{-- Attributes land on the TABLE, not the wrapper: `data-row-detail` and the
     like describe the grid itself, and row-detail.js looks for them there. --}}
<div class="table-block">
    <div class="table-scroll">
        <table {{ $attributes->merge(['class' => 'dt '.($dense ? 'dense' : '')]) }}>
            @isset($head)<thead>{{ $head }}</thead>@endisset
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>

    @if ($count === 0)
        <p class="empty-state table-empty">{{ $empty }}</p>
    @endif

    @if ($procedure || $count !== null)
        <footer class="table-foot">
            @if ($count !== null)
                <span class="table-count">
                    @if ($total !== null && $total > $count)
                        Showing {{ number_format($count) }} of {{ number_format($total) }}
                    @else
                        {{ number_format($count) }} {{ \Illuminate\Support\Str::plural('row', $count) }}
                    @endif
                </span>
            @endif
            @if ($procedure)
                {{-- Rule 3.4: the customer reads this and goes straight to the
                     object they are allowed to change. --}}
                <span class="table-proc" title="The stored procedure that produced these rows">{{ $procedure }}</span>
            @endif
        </footer>
    @endif
</div>
