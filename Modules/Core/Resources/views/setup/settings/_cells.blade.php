{{--
    The system defaults grid's cells (T026). The group value is written by the
    setting's own TYPE — a money threshold as rand, a share as a percentage,
    litres to the millilitre — through SettingService::display(), which goes
    through App\Support\Format. Everything else falls through to the shared
    partial.

    Props: $column, $row, $grid
--}}
@switch($column->key)
    @case('GroupValue')
        {{ \Modules\Core\Services\SettingService::display($row->Type, $row->GroupValue) }}
        @break

    @case('UsedBy')
        @if ($row->UsedBy)
            {{ $row->UsedBy }}
        @else
            <span class="muted">Nothing reads it yet</span>
        @endif
        @break

    @case('Source')
        <x-chip :tone="$row->Source === 'edit' ? 'warn' : 'neutral'" :dot="false">{{ ['seed' => 'Agora default', 'pumpit' => 'From PumpIT', 'edit' => 'Changed here'][$row->Source] ?? $row->Source }}</x-chip>
        @break

    @default
        @include('grid._cell', ['row' => $row, 'column' => $column])
@endswitch
