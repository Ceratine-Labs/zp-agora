<?php

namespace Modules\Assets\Http\Controllers\Concerns;

use App\Support\BranchContext;
use Illuminate\Support\Collection;
use Modules\Core\Models\Branch;

/**
 * The site questions every asset screen asks, answered once.
 */
trait ResolvesAssetScope
{
    /**
     * The sites a person may pick: active ones they are granted, head office
     * included — the group entity owns assets too (office furniture, the
     * company's vehicles). BranchScope narrows Branch to the person's grants,
     * so the picker and what the procedures return cannot drift apart.
     *
     * @return Collection<int, Branch>
     */
    protected function branches(): Collection
    {
        return Branch::query()
            ->where('IsActive', true)
            ->orderBy('Name')
            ->get();
    }

    /**
     * Which site a submitted form is about. In the branch workspace it is the
     * pinned site whatever the form says; in head office it is the form's, and
     * it is still checked, because a form field is not an authorisation.
     */
    protected function resolveBranch(?int $submitted, BranchContext $context): int
    {
        if ($context->isBranchWorkspace() && $context->id() !== null) {
            return $context->id();
        }

        abort_if($submitted === null || $submitted <= 0, 422, 'Say which site.');
        abort_unless($context->maySee($submitted), 403, 'You may not register assets for that site.');

        return $submitted;
    }

    /**
     * The grid's own branches selector belongs to head office (feature-rules §3.3).
     *
     * @return Collection<int, Branch>|null
     */
    protected function gridBranches(BranchContext $context): ?Collection
    {
        return $context->isBranchWorkspace() ? null : $this->branches();
    }
}
