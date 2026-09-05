<?php

namespace Modules\Core\Badges;

/**
 * Exceptions still open. Owned by Exceptions (T058) once that lands, and the
 * same figure the bell in the app bar shows (T011).
 *
 * The eight-item threshold is the mockup's, not an invention: below it the
 * register is a working queue, above it somebody has stopped clearing it.
 */
class ExceptionsOpenBadge extends PendingBadge
{
    public function key(): string
    {
        return 'exceptions.open';
    }

    public function tone(?int $count): string
    {
        return match (true) {
            $count === null => 'neutral',
            $count > 8 => 'crit',
            $count > 0 => 'warn',
            default => 'good',
        };
    }

    public function route(): ?string
    {
        return 'app.exceptions.index';
    }

    protected function owner(): string
    {
        return 'T058';
    }

    protected function pending(): string
    {
        return 'Exception register';
    }

    protected function known(int $count): string
    {
        return $count > 0
            ? $count.' exceptions open'
            : 'No exceptions open';
    }
}
