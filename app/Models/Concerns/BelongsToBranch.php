<?php

namespace App\Models\Concerns;

use App\Models\Scopes\BranchScope;
use App\Support\BranchContext;

/**
 * Adds the branch global scope and fills BranchId on create.
 *
 * A model that opts out (reference data that is genuinely estate-wide) sets
 * `$branchScoped = false` rather than removing the trait, so the intent is
 * visible on the model instead of inferred from its absence.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);

        static::creating(function ($model) {
            if ($model->BranchId !== null) {
                return;
            }

            $context = app(BranchContext::class);

            // A row with no branch of its own belongs to the group entity.
            // Never NULL — that is how legacy rows became unreportable.
            $model->BranchId = $context->isBranchWorkspace() && $context->id() !== null
                ? $context->id()
                : $context->groupId();
        });
    }

    /** Escape hatch for an estate-wide read; the query does its own scoping. */
    public function scopeAcrossBranches($query)
    {
        return $query->withoutGlobalScope(BranchScope::class);
    }
}
