{{--
    A figure that a correction may have changed: the old value struck through
    beside the new one when they differ, the figure alone when they do not.

    The audit trail's correction panel (2 Oct 2026) reads the way the customer
    report reads a correction, "<s>20</s> 0", so the eye lands on exactly the
    cells that moved and nowhere else. Numbers go through App\Support\Format.

    Props: before, after (numbers or null), dp (decimal places, default 0 —
    pass 3 for weighed stock), signed (colour a negative red, a positive amber:
    for a variance, where negative is short).
--}}
@props(['before' => null, 'after' => null, 'dp' => 0, 'signed' => false])

@php
    use App\Support\Format;

    $changed = abs((float) $before - (float) $after) > 0.0005 || (($before === null) !== ($after === null));
    $tone = function ($v) use ($signed): string {
        if (! $signed || $v === null) {
            return '';
        }

        return (float) $v < -0.0005 ? 'ba-short' : ((float) $v > 0.0005 ? 'ba-over' : '');
    };
    $fmt = fn ($v) => Format::n($v, (abs((float) $v - round((float) $v)) > 0.0005) ? max($dp, 3) : $dp);
@endphp

<span {{ $attributes->merge(['class' => 'ba'.($changed ? ' ba-changed' : '')]) }}>
    @if ($changed)<span class="ba-old {{ $tone($before) }}">{{ $fmt($before) }}</span><span class="ba-new {{ $tone($after) }}">{{ $fmt($after) }}</span>@else<span class="{{ $tone($after) }}">{{ $fmt($after) }}</span>@endif
</span>
