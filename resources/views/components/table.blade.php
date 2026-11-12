{{--
    A rendered result set.

    This is NOT <x-data-grid> (T014) — it has no server-side header filters or
    export. Signed-in users can save table appearance and column preferences;
    the grid remains the right choice for server-paged result sets. It carries
    the part of
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

    `fit` is for a table with more columns than a window has room for. It lets
    the numeric HEADINGS wrap and pins every numeric column to its content
    width, so the surplus goes to the columns carrying words instead of to
    eleven column headings — see the block above `table.dt.fit` for the
    measurements that motivated it. Height in the head once, against width on
    every row forever.

    `tools` adds click-to-sort and a
    per-column filter row, done in the BROWSER over the rows already on the
    page. That is the right shape here and the wrong shape in the grid — see
    the head of `table-tools.js` for the whole argument — and it is opt-in
    because it only makes sense on a table that arrived complete. A table whose
    rows were paged or truncated BY THE SERVER must not offer it: filtering page
    one of nine and calling the answer a filter is a lie.

    Three attributes ride through to the table for `tools` to read. They are
    attributes rather than props because they describe the rows, which are the
    caller's markup, not this component's:

      data-tt-page="50"      cut the rows into pages of fifty, in the browser,
                             over the set that already arrived. Every row stays
                             in the document and in any form around it — a page
                             is a way of looking, not a scope — so this is safe
                             on a table whose tick boxes decide what a commit
                             writes, which server paging would not be.
      data-tt-pills="Value"  on a <th>: show that column as a strip of pills
                             above the table instead of as a tick list in the
                             filter row, opening scoped to that value.
                             `data-tt-pill-order="A|B|C"` fixes their order.
      data-tone="good"       on a <td>: the chip tone that column's pill takes.
--}}
@props([
    'procedure' => null,
    'count' => null,
    'total' => null,
    'empty' => 'Nothing to show.',
    'dense' => false,
    'fit' => false,
    'tools' => false,
    'layoutKey' => null,
])

@php
    // The route and the headings identify the kind of table rather than a
    // particular record, so the same preference follows the user to other
    // records and other devices. Callers can name ambiguous tables explicitly.
    // Include empty headings and spans: an optional selection column has no
    // text, but changes every following column's position.
    $layoutMarkup = preg_replace_callback('/<th\b([^>]*)>/i', static function ($match) {
        preg_match('/\bcolspan=["\']?(\d+)/i', $match[1], $span);
        return '|column:'.($span[1] ?? '1').'|';
    }, (string) ($head ?? ''));
    $layoutHead = trim(preg_replace('/\s+/u', ' ', str_replace(['▲', '▼'], '', strip_tags($layoutMarkup))));
    $layoutName = $layoutKey ?? (string) request()->route()?->getName();
    $layoutHash = hash('sha256', $layoutName.'|'.$layoutHead);
    $layoutSignature = hash_hmac('sha256', $layoutHash, (string) config('app.key'));
@endphp

{{-- Attributes land on the TABLE, not the wrapper: `data-row-detail` and the
     like describe the grid itself, and row-detail.js looks for them there. --}}
<div class="table-block" @auth data-table-layout-url="{{ route('app.table-layouts.show', ['key' => $layoutHash, 'signature' => $layoutSignature]) }}" data-table-layout-read-url="{{ route('app.table-layouts.read') }}" @endauth>
    <div class="table-scroll">
        <table {{ $attributes->merge(['class' => 'dt '.($dense ? 'dense ' : '').($fit ? 'fit' : '')]) }}
               @if ($tools) data-table-tools @endif>
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
