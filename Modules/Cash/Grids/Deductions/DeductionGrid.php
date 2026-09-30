<?php

namespace Modules\Cash\Grids\Deductions;

use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;

/**
 * What the six Deductions grids share (T041): a head-office result set across
 * the sites the person may see, over a period — the last full calendar month
 * when none is chosen, because a deduction schedule is a pay period — powered
 * by one procedure each (feature-rules §2).
 *
 * The stat strip above each tab reads the grid's own grand totals row, so the
 * figure on the strip and the footer of the grid come out of the same result
 * set. A figure the strip needs that is not a column's total is named in
 * statKeys() and carried through totals().
 */
abstract class DeductionGrid extends GridDefinition
{
    public function __construct(protected BranchContext $context) {}

    abstract protected function procedureName(): string;

    /** @return array<int, string> grand-total keys the stat strip reads beyond the column totals */
    protected function statKeys(): array
    {
        return [];
    }

    /** @return array<string, mixed> procedure arguments beyond the template and the allowed sites */
    protected function extraArguments(): array
    {
        return [];
    }

    public function source(): GridSource
    {
        $allowed = $this->context->allowed();

        return new ProcedureSource(
            'agora.'.$this->procedureName(),
            acceptsFilters: true,
            extra: ['AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed)] + $this->extraArguments(),
        );
    }

    public function totals(): array
    {
        return array_values(array_unique([...parent::totals(), ...$this->statKeys()]));
    }

    public function dateRange(): bool
    {
        return true;
    }
}
