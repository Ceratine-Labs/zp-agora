<?php

namespace App\Support\Badges;

use App\Support\Format;

/**
 * One live figure, with everything a surface needs to draw it.
 *
 * The bell, a menu item and the sign-in panel all want the same number and
 * none of them wants it in the same shape: the menu wants "12", the bell wants
 * a dot and a tone, the sign-in panel wants "12 exceptions open" and somewhere
 * to click. So a provider returns this, and each surface takes the part it
 * needs — rather than three surfaces each doing their own arithmetic on the
 * same query and drifting.
 *
 * `count` is nullable and that is load-bearing. Null means "nothing is known
 * here" — the module that owns the figure is not built yet, or its source did
 * not answer. It does NOT mean zero, and the difference is the whole reason
 * `value()` returns an em dash: "All overnight loads clean" and "we have not
 * looked" are different sentences, and only one of them is safe to put on a
 * sign-in screen.
 */
final class Badge
{
    public function __construct(
        public readonly string $key,
        public readonly ?int $count,
        public readonly string $label,
        /** neutral | good | warn | serious | crit */
        public readonly string $tone = 'neutral',
        /** A named route, resolved by the surface. Null renders as plain text. */
        public readonly ?string $route = null,
    ) {}

    /** The figure beside a menu item or inside a bell. An em dash when unknown. */
    public function value(): string
    {
        return Format::n($this->count);
    }

    /** True when there is a figure worth drawing attention to. */
    public function isRaised(): bool
    {
        return $this->count !== null && $this->count > 0;
    }

    public function isKnown(): bool
    {
        return $this->count !== null;
    }
}
