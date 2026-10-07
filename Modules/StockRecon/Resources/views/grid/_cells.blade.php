{{--
    The exceptions grid's own cells (GridDefinition::cellsPartial): the two
    code columns are coloured badges that open the codes modal at their
    entry (Ryan, 7 Oct 2026: "put more life to the exceptions table, identify
    different codes with their colors"). Everything else is the default.
--}}
@switch($column->key)
    @case('ExceptionCode')
    @case('AuditCode')
        @if (($code = (string) ($row->{$column->key} ?? '')) !== '')
            @include('stockrecon::partials.code', ['code' => $code])
        @endif
        @break
    @default
        @include('grid._cell', ['row' => $row, 'column' => $column])
@endswitch
