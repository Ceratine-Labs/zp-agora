{{--
    The default cells partial: every column written from its declared format,
    and nothing grid-specific.

    THE PATTERN. A grid whose cells need more than the format vocabulary ships
    its OWN `_cells` partial and names it from its GridDefinition:

        public function cellsPartial(): string
        {
            return 'cash::grid._cells';   // Modules/Cash/Resources/views/grid/_cells.blade.php
        }

    and that file switches on the column it has been handed, falling through to
    this one for everything it does not care about:

        @switch($column->key)
            @case('BagNo')
                <a href="{{ route('app.cash.dropsafe.show', $row->BagId) }}">{{ $row->BagNo }}</a>
                @break
            @default
                @include('grid._cell', ['row' => $row, 'column' => $column])
        @endswitch

    That is feature-rules §3.7 in practice: a title or a reference value
    navigates to the resource, as a route change, and it does so from the cells
    partial rather than from the shell — because which values name another
    record is the screen's business and the shell does not know.

    Props: $column (App\Grid\GridColumn), $row (object), $grid (App\Grid\GridResult)
--}}
@include('grid._cell', ['row' => $row, 'column' => $column])
