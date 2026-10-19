{{--
    The original vs amended variance grid's own cells (GridDefinition::
    cellsPartial): each side's variance and value washed in that side's colour,
    as PumpIT's Stock Recon Balancing screen colours them — red short, yellow
    over the item's allowance, good within it. The procedure decides the tone
    (OriginalTone, AmendedTone); this only draws it. Everything else is the
    default.
--}}
@switch($column->key)
    @case('QtyVarOriginal')
    @case('ValueVarOriginal')
        <x-tone-value :tone="$row->OriginalTone ?? null">@include('grid._cell', ['row' => $row, 'column' => $column])</x-tone-value>
        @break
    @case('QtyVar')
    @case('ValueVar')
        <x-tone-value :tone="$row->AmendedTone ?? null">@include('grid._cell', ['row' => $row, 'column' => $column])</x-tone-value>
        @break
    @default
        @include('grid._cell', ['row' => $row, 'column' => $column])
@endswitch
