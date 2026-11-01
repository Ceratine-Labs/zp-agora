@if (in_array($column->key, ['ShiftNo', 'PreviousShift'], true))
    {{ \Modules\StockRecon\Support\ShiftLabel::show($row->{$column->key} ?? null) }}
@else
    @include('grid._cell', ['row' => $row, 'column' => $column])
@endif
