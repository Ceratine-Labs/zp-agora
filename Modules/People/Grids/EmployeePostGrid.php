<?php

namespace Modules\People\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;

/**
 * Setup → People and assets → Employee posts (T023), over
 * agora.usp_People_GridPosts.
 *
 * One row per post per branch, from PumpIT's BRN_EmployeePost, with how many
 * people hold it. A post's number is the branch's own, so the post's name is
 * what links: it opens the employee list at that branch filtered to the post.
 */
class EmployeePostGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.setup.employee-posts';
    }

    public function title(): string
    {
        return 'Employee posts';
    }

    public function blurb(): ?string
    {
        return 'The posts at each site. Numbered per branch: the same post carries a different number at another site.';
    }

    public function defaultSort(): ?string
    {
        return 'BranchName';
    }

    public function columns(): array
    {
        return [
            new GridColumn('PostDescription', 'Post', sort: 'PostDescription', link: true, wide: true),
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('ActiveEmployees', 'Active', format: 'number', sort: 'ActiveEmployees', total: true),
            new GridColumn('TotalEmployees', 'All', format: 'number', sort: 'TotalEmployees', total: true,
                title: 'Active and inactive, system accounts left out'),
            new GridColumn('PostNo', 'Post no', format: 'number', sort: 'PostNo'),
            new GridColumn('StockControl', 'Stock control', format: 'bool', options: ['Yes', 'No'],
                title: 'BRN_EmployeePost.StockControl — the posts whose holders carry stock losses'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_People_GridPosts', acceptsFilters: true);
    }

    /** The people holding this post at this site (feature-rules §3.7). */
    public function rowUrl(object $row): ?string
    {
        return route('app.setup.employees.index', [
            'branches' => [$row->BranchId],
            'f' => ['Post' => ['q' => $row->PostDescription, 'eq' => 1]],
        ]);
    }
}
