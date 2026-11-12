{{--
    One cell, rendered according to the column's declared type.

    The type is on the column definition in Config/config.php, so a report gets
    its money formatted as money and its litres to the millilitre without any
    screen knowing which report it is showing.

    Every number goes through App\Support\Format — never number_format here —
    because the server and the browser have to agree character for character
    and Format is one half of that pair. A missing figure is an em dash, never
    R0.00: "we do not have this" and "this is zero" are different answers.
--}}
@props(['type' => 'text', 'value' => null])

@php
    $blank = $value === null || $value === '';
@endphp

@if ($blank && $type !== 'bool')
    <span class="muted">—</span>
@elseif ($type === 'money')
    {{ \App\Support\Format::r($value) }}
@elseif ($type === 'litres')
    {{ \App\Support\Format::litres($value) }}
@elseif ($type === 'number')
    {{-- Whole numbers stay whole; a quantity that carries decimals keeps three,
         which is the millilitre / gram the rest of the system works to. --}}
    {{ \App\Support\Format::n($value, fmod((float) $value, 1.0) === 0.0 ? 0 : 3) }}
@elseif ($type === 'date')
    <span class="mono">{{ \Illuminate\Support\Carbon::parse($value)->toDateString() }}</span>
@elseif ($type === 'datetime')
    <span class="mono">{{ \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i') }}</span>
@elseif ($type === 'bool')
    <x-chip :tone="$value ? 'good' : 'neutral'">{{ $value ? 'Yes' : 'No' }}</x-chip>
@elseif ($type === 'chip')
    <x-chip :tone="\App\Grid\StatusTone::of((string) $value)">{{ $value }}</x-chip>
@else
    {{ $value }}
@endif
