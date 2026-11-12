<?php

namespace Modules\Masters\Grids;

use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * One reference master listing (T022): a read-only grid over a PumpIT master.
 *
 * Each master is a resource of its own — its slug names the routes
 * (`app.setup.{slug}.index` / `.show`), the GridKey (`app.setup.{slug}`) and
 * the permission (`masters.{permission}.view`). Nothing is edited: the truth
 * is PumpIT's, and Agora does not write it (v1__02a explains why).
 *
 * Every row the procedure returns carries `RowKey`, the record's identity
 * within its branch as one string, and `BranchId`. The row's own page and its
 * detail panel are `show?key=RowKey`, answered by the SAME procedure called
 * with `@Key` — one definition of what the record is, not a second query that
 * could disagree with the grid.
 *
 * THE PROCEDURE IS THE CHECK. Each is passed `@Authorized` from the view
 * permission, worked out in source() — which runs for the page AND for the
 * extract — so the extract endpoint, which no route middleware guards per
 * grid, still cannot read a master its caller may not see.
 *
 * A per-branch master's procedure refuses to answer without a branch in scope
 * (MASTERS_BRANCH): every one of these is keyed per branch in PumpIT, and an
 * estate-wide list of till 1, till 1, till 1 is not an answer to anything.
 */
abstract class ReferenceMasterGrid extends GridDefinition
{
    /** The URL / route slug: `profit-centres`. */
    abstract public function slug(): string;

    /** The permission resource: `profitcentres` → masters.profitcentres.view. */
    abstract public function resource(): string;

    /** The procedure behind the grid, schema-qualified. */
    abstract public function procedureName(): string;

    /** Where the truth lives, as the customer would find it in SSMS. */
    abstract public function legacySource(): string;

    /** What one record is called, singular: "Profit centre". */
    abstract public function noun(): string;

    /** Kept per branch in PumpIT. Global masters (profit centres, standard categories) override. */
    public function perBranch(): bool
    {
        return true;
    }

    /**
     * What each value MEANS, by column key — the line under it on the detail
     * panel. Only where the reader cannot see it from the value.
     *
     * @return array<string, string>
     */
    public function meanings(): array
    {
        return [];
    }

    /** The page head's eyebrow: where the master sits in Setup. */
    public function eyebrow(): string
    {
        return 'Setup · The business';
    }

    /**
     * The tables a record's detail adds under its facts, in the order the
     * procedure returns them after its total when called with @Key:
     * `[title, what an empty one means]`.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function related(): array
    {
        return [];
    }

    /** What the record is called on its own page: the linked column's value. */
    public function recordTitle(object $row): string
    {
        foreach ($this->columns() as $column) {
            if ($column->link) {
                return (string) ($row->{$column->key} ?? $this->noun());
            }
        }

        return $this->noun();
    }

    public function key(): string
    {
        return 'app.setup.'.$this->slug();
    }

    public function permission(): string
    {
        return 'masters.'.$this->resource().'.view';
    }

    public function branchSelector(): bool
    {
        return $this->perBranch();
    }

    public function detailMode(): string
    {
        return 'expand';
    }

    public function source(): GridSource
    {
        return new ProcedureSource($this->procedureName(), acceptsFilters: true, extra: [
            'UserId' => Auth::user()?->Id,
            'Authorized' => (int) Gate::allows($this->permission()),
        ]);
    }

    public function rowUrl(object $row): ?string
    {
        if (! isset($row->RowKey, $row->BranchId)) {
            return null;
        }

        return route('app.setup.'.$this->slug().'.show', ['branch' => (int) $row->BranchId, 'key' => (string) $row->RowKey]);
    }

    public function detailUrl(object $row): ?string
    {
        return $this->rowUrl($row);
    }

    public function rowActions(object $row): array
    {
        $url = $this->rowUrl($row);

        return $url === null ? [] : [['label' => 'Open', 'url' => $url]];
    }
}
