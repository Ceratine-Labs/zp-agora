<?php

namespace App\Grid\Definitions;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\EloquentSource;
use App\Grid\Sources\GridSource;
use Modules\Core\Models\Branch;

/**
 * The estate, over Eloquent.
 *
 * The second half of "server-side sort and pagination over BOTH proc result
 * sets and Eloquent queries". It is also an honest example of when a grid does
 * NOT need a procedure: feature-rules §2 draws the line at exposure, and a list
 * of Agora's own Branch rows on a development surface is machinery. Wrapping it
 * in T-SQL would satisfy a rule that was about the customer being able to open
 * and change what they read.
 *
 * It is the grid that carries the header filters, because an Eloquent source
 * can answer them: the query builder IS the semantics, so there is no second
 * implementation to keep in step.
 */
class BranchGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.dev.grids:branches';
    }

    public function title(): string
    {
        return 'Branches';
    }

    public function blurb(): ?string
    {
        return 'Every site and administrative entity Agora knows about, straight off the model. '
            .'No procedure: nothing here is a figure the customer reads.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchId', label: 'Id', format: 'number', sort: 'BranchId', mono: true),
            new GridColumn(key: 'Name', label: 'Name', sort: 'Name'),
            new GridColumn(key: 'IsTrading', label: 'Trading', format: 'bool', sort: 'IsTrading'),
            new GridColumn(key: 'IsActive', label: 'Active', format: 'bool', sort: 'IsActive'),
            new GridColumn(key: 'SortOrder', label: 'Order', format: 'number', sort: 'SortOrder'),
            new GridColumn(key: 'BrandId', label: 'Brand', format: 'number', sort: 'BrandId', visible: false),
            new GridColumn(key: 'RegionId', label: 'Region', format: 'number', sort: 'RegionId', visible: false),
            new GridColumn(key: 'CreatedAt', label: 'Created', format: 'datetime', sort: 'CreatedAt', visible: false),
        ];
    }

    public function source(): GridSource
    {
        return new EloquentSource(
            // A closure, not a builder: a shared builder accumulates the last
            // request's where clauses and the second page of a filtered grid
            // comes back filtered twice.
            query: fn () => Branch::query()->acrossBranches(),
            searchable: ['Name'],
            sortable: [
                'BranchId' => 'BranchId',
                'Name' => 'Name',
                'IsTrading' => 'IsTrading',
                'IsActive' => 'IsActive',
                'SortOrder' => 'SortOrder',
                'BrandId' => 'BrandId',
                'RegionId' => 'RegionId',
                'CreatedAt' => 'CreatedAt',
            ],
        );
    }

    public function defaultSort(): ?string
    {
        return 'Name';
    }

    /** The estate list is not branch-scoped by a selector; it IS the branches. */
    public function branchSelector(): bool
    {
        return false;
    }

    public function selectable(): bool
    {
        return true;
    }

    public function rowKey(object $row): string|int|null
    {
        return isset($row->BranchId) ? (int) $row->BranchId : null;
    }
}
