{{--
    The data grid: every user-facing result set in Agora.

    THE SPLIT, which is the instruction on T014 and the reason this file is one
    file: the SHELL owns structure — the wrapper, the toolbar, the header, the
    sort marks, the filter row, the `<td>` and its classes, the footer, the
    pager, the mobile cards, the extract drawer. The PARTIAL owns cell CONTENT,
    and a grid that needs a cell the format vocabulary cannot write ships its
    own `_cells` partial and changes nothing else. See resources/views/grid.

    What it satisfies, from feature-rules §3, and where:

      §3.1  typed header filters, from the column's declared format — the
            filter row below, submitted into the scope form by `form=`.
      §3.2  extract as CSV or XLSX, of what the user is LOOKING at, refused
            above the ceiling and sent to the export centre — the drawer.
      §3.3  the branches selector on head-office result sets — `grid._scope`,
            rendered only when the controller passes branches.
      §3.4  the procedure's name on the grid — carried by <x-table>, which
            already does this for every table.
      §3.5  a row opens its detail. Through `data-row-detail`, which
            row-detail.js already owns: it fetches once, keeps it, leaves
            clicks on inner links alone, and takes HTML back rather than JSON.
            The grid emits the contract and does not reimplement it.
      §3.6  column order, widths and text size are the user's and persist —
            data-grid.js posts them to agora.UserGridColumn.
      §3.7  a title or reference value navigates. From the CELLS partial, not
            from here: which values name another record is the screen's
            business and the shell does not know.

    A component never queries the database (plan §3.8). Everything arrives as
    one prop: `$grid`, a GridResult that GridService built.

    Props:
      grid      App\Grid\GridResult. Required.
      branches  the sites the selector offers, or null for none (branch
                workspace, or a grid that is not branch-scoped).
      actions   slot: buttons for the toolbar, right of the extract.
      bulk      slot: what to offer when rows are ticked. Rendered inside the
                selection bar, which is hidden until something is ticked.
--}}
@props([
    'grid',
    'branches' => null,
])

@php
    $definition = $grid->definition;
    $columns = $grid->visibleColumns();
    $selectable = $definition->selectable();
    $span = count($columns) + ($selectable ? 1 : 0);
    // The GridKey carries dots and a colon; an id and a CSS selector do not
    // want either. One slug, used for every handle on the grid.
    $slug = \Illuminate\Support\Str::slug($grid->key());
    $formId = 'dg-'.$slug.'-scope';
    $expands = $definition->detailMode() === 'expand';
    // Fixed layout is what makes a saved pixel width mean anything (§3.6):
    // under auto layout the browser re-divides the space and the width is only
    // a suggestion. Applied on LOAD as well as during a drag, or a returning
    // user's saved widths would be ignored until they touched a border again.
    $fixed = collect($columns)->contains(fn ($v) => $v->width !== null);
@endphp

<div {{ $attributes->merge(['class' => 'data-grid dg-text-'.$grid->textSize]) }}
     data-grid
     data-grid-key="{{ $grid->key() }}"
     data-grid-slug="{{ $slug }}"
     data-state-url="{{ $grid->stateUrl() }}">

    @include('grid._scope', ['grid' => $grid, 'branches' => $branches, 'formId' => $formId])

    {{-- The error state (feature-rules, proposed §C). A procedure that REFUSES
         — a range it cannot usefully answer — is a message to the person, not a
         500. GridService catches it, so every grid screen gets this without its
         controller remembering to. An unexpected fault is still a
         QueryException and still a 500, because it is one. --}}
    @if ($grid->refusal !== null)
        <x-notice tone="stop" title="This grid could not be run" style="margin-bottom:14px">
            <p>{{ $grid->refusal }}</p>
        </x-notice>
    @endif

    <div class="dg-bar">
        @if ($selectable)
            {{-- Hidden until something is ticked. A bar that is always there
                 saying "0 selected" is a permanent reminder of a thing nobody
                 is doing. --}}
            <div class="dg-selection" data-selection hidden>
                <strong data-selection-count>0</strong> selected
                @isset($bulk)<span class="dg-selection-actions">{{ $bulk }}</span>@endisset
                <button type="button" class="btn-ghost" data-selection-clear>Clear</button>
            </div>
        @endif

        <span class="dg-shown">
            @if ($grid->total === 0)
                No rows
            @else
                Showing {{ \App\Support\Format::n($grid->rows->count()) }}
                of {{ \App\Support\Format::n($grid->total) }}
            @endif
            <span class="dg-ms" title="How long the source took to answer">· {{ (int) round($grid->ms) }} ms</span>
        </span>

        <span class="dg-bar-gap"></span>

        @isset($actions){{ $actions }}@endisset

        @include('grid._chooser', ['grid' => $grid, 'slug' => $slug])

        <button type="button" class="btn-ghost" data-drawer-open="dg-{{ $slug }}-extract">Extract</button>
    </div>

    {{-- The loading state (feature-rules, proposed §C). These procedures run
         over big tables, and a grid that goes blank for four seconds reads as
         broken. Sorting, paging and filtering are navigations, so the browser
         is fetching a new page — this says so over the old rows, which stay
         readable, rather than replacing them with a spinner. It is `hidden`
         markup until data-grid.js has something to report; with no JavaScript
         the browser's own progress is the only signal there is, and that is
         the correct fallback rather than a lie. --}}
    <p class="dg-busy" data-busy hidden aria-live="polite">Running…</p>

    <x-table
        class="dg{{ $fixed ? ' is-fixed' : '' }}"
        :count="$grid->rows->count()"
        :total="$grid->total"
        :procedure="$definition->procedure()"
        empty="Nothing matched. Widen the scope above, or clear the column filters."
        {{-- Bound rather than wrapped in an @if: a Blade directive inside a
             component tag's attribute list does not parse. A null value is
             dropped from the bag, so a grid that does not expand emits no
             attribute at all — and row-detail.js selects on the attribute's
             presence, not its value. --}}
        :data-row-detail="$expands ? 'true' : null">

        <x-slot:head>
            <tr>
                @if ($selectable)
                    <th class="pick">
                        <input type="checkbox" data-check-all aria-label="Select every row on this page">
                    </th>
                @endif

                @foreach ($columns as $view)
                    @php($column = $view->column)
                    <th @class($column->cellClasses())
                        data-column="{{ $column->key }}"
                        @if ($view->style()) style="{{ $view->style() }}" @endif
                        @if ($grid->ariaSort($column)) aria-sort="{{ $grid->ariaSort($column) }}" @endif
                        @if ($column->title) title="{{ $column->title }}" @endif>

                        @if ($column->isSortable())
                            <a href="{{ $grid->sortUrl($column) }}">{{ $column->label }}@if ($grid->sort() === $column->sort)<span class="sort-arrow">{{ $grid->ascending() ? '▲' : '▼' }}</span>@endif</a>
                        @else
                            {{ $column->label }}
                        @endif

                        {{-- The drag handle for a resize. A span rather than a
                             pseudo-element because it has to be a hit target
                             and a pseudo-element cannot be one. --}}
                        <span class="dg-resize" data-resize="{{ $column->key }}" aria-hidden="true"></span>
                    </th>
                @endforeach
            </tr>

            @if ($definition->filterable())
                @include('grid._filters', ['grid' => $grid, 'columns' => $columns, 'formId' => $formId, 'selectable' => $selectable])
            @endif
        </x-slot:head>

        @foreach ($grid->rows as $index => $row)
            @php($url = $expands ? $definition->detailUrl($row) : null)
            <tr @if ($url) data-detail-url="{{ $url }}" @endif>
                @if ($selectable)
                    <td class="pick">
                        <input type="checkbox" data-check
                               value="{{ $definition->rowKey($row) }}"
                               aria-label="Select this row">
                    </td>
                @endif

                @foreach ($columns as $view)
                    @php($column = $view->column)
                    <td @class($column->cellClasses()) data-column="{{ $column->key }}">
                        @include($definition->cellsPartial(), ['row' => $row, 'column' => $column, 'grid' => $grid])
                    </td>
                @endforeach
            </tr>
        @endforeach

        @if ($grid->totals !== [] && $grid->rows->isNotEmpty())
            {{-- A totals row inside the body rather than a <tfoot>: <x-table>
                 owns the table's structure and gives the caller a head slot and
                 a body slot, and growing a third one here for a single grid
                 would put table markup back into a screen. The class is what
                 makes it read and stick as a footer. --}}
            <tr class="dg-total">
                @if ($selectable)<td class="pick"></td>@endif
                @foreach ($columns as $view)
                    @php($column = $view->column)
                    <td @class($column->cellClasses())>
                        @if (array_key_exists($column->key, $grid->totals))
                            @include('grid._cell', [
                                'column' => $column,
                                'row' => (object) [$column->key => $grid->totals[$column->key]],
                            ])
                        @elseif ($loop->first)
                            <span class="muted">{{ $grid->totalsAreGrand ? 'All rows' : 'This page' }}</span>
                        @endif
                    </td>
                @endforeach
            </tr>
        @endif
    </x-table>

    @include('grid._cards', ['grid' => $grid, 'slug' => $slug])

    @if ($grid->isPartial())
        <p class="dg-limit">
            Showing the first {{ \App\Support\Format::n($grid->rows->count()) }}
            of {{ \App\Support\Format::n($grid->total) }} rows — extract to see them all.
        </p>
    @endif

    @include('grid._pager', ['grid' => $grid])

    <x-drawer :id="'dg-'.$slug.'-extract'" title="Extract" copy
              note="The file is what you are looking at — your filters, your sort, your visible columns, in your order. It is produced by re-running the same source with the page opened up, so the numbers cannot disagree with the ones above.">

        @if ($grid->overCeiling())
            <x-notice tone="stop" title="Too big for a download">
                <p>This answer is {{ \App\Support\Format::n($grid->total) }} rows and the grid extracts up to
                   {{ \App\Support\Format::n($definition->exportCeiling()) }}. Narrow it above, or send it to the
                   export centre, which produces the same file out of the request cycle.</p>
            </x-notice>
        @else
            <dl class="dg-extract-facts">
                <dt>Rows</dt><dd>{{ \App\Support\Format::n($grid->total) }}</dd>
                <dt>Columns</dt><dd>{{ collect($columns)->map(fn ($v) => $v->column->label)->implode(', ') }}</dd>
                <dt>Sort</dt>
                <dd>{{ $grid->sort() ?? 'the source’s own order' }}@if ($grid->sort()), {{ $grid->ascending() ? 'ascending' : 'descending' }}@endif</dd>
            </dl>

            <div class="dg-extract-actions">
                <a class="btn-primary" href="{{ $grid->extractUrl('xlsx') }}" data-extract>Download .xlsx</a>
                <a class="btn-ghost" href="{{ $grid->extractUrl('csv') }}" data-extract>Download .csv</a>
            </div>

            <p class="dg-extract-note">
                Numbers come down as numbers and dates as dates, not as the formatted text on the screen —
                so the receiving column adds up and sorts without being cleaned first.
            </p>

            {{-- The copy button copies this. A textarea rather than a <pre>
                 because select-all inside one is a single keystroke, and on a
                 locked-down desktop where the download is blocked this is the
                 way the data still gets out. --}}
            <label class="dg-extract-label" for="dg-{{ $slug }}-csv">The first rows, as comma-separated text</label>
            <textarea id="dg-{{ $slug }}-csv" readonly spellcheck="false" rows="12">{{ collect($columns)->map(fn ($v) => $v->column->label)->implode(',') }}
@foreach ($grid->rows as $row){{ collect($columns)->map(function ($v) use ($row) {
    $value = $row->{$v->column->key} ?? '';
    return str_contains((string) $value, ',') ? '"'.str_replace('"', '""', (string) $value).'"' : $value;
})->implode(',') }}
@endforeach</textarea>
        @endif
    </x-drawer>
</div>
