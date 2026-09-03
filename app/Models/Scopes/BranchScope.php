<?php

namespace App\Models\Scopes;

use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Branch scoping, applied automatically to every BaseModel query.
 *
 * In the Branch workspace this pins to exactly one site. In Head Office it
 * limits to the sites the signed-in user is granted, and applies nothing at
 * all when the user is granted everything — an unfiltered query is cheaper
 * than an `IN` list of all 31 branches, and reads the same.
 *
 * Rows carrying the GROUP branch id are always visible: menus, settings and
 * reference data live there, and a branch user still needs to see the menu.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(BranchContext::class);

        if ($context->scopeSuspended()) {
            return;
        }

        $table = $model->getTable();
        $group = $context->groupId();

        if ($context->isBranchWorkspace() && $context->id() !== null) {
            $builder->whereIn("{$table}.BranchId", array_unique([$context->id(), $group]));

            return;
        }

        $allowed = $context->allowed();

        if ($allowed !== []) {
            $builder->whereIn("{$table}.BranchId", array_unique([...$allowed, $group]));
        }
    }
}
