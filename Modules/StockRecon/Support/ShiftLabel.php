<?php

namespace Modules\StockRecon\Support;

/** Numbered PumpIT shifts; a counting area's schedule decides which are used. */
final class ShiftLabel
{
    public static function show(int|string|null $shift): string
    {
        if ($shift === null || $shift === '') {
            return '—';
        }

        $number = (string) $shift;
        $name = match ($number) {
            '1' => 'Day',
            '2' => 'Afternoon',
            '3' => 'Night',
            default => null,
        };

        return $name === null ? $number : $number.' ('.$name.')';
    }
}
