<?php

namespace Tests\Feature\Reports;

use Modules\Reports\Services\ReportsService;
use Tests\TestCase;

/**
 * Branch scoping, and the bug it is here to stop coming back.
 *
 * `STRING_SPLIT('', ',')` returns ONE row holding an empty string, and
 * `TRY_CONVERT(int, '')` is 0 — not NULL. So the first version of every
 * procedure in this module built a branch filter containing branch 0 whenever
 * the caller passed no branches, decided it was therefore scoped, and returned
 * NOTHING. Fifteen reports, all silently empty, all "working".
 *
 * It was caught on the first run because the local container has 31 branches in
 * it and one report should have returned 558 rows. That is luck, not process,
 * so it is a test now.
 *
 * Read-only: usp_Reports_GridOvernightLoads counts what landed and writes
 * nothing, and it produces rows from the branch list alone — which makes it the
 * one report that can prove scoping without depending on any transaction data.
 */
class ReportsBranchScopeTest extends TestCase
{
    private ReportsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReportsService::class);
    }

    public function test_no_branches_means_every_branch_not_none(): void
    {
        $all = $this->service->run('overnight-loads', ['from' => '2026-09-03', 'to' => '2026-09-03']);

        $this->assertGreaterThan(
            0,
            $all['total'],
            'An empty branch list returned nothing. TRY_CONVERT(int, \'\') is 0, not NULL — '
            .'see the guard in each procedure\'s branch filter.'
        );
        $this->assertNull($all['params']['BranchIds'], 'No branches should reach the procedure as NULL.');
    }

    public function test_a_branch_list_narrows_the_answer(): void
    {
        $all = $this->service->run('overnight-loads', ['from' => '2026-09-03', 'to' => '2026-09-03']);

        $one = $this->service->run('overnight-loads', [
            'from' => '2026-09-03', 'to' => '2026-09-03', 'branch_ids' => [13],
        ]);

        $this->assertSame('13', $one['params']['BranchIds']);
        $this->assertLessThan($all['total'], $one['total'], 'One branch returned as much as all of them.');
    }

    public function test_a_branch_nobody_has_returns_nothing_rather_than_everything(): void
    {
        $none = $this->service->run('overnight-loads', [
            'from' => '2026-09-03', 'to' => '2026-09-03', 'branch_ids' => [999999],
        ]);

        $this->assertSame(0, $none['total']);
    }

    public function test_a_zero_in_the_branch_list_is_dropped_not_passed_through(): void
    {
        // 0 is not a branch. Passing it through would be the same failure in a
        // different coat: the procedure would scope to a branch that does not
        // exist and return nothing.
        $result = $this->service->run('overnight-loads', [
            'from' => '2026-09-03', 'to' => '2026-09-03', 'branch_ids' => [0, 13, 0],
        ]);

        $this->assertSame('13', $result['params']['BranchIds']);
    }

    public function test_every_report_returns_a_total_row_set(): void
    {
        // Result set 2 is one row, (TotalRows BIGINT), by contract — it is what
        // lets a grid say "50 of 12,480" and decide about the export ceiling.
        // A procedure that loses it reports its page size as the whole answer.
        foreach (array_keys(config('reports.reports')) as $key) {
            $result = $this->service->run($key, ['from' => '2026-09-03', 'to' => '2026-09-03']);

            $this->assertIsInt($result['total'], "[{$key}] returned no TotalRows result set.");
            $this->assertGreaterThanOrEqual($result['rows']->count(), $result['total']);
        }
    }
}
