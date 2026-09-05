<?php

namespace Modules\Core\Badges;

/**
 * Overnight loads that failed. Owned by Imports (T053) once that lands.
 *
 * Tone follows the mockup: any failure at all is critical, because an
 * overnight load that did not run means every figure on every screen behind
 * this one is yesterday's.
 */
class LoadsFailedBadge extends PendingBadge
{
    public function key(): string
    {
        return 'imports.loads.failed';
    }

    public function tone(?int $count): string
    {
        return match (true) {
            $count === null => 'neutral',
            $count > 0 => 'crit',
            default => 'good',
        };
    }

    public function route(): ?string
    {
        return 'app.imports.dashboard';
    }

    protected function owner(): string
    {
        return 'T053';
    }

    protected function pending(): string
    {
        return 'Overnight loads';
    }

    protected function known(int $count): string
    {
        return $count > 0
            ? $count.' overnight loads failed'
            : 'All overnight loads clean';
    }
}
