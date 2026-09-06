<?php

namespace Modules\Core\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;

/**
 * Setup → People and assets → Users and access (T028).
 *
 * The people who can sign in, what they may do, and how much of the estate
 * they can see. With 88 of them the useful questions are all filters — "branch
 * users who have never signed in", "everyone holding Finance" — so this is the
 * first grid to declare @FiltersJson.
 */
class UserGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.setup.users';
    }

    public function title(): string
    {
        return 'Users and access';
    }

    public function blurb(): ?string
    {
        return 'Everyone who can sign in to Agora. Head office or branch is derived from how many '
            .'branches a person is granted — exactly one is a site, more than one is head office.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'UserCode', label: 'Code', sort: 'UserCode', mono: true),
            new GridColumn(key: 'UserName', label: 'Name', sort: 'UserName', wide: true),
            new GridColumn(key: 'EmailAddress', label: 'Email', sort: 'EmailAddress', wide: true),
            new GridColumn(key: 'UserType', label: 'Type', sort: 'UserType'),
            new GridColumn(key: 'PrimaryRole', label: 'Primary role', sort: 'PrimaryRole'),
            new GridColumn(key: 'RoleNames', label: 'All roles', wide: true, visible: false),
            new GridColumn(key: 'BranchCount', label: 'Branches', format: 'number', sort: 'BranchCount'),
            new GridColumn(key: 'LastSignInAt', label: 'Last sign-in', format: 'datetime', sort: 'LastSignInAt'),
            new GridColumn(key: 'Status', label: 'Status', format: 'chip'),
        ];
    }

    /**
     * Keyed by COLUMN, which is the contract the base class states and what
     * the header row looks each column up by. A plain list renders no filters
     * at all and fails silently.
     *
     * GridFilter keeps values, not labels — its constructor runs the options
     * through array_values(array_unique(...)) and drops keys — so the set
     * options below are the values the column actually holds. A labelled
     * option would send the label and match nothing.
     *
     * @return array<string, GridFilter>
     */
    public function filters(): array
    {
        return [
            'UserName' => new GridFilter(column: 'UserName', type: 'text'),
            'EmailAddress' => new GridFilter(column: 'EmailAddress', type: 'text'),
            'UserType' => new GridFilter(column: 'UserType', type: 'set', options: ['ho', 'branch']),
            'RoleNames' => new GridFilter(column: 'RoleNames', type: 'text'),
            'Status' => new GridFilter(column: 'Status', type: 'text'),
        ];
    }

    public function source(): GridSource
    {
        // acceptsFilters: true — the procedure declares @FiltersJson. Passing
        // false here and filtering in PHP is the split feature-rules §3.2
        // exists to prevent, and ProcedureSource throws rather than allow it.
        return new ProcedureSource('agora.usp_Core_GridUsers', acceptsFilters: true);
    }

    public function defaultSort(): ?string
    {
        return 'UserName';
    }

    /** A user is a group-level row; the branch selector would be meaningless. */
    public function branchSelector(): bool
    {
        return false;
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.setup.users.show', ['user' => $row->Id]);
    }
}
