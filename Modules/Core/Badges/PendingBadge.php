<?php

namespace Modules\Core\Badges;

use App\Support\Badges\BadgeProvider;
use App\Support\BranchContext;

/**
 * A figure the sign-in panel asks for whose module has not been built.
 *
 * The four rows on the sign-in screen come from four different epics — Imports
 * (T053), Exceptions (T058), Cash (T043) and Purchasing (T050). T007 needs the
 * panel now, so Core registers a placeholder for each key and the owning module
 * takes its key over the day it lands:
 *
 *     $this->app->make(BadgeRegistry::class)->register(OpenExceptionsBadge::class);
 *
 * Nothing in Core changes when that happens, which is the point of the
 * registry.
 *
 * A placeholder returns NULL, never 0. "All overnight loads clean" on a system
 * that has not looked would be a lie on the one screen everyone sees, and it is
 * the kind of lie that gets believed for months. An em dash and "not reported
 * yet" is the honest rendering, and `docs/components.md` already says a missing
 * figure is an em dash and never a zero.
 */
abstract class PendingBadge extends BadgeProvider
{
    /** The task that will answer this figure for real. Shown to the reader. */
    abstract protected function owner(): string;

    /** What the row says when the figure IS known. Kept here so the real
     *  provider that replaces this one has the wording to match. */
    abstract protected function known(int $count): string;

    final public function count(BranchContext $context): ?int
    {
        return null;
    }

    final public function label(?int $count): string
    {
        return $count === null
            ? $this->pending().' — not reported yet ('.$this->owner().')'
            : $this->known($count);
    }

    /** The subject of the sentence, so the pending row still reads as English. */
    abstract protected function pending(): string;
}
