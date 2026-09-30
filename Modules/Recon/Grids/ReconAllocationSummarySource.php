<?php

namespace Modules\Recon\Grids;

use App\Grid\GridPage;
use App\Grid\GridQuery;
use App\Grid\Sources\GridSource;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Services\ReconService;
use Modules\Recon\Support\StaffShort;

/**
 * A run's proposals, each with the split by cashier its batch was confirmed
 * with — for the proposals extract of a cash-bag run.
 *
 * A proposal is one row and its split is several (one per cashier), so the
 * split cannot be a join without repeating the proposal. It rides on the row
 * as two columns instead: `AccountedTo`, every cashier line in words, and
 * `ShortDue`, what the lines' staff shorts come to. The per-bag detail is the
 * deposits extract (app.recon.run:mops), and the page shows the split whole.
 *
 * Wraps the proposals' own source rather than replacing it: filters, search,
 * sort and paging are the inner source's, untouched, and this adds two values
 * to the rows of the page it returned. One call to usp_Recon_GetAllocation
 * per page, and none at all on a run that is not CashBags.
 */
final class ReconAllocationSummarySource implements GridSource
{
    public function __construct(
        private GridSource $inner,
        private ?int $runId,
        private ReconService $service,
    ) {}

    public function page(GridQuery $query): GridPage
    {
        $page = $this->inner->page($query);

        if ($this->runId === null || $page->rows->isEmpty()) {
            return $page;
        }

        $run = ReconRun::query()->acrossBranches()->find($this->runId);
        $split = $run === null ? null : $this->service->allocation($run);

        if ($split === null) {
            return $page;
        }

        $lines = $split['lines']->groupBy(fn (object $l) => (int) $l->RunLineId);
        $shorts = $split['batches']->groupBy(fn (object $b) => (int) $b->RunLineId)
            ->map(fn ($batches) => round((float) $batches->sum(fn (object $b) => (float) $b->ShortDue), 2));

        foreach ($page->rows as $row) {
            $id = (int) ($row->Id ?? 0);

            if (! $lines->has($id)) {
                continue;
            }

            $row->AccountedTo = $lines->get($id)
                ->map(fn (object $l) => self::describe($l))
                ->implode('; ');
            $row->ShortDue = $shorts->get($id, 0.0);
        }

        return $page;
    }

    /**
     * One cashier line in words: who, their share of the variance (bank less
     * bags), and their staff short — "Dlamini Ayanda (AM261117): share -4.99,
     * short due, not yet raised". A reversed line says so and why.
     */
    public static function describe(object $line): string
    {
        $who = $line->CashierCode === null
            ? 'No cashier recorded'
            : ($line->CashierName !== null ? $line->CashierName.' ('.$line->CashierCode.')' : $line->CashierCode);

        return $who
            .': share '.number_format((float) $line->Share, 2, '.', '')
            .($line->Basis === 'manual' ? ' (changed by hand)' : '')
            .', '.StaffShort::words($line->ShortStatus, $line->Share, $line->StaffShortsNo)
            .($line->ReversedAt !== null ? ' — reversed: '.$line->ReversalReason : '');
    }

    public function name(): ?string
    {
        return $this->inner->name();
    }

    public function supportsFilters(): bool
    {
        return $this->inner->supportsFilters();
    }
}
