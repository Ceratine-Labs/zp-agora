{{--
    One cell's contents, written according to the column's declared format.

    The SHELL owns the <td> and its classes; this owns what goes inside it. That
    split is the instruction on T014 and it is what lets a grid change how one
    column reads without touching the structure, and the structure change once
    for every grid.

    Every number goes through App\Support\Format — never number_format here —
    because the server and the browser have to agree character for character and
    Format is one half of that pair. A missing figure is an em dash, never
    R0.00: "we do not have this" and "this is zero" are different answers, and
    on a cashup they are a very different conversation.

    The vocabulary is the same as <x-reports::cell>'s, with the same meanings,
    plus the tile and margin formats the build plan names for T014 (rk, lk, pct,
    cpl, litres, delta, mono). A report that moves onto the grid keeps its
    column types unchanged.

    Props: $column (App\Grid\GridColumn), $row (object)
--}}
@php
    $value = $row->{$column->key} ?? null;
    $blank = $value === null || $value === '';
@endphp

@if ($blank && $column->format !== 'bool')
    <span class="muted">{{ \App\Support\Format::NOTHING }}</span>
@elseif ($column->format === 'money')
    {{ \App\Support\Format::r($value) }}
@elseif ($column->format === 'rk')
    {{ \App\Support\Format::rk($value) }}
@elseif ($column->format === 'lk')
    {{ \App\Support\Format::lk($value) }}
@elseif ($column->format === 'litres')
    {{ \App\Support\Format::litres($value) }}
@elseif ($column->format === 'cpl')
    {{ \App\Support\Format::cpl($value) }}
@elseif ($column->format === 'pct')
    {{ \App\Support\Format::pct($value) }}
@elseif ($column->format === 'delta')
    {{-- The caller decides which direction is good; the column cannot know
         whether a rise in this figure is the good news. GridColumn carries no
         `invert` yet, so this is the uninverted reading — a column where down
         is the good news needs that flag before it renders honestly. --}}
    <x-delta :value="$value" :invert="$column->options['invert'] ?? false" />
@elseif ($column->format === 'number')
    {{-- Whole numbers stay whole; a quantity carrying decimals keeps three,
         which is the millilitre / gram the rest of the system works to. --}}
    {{ \App\Support\Format::n($value, is_numeric($value) && fmod((float) $value, 1.0) === 0.0 ? 0 : 3) }}
@elseif ($column->format === 'date')
    <span class="mono">{{ \Illuminate\Support\Carbon::parse((string) $value)->toDateString() }}</span>
@elseif ($column->format === 'datetime')
    <span class="mono">{{ \Illuminate\Support\Carbon::parse((string) $value)->format('Y-m-d H:i') }}</span>
@elseif ($column->format === 'bool')
    <x-chip :tone="$value ? 'good' : 'neutral'">{{ $value ? 'Yes' : 'No' }}</x-chip>
@elseif ($column->format === 'chip')
    <x-chip :tone="\App\Grid\StatusTone::of((string) $value)">{{ $value }}</x-chip>
@else
    {{ $value }}
@endif
