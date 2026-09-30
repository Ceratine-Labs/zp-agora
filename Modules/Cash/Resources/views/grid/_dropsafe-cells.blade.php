{{--
    The Drop safe bags grid's cells. Everything not named here falls through to
    the shared partial and is written from its declared format.

    ONE COLOURED COLUMN, the one the grid is about: Collected. "In the safe" is
    the mockup's own chip for a bag still waiting for the security company;
    "Collected" is good. A bag over the threshold with no reason says so under
    its amount — flagged, never hidden, and never a colour on its own.

    Props: $column, $row, $grid
--}}
@switch($column->key)
    @case('Status')
        @if (($row->Status ?? '') === 'In the safe')
            <x-chip tone="crit">In the safe</x-chip>
        @else
            <x-chip tone="good">Collected</x-chip>
        @endif
        @break

    @case('Amount')
        {{ \App\Support\Format::r($row->Amount) }}
        @if ($row->NeedsReason ?? false)
            <br><span class="muted">no reason given</span>
        @endif
        @break

    @case('CashierName')
        {{ $row->CashierName ?? $row->CashierCode }}
        @break

    @case('ManagerName')
        {{ $row->ManagerName ?? $row->ManagerCode }}
        @break

    @default
        @include('grid._cell', ['row' => $row, 'column' => $column])
@endswitch
