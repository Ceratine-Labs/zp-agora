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

    // A status word to a tone. Keyword rather than an exhaustive list: the
    // procedures each phrase their own Status, and a new phrase should fall to
    // neutral rather than to a fatal.
    $tone = function (string $text): string {
        $t = mb_strtolower($text);

        return match (true) {
            str_contains($t, 'never collected'), str_contains($t, 'went backwards'),
            str_contains($t, 'not started'), str_contains($t, 'no day-close') => 'crit',

            str_contains($t, 'missing'), str_contains($t, 'not confirmed'),
            str_contains($t, 'not balanced'), str_contains($t, 'do not agree'),
            str_contains($t, 'does not agree'), str_contains($t, 'over the'),
            str_contains($t, 'nothing arrived'), str_contains($t, 'nothing configured'),
            str_contains($t, 'not flagged') => 'serious',

            str_contains($t, 'open'), str_contains($t, 'variance'),
            str_contains($t, 'gap'), str_contains($t, 'zero usage'),
            str_contains($t, 'no bags'), str_contains($t, 'switch is off'),
            str_contains($t, 'several approvers'), str_contains($t, 'over 15') => 'warn',

            str_contains($t, 'closed'), str_contains($t, 'loaded'),
            str_contains($t, 'agrees'), str_contains($t, 'balanced'),
            str_contains($t, 'imported'), str_contains($t, 'within'),
            str_contains($t, 'day on day'), str_contains($t, 'normal') => 'good',

            default => 'neutral',
        };
    };
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
    <x-chip :tone="$tone((string) $value)">{{ $value }}</x-chip>
@else
    {{ $value }}
@endif
