@props(['label' => '', 'value' => '', 'note' => null, 'tone' => 'neutral'])

<div class="kpi tone-{{ $tone }}">
    <span class="kpi-label">{{ $label }}</span>
    <span class="kpi-value">{{ $value }}</span>
    @if ($note)<span class="kpi-note">{{ $note }}</span>@endif
</div>
