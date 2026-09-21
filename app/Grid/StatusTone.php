<?php

namespace App\Grid;

/**
 * A procedure's Status wording, mapped to a chip tone.
 *
 * By keyword rather than by an exhaustive list, because the procedures each
 * phrase their own Status and a new phrase must fall to neutral rather than to
 * a fatal. "Bank does not agree" is serious whichever report says it.
 *
 * This is lifted verbatim from `<x-reports::cell>`, which grew it first. It is
 * here now because the shared grid needs the same answer and a second copy of
 * a match table is a table that drifts — the first time somebody adds a phrase
 * to one of them, two screens start disagreeing about the same word. When this
 * merges, `Modules/Reports/Resources/views/components/cell.blade.php` should
 * call `StatusTone::of()` and delete its inline copy; that file belongs to
 * another lane, so it is named here rather than edited.
 */
final class StatusTone
{
    public static function of(string $status): string
    {
        $t = mb_strtolower($status);

        return match (true) {
            str_contains($t, 'never collected'), str_contains($t, 'went backwards'),
            str_contains($t, 'not started'), str_contains($t, 'no day-close'),
            // A critical line at zero is the emergency the screen exists for.
            // Before this it wore the same neutral chip as "In stock", which
            // is the colour doing no work at all.
            str_contains($t, 'out of stock'), str_contains($t, 'below cost') => 'crit',

            str_contains($t, 'never counted'), str_contains($t, 'no pos record'),
            str_contains($t, 'no cost price'), str_contains($t, 'no pos sell'),
            str_contains($t, 'missing'), str_contains($t, 'not confirmed'),
            str_contains($t, 'not balanced'), str_contains($t, 'do not agree'),
            str_contains($t, 'does not agree'), str_contains($t, 'over the'),
            str_contains($t, 'nothing arrived'), str_contains($t, 'nothing configured'),
            str_contains($t, 'not flagged') => 'serious',

            // "Low" on its own is too short to keyword safely — 'low' matches
            // "below", "allow" and "slow" — so it is matched exactly.
            $t === 'low', str_contains($t, 'not counted in'),
            str_contains($t, 'open'), str_contains($t, 'variance'),
            str_contains($t, 'gap'), str_contains($t, 'zero usage'),
            str_contains($t, 'no bags'), str_contains($t, 'switch is off'),
            str_contains($t, 'several approvers'), str_contains($t, 'over 15') => 'warn',

            // 'active' EXACTLY, never as a substring: "Inactive" contains it,
            // and usp_Core_GridUsers emits exactly that for somebody who can
            // no longer sign in. A substring match here would paint it green.
            str_contains($t, 'in stock'), $t === 'active',
            str_contains($t, 'closed'), str_contains($t, 'loaded'),
            str_contains($t, 'agrees'), str_contains($t, 'balanced'),
            str_contains($t, 'imported'), str_contains($t, 'within'),
            str_contains($t, 'day on day'), str_contains($t, 'normal') => 'good',

            default => 'neutral',
        };
    }
}
