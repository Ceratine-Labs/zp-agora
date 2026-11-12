@if (in_array($column->key, ['CostPrice', 'Margin', 'FleetMargin', 'HandlingFee', 'SalesVariance'], true))
    {{ \App\Support\Format::n($row->{$column->key}, 4) }}
@else
    @include('grid._cell', ['row' => $row, 'column' => $column])
@endif
