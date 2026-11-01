@if ($column->key === 'ReconArea')
    @php
        $areaKey = (string) ($row->ReconArea ?? '');
    @endphp
    <span class="recon-run-type">
        <x-feature-art :art="app(\Modules\Core\Services\NavigationArtwork::class)->forRoute('app.recon.area', ['area' => $areaKey])" />
        <span>{{ config('recon.areas.'.$areaKey.'.label', $areaKey) }}</span>
    </span>
@elseif ($column->key === 'Validity')
    @php
        [$label, $tone] = match ($row->Validity ?? 'review') {
            'invalid' => ['Invalid', 'serious'],
            'partial' => ['Partly superseded', 'warn'],
            'current' => ['Current', 'good'],
            'history' => ['History', 'neutral'],
            default => ['Needs review', 'warn'],
        };
    @endphp
    <span class="recon-run-validity">
        <x-chip :tone="$tone">{{ $label }}</x-chip>
        @if (($row->InvalidRows ?? null) !== null && ($row->RemainingRows ?? null) !== null)
            <small>{{ \App\Support\Format::n($row->InvalidRows) }} superseded · {{ \App\Support\Format::n($row->RemainingRows) }} remaining</small>
        @endif
    </span>
@else
    @include('grid._cell', ['row' => $row, 'column' => $column])
@endif
