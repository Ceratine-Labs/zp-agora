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
      §3.7  a title or reference value navigates. TWO halves, because they are
            two different jobs. The row's OWN resource is the shell's: the
            definition declares which column carries the name (`link: true`)
            and where the row lives (`rowUrl()`), and the shell draws the
            anchor — every list needs this and nothing about it is
            screen-specific. A value naming some OTHER record (a bag number to
            the drop-safe screen) stays in the CELLS partial, because which
            values do that is the screen's business and the shell cannot know.

            Until 7 Sep 2026 only the second half existed: `rowUrl()` was
            declared on GridDefinition, overridden on UserGrid, and read by
            nothing at all — so the user list rendered 90 people as plain text
            with no way to open any of them.

      —     what a row lets you DO is `rowActions()`, a trailing column the
            shell renders when the first row on the page offers any.

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
    // Asked of the first row, once: actions vary by the caller's permissions,
    // not row by row, and a column that came and went down the page would give
    // the table a ragged edge. GridDefinition::rowActions() says so too.
    $hasActions = $grid->rows->isNotEmpty() && $definition->rowActions($grid->rows->first()) !== [];
    $span = count($columns) + ($selectable ? 1 : 0) + ($hasActions ? 1 : 0);
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

                @if ($hasActions)
                    {{-- No resize handle and no sort: it is not a value, so
                         there is nothing to order by and nothing to widen. --}}
                    <th class="dg-actions-head"><span class="sr-only">Actions</span></th>
                @endif
            </tr>

            @if ($definition->filterable())
                @include('grid._filters', ['grid' => $grid, 'columns' => $columns, 'formId' => $formId, 'selectable' => $selectable])
            @endif
        </x-slot:head>

        @foreach ($grid->rows as $index => $row)
            @php($url = $expands ? $definition->detailUrl($row) : null)
            @php($rowUrl = $definition->rowUrl($row))
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
                        {{-- The anchor wraps the cell rather than replacing it,
                             so a linked column keeps whatever format it
                             declared — a linked date is still a formatted
                             date. A null rowUrl renders the value bare: a
                             reference that leads nowhere must not look like a
                             link. --}}
                        @if ($column->link && $rowUrl)
                            <a class="dg-row-link" href="{{ $rowUrl }}">@include($definition->cellsPartial(), ['row' => $row, 'column' => $column, 'grid' => $grid])</a>
                        @else
                            @include($definition->cellsPartial(), ['row' => $row, 'column' => $column, 'grid' => $grid])
                        @endif
                    </td>
                @endforeach

                @if ($hasActions)
                    <td class="dg-actions">
                        @foreach ($definition->rowActions($row) as $action)
                            {{-- `attributes` is how an action opts into
                                 behaviour the shell knows nothing about — a
                                 dialog, a confirmation. The href stays real, so
                                 the action works with scripting off. --}}
                            <a class="{{ ($action['primary'] ?? false) ? 'btn-primary sm' : 'btn sm' }}"
                               href="{{ $action['url'] }}"
                               @foreach (($action['attributes'] ?? []) as $name => $value) {{ $name }}="{{ $value }}" @endforeach
                            >{{ $action['label'] }}</a>
                        @endforeach
                    </td>
                @endif
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
                @if ($hasActions)<td class="dg-actions"></td>@endif
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

    @include('grid._extract', ['grid' => $grid, 'columns' => $columns, 'definition' => $definition, 'slug' => $slug])
</div>
