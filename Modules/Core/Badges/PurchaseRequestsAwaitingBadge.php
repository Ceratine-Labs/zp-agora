<?php

namespace Modules\Core\Badges;

use App\Support\Format;

/**
 * Purchase requests waiting for an approval. Owned by Purchasing (T050).
 *
 * The hundred-item threshold is the mockup's. The legacy system's own figure
 * was 607 when the design was drawn, which is why this row is on the sign-in
 * screen at all: a queue that deep is the first thing somebody should see.
 */
class PurchaseRequestsAwaitingBadge extends PendingBadge
{
    public function key(): string
    {
        return 'purchasing.requests.awaiting';
    }

    public function tone(?int $count): string
    {
        return match (true) {
            $count === null => 'neutral',
            $count > 100 => 'crit',
            $count > 0 => 'warn',
            default => 'good',
        };
    }

    public function route(): ?string
    {
        return 'app.purchasing.approvals';
    }

    protected function owner(): string
    {
        return 'T050';
    }

    protected function pending(): string
    {
        return 'Purchase approvals';
    }

    protected function known(int $count): string
    {
        return $count > 0
            ? Format::n($count).' purchase requests awaiting approval'
            : 'No purchase requests awaiting approval';
    }
}
