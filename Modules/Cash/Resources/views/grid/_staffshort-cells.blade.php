{{--
    The Staff shorts list's cells. Everything not named here falls through to
    the shared partial and is written from its declared format.

    ONE COLOURED COLUMN, the one the list is about: Status — waiting (warn),
    approved and not on a cashup (serious: it is owed and not yet charged),
    posted (good), declined (neutral: nothing is owed). The words go with the
    colour, never the colour alone.

    Props: $column, $row, $grid
--}}
@switch($column->key)
    @case('Status')
        <x-chip :tone="\Modules\Cash\Services\StaffShortService::statusTone($row->Status ?? null)">{{ $row->Status }}</x-chip>
        @break

    @case('EmployeeName')
        {{ $row->EmployeeName ?? $row->EmployeeCode }}
        <br><span class="muted mono">{{ $row->EmployeeCode }}</span>
        @break

    @default
        @include('grid._cell', ['row' => $row, 'column' => $column])
@endswitch
