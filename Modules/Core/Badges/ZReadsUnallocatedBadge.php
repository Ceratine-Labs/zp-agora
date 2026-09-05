<?php

namespace Modules\Core\Badges;

/**
 * Z-reads captured but not yet allocated to a shift and an operator.
 * Owned by Cash (T043) once that lands.
 *
 * An unallocated Z-read is a day that cannot be closed, so any number above
 * zero is a warning rather than a note.
 */
class ZReadsUnallocatedBadge extends PendingBadge
{
    public function key(): string
    {
        return 'cash.zreads.unallocated';
    }

    public function route(): ?string
    {
        return 'app.cash.zread';
    }

    protected function owner(): string
    {
        return 'T043';
    }

    protected function pending(): string
    {
        return 'Z-read allocation';
    }

    protected function known(int $count): string
    {
        return $count > 0
            ? $count.' Z-reads unallocated'
            : 'All Z-reads allocated';
    }
}
