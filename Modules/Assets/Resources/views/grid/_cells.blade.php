{{--
    The Assets grids' cells (T057). Everything falls through to the shared
    `grid._cell` except the chips whose tone the PROCEDURE decides.

    The shared keyword table (App\Grid\StatusTone) has no word for "Away from
    its site" or "Comeback", and teaching it asset words would put this
    module's meaning in a file every grid shares. So each procedure sends the
    tone beside the words — StatusTone, CheckTone, ResultTone, StateTone — and
    this partial draws what it was told. A row without a tone column falls
    back to the shared table, so the partial can never render less than the
    default would.

    Props: $column (App\Grid\GridColumn), $row (object), $grid (App\Grid\GridResult)
--}}
@php
    $tones = ['Status' => 'StatusTone', 'Check' => 'CheckTone', 'Result' => 'ResultTone', 'State' => 'StateTone'];
    $toneKey = $tones[$column->key] ?? null;
    $value = $row->{$column->key} ?? null;
@endphp

@if ($toneKey !== null && $value !== null && isset($row->{$toneKey}))
    <x-chip :tone="$row->{$toneKey}">{{ $value }}</x-chip>
@elseif ($column->key === 'IsComeback' && $value !== null)
    {{-- A comeback is the finding; a first call is the absence of one. --}}
    <x-chip :tone="$value === 'Yes' ? 'warn' : 'neutral'" :dot="$value === 'Yes'">{{ $value }}</x-chip>
@else
    @include('grid._cell', ['row' => $row, 'column' => $column])
@endif
