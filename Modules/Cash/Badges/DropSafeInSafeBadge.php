<?php

namespace Modules\Cash\Badges;

use App\Support\Badges\BadgeProvider;
use App\Support\BranchContext;
use Modules\Cash\Services\DropSafeService;

/**
 * "In the safe": the bags dropped and not yet collected, in the person's scope
 * — the pinned site in the branch workspace, the sites they may see in head
 * office.
 *
 * This is the drop safe step of the branch console (the mockup's "Drop safe ·
 * 3 bags not collected / All bags collected"). The console itself is T029 and
 * is not built; when it is, it asks the badge registry for this key and gets
 * the figure, the wording and the link without knowing anything about the
 * drop safe. A collection that empties the safe turns it good.
 *
 * The figure is agora.usp_Cash_DropSafeInSafe's, through DropSafeService — the
 * same count the Drop safe screens show beside their chip.
 */
class DropSafeInSafeBadge extends BadgeProvider
{
    public function __construct(protected DropSafeService $service) {}

    public function key(): string
    {
        return DropSafeService::BADGE;
    }

    public function count(BranchContext $context): ?int
    {
        $sites = $context->isBranchWorkspace() && $context->id() !== null
            ? [$context->id()]
            : $context->allowed();

        return (int) $this->service->inSafe($sites)->sum(fn (object $row) => (int) $row->Bags);
    }

    public function label(?int $count): string
    {
        return $count === null
            ? 'Drop safe — could not be counted'
            : DropSafeService::stepText($count);
    }

    public function route(): ?string
    {
        return 'app.cash.dropsafe';
    }
}
