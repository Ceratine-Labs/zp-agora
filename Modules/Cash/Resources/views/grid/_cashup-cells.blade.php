{{--
    The Cashups grid's cells. Everything not named here falls through to the
    shared partial and is written from its declared format.

    THREE COLOURED COLUMNS, and they are the three a manager scans for:

      Variance  good inside a rand, warn inside the tolerance
                (cash.cashup.variance_tolerance), crit beyond it — the chip is
                the figure, so the colour never stands in for the number
      Z-read    whether the Z-read allocated to the till and shift agrees
      Cashup    Open (needs confirming), Confirmed (needs a manager's close),
                Closed or Day balanced (closed off in PumpIT)

    Props: $column, $row, $grid
--}}
@switch($column->key)
    @case('Variance')
        <x-chip :tone="app(\Modules\Cash\Services\CashupService::class)->varianceTone($row->Variance)" :dot="false">{{ \App\Support\Format::r($row->Variance) }}</x-chip>
        @break

    @case('ZreadCheck')
        <x-chip :tone="\Modules\Cash\Services\CashupService::zreadTone($row->ZreadCheck)">{{ $row->ZreadCheck }}</x-chip>
        @break

    @case('Status')
        <x-chip :tone="\Modules\Cash\Services\CashupService::statusTone($row->Status)">{{ $row->Status }}</x-chip>
        @if ($row->FiguresMoved ?? false)
            <br><span class="muted">figures moved since confirmed</span>
        @endif
        @break

    @case('TillDescription')
        {{ $row->TillDescription ?? ('Till '.$row->TillNo) }}
        @break

    @case('EmployeeName')
        {{ $row->EmployeeName ?? $row->EmployeeCode }}
        @break

    @default
        @include('grid._cell', ['row' => $row, 'column' => $column])
@endswitch
