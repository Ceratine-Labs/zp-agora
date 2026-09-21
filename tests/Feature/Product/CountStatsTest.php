<?php

namespace Tests\Feature\Product;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `agora.usp_Product_RefreshCountStats` — the last-counted rollup.
 *
 * The listing carries a "last counted" column and its source is 6.2 million
 * count lines, so the answer is materialised rather than computed per page.
 * What matters about a cache is not that it is fast but that it is right about
 * absence: a line nobody has ever counted must come back as NOTHING, not as a
 * zero or a 1900 date, because "never counted" is the answer the screen exists
 * to surface. Two of these tests are about that.
 *
 * Writes to the LOCAL PumpIT stub and to Agora's own table, under branch 999
 * only. tearDown() removes every row it wrote.
 */
class CountStatsTest extends TestCase
{
    private const BRANCH = 999;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes stock recon lines.');
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    public function test_it_reports_the_most_recent_count_per_item(): void
    {
        $this->countLine('10', '2026-09-01');
        $this->countLine('10', '2026-09-16');
        $this->countLine('11', '2025-07-02');

        $this->refresh();

        $this->assertSame('2026-09-16', $this->stat('10')->LastCountedAt->toDateString());
        $this->assertSame('2025-07-02', $this->stat('11')->LastCountedAt->toDateString());
    }

    public function test_an_item_nobody_has_counted_gets_no_row_at_all(): void
    {
        $this->countLine('10', '2026-09-16');

        $this->refresh();

        // Not a zero, not a 1900 date, not a row with NULL in it — absent.
        // The grid renders the absence as an em dash, which is the truthful
        // thing to render for the 4,434 live items in this position.
        $this->assertNull($this->db()->table('agora.StockItemCountStat')
            ->where('BranchId', self::BRANCH)->where('StockItemNo', '99')->first());
    }

    public function test_the_ninety_day_window_separates_quiet_lines_from_busy_ones(): void
    {
        $this->countLine('10', Carbon::today()->subDays(3)->toDateString());
        $this->countLine('10', Carbon::today()->subDays(20)->toDateString());
        $this->countLine('11', Carbon::today()->subDays(200)->toDateString());

        $this->refresh();

        $this->assertSame(2, (int) $this->stat('10')->CountLines90);
        $this->assertSame(2, (int) $this->stat('10')->CountLinesAllTime);

        // Counted once, long ago: it has a date, and a zero in the window.
        // Both halves are needed — a date alone cannot distinguish "counted
        // last week" from "counted in 2025" at a glance.
        $this->assertSame(0, (int) $this->stat('11')->CountLines90);
        $this->assertSame(1, (int) $this->stat('11')->CountLinesAllTime);
    }

    public function test_a_line_that_stops_being_counted_loses_its_row_rather_than_keeping_a_stale_date(): void
    {
        $this->countLine('10', '2026-09-16');
        $this->refresh();
        $this->assertNotNull($this->stat('10'));

        $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->delete();
        $this->refresh();

        $this->assertNull($this->db()->table('agora.StockItemCountStat')
            ->where('BranchId', self::BRANCH)->where('StockItemNo', '10')->first());
    }

    public function test_refreshing_one_branch_leaves_the_others_alone(): void
    {
        $this->countLine('10', '2026-09-16');
        $this->refresh();

        $before = $this->db()->table('agora.StockItemCountStat')
            ->where('BranchId', '<>', self::BRANCH)->count();

        $this->refresh(self::BRANCH);

        $this->assertSame($before, $this->db()->table('agora.StockItemCountStat')
            ->where('BranchId', '<>', self::BRANCH)->count());
        $this->assertNotNull($this->stat('10'));
    }

    public function test_an_unknown_branch_is_refused_rather_than_silently_doing_nothing(): void
    {
        $this->expectException(AgoraProcException::class);

        try {
            $this->refresh(123456);
        } catch (AgoraProcException $e) {
            $this->assertSame('UNKNOWN_BRANCH', $e->code());
            throw $e;
        }
    }

    public function test_if_stale_skips_when_no_count_line_has_arrived_since_the_last_rebuild(): void
    {
        $this->countLine('10', '2026-09-16', '2026-09-16 04:00:00');
        $this->artisan('agora:refresh-count-stats', ['--branch' => self::BRANCH])->assertOk();

        $this->artisan('agora:refresh-count-stats', ['--branch' => self::BRANCH, '--if-stale' => true])
            ->expectsOutputToContain('no count lines have arrived since the last rebuild')
            ->assertOk();
    }

    public function test_if_stale_rebuilds_once_a_count_line_lands(): void
    {
        $this->countLine('10', '2026-09-16', '2026-09-16 04:00:00');
        $this->artisan('agora:refresh-count-stats', ['--branch' => self::BRANCH])->assertOk();

        // Written a second ago, for a day last week — which is the real shape:
        // on 20 September the newest count DATE was the 19th while its rows
        // were still arriving at 19:44.
        $this->countLine('11', '2026-09-17', now()->addMinute()->toDateTimeString());

        $this->artisan('agora:refresh-count-stats', ['--branch' => self::BRANCH, '--if-stale' => true])
            ->doesntExpectOutputToContain('no count lines have arrived')
            ->assertOk();

        $this->assertNotNull($this->stat('11'));
    }

    public function test_a_rollup_that_has_never_been_built_is_stale_by_definition(): void
    {
        // Otherwise a branch that has never counted would never get its first
        // pass, and "never counted" would be indistinguishable from "never
        // asked".
        $this->countLine('10', '2026-09-16', '2026-09-16 04:00:00');

        $this->artisan('agora:refresh-count-stats', ['--branch' => self::BRANCH, '--if-stale' => true])
            ->doesntExpectOutputToContain('no count lines have arrived')
            ->assertOk();

        $this->assertNotNull($this->stat('10'));
    }

    private function refresh(?int $branchId = null): object
    {
        return (new ProcedureService)->write('agora.usp_Product_RefreshCountStats', [
            'BranchId' => $branchId,
        ]);
    }

    private function stat(string $itemNo): ?object
    {
        $row = $this->db()->table('agora.StockItemCountStat')
            ->where('BranchId', self::BRANCH)
            ->where('StockItemNo', $itemNo)
            ->first();

        if ($row !== null && $row->LastCountedAt !== null) {
            $row->LastCountedAt = Carbon::parse($row->LastCountedAt);
        }

        return $row;
    }

    private function countLine(string $itemNo, string $date, ?string $writtenAt = null): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockReconLine')->insert([
            'SSBranchId' => self::BRANCH,
            'TransactionDate' => $date,
            // When the row was WRITTEN, as against the day it counts for.
            // `--if-stale` reads this, and the two are days apart in practice.
            'CreateDateTime' => $writtenAt ?? $date,
            'ShiftNo' => 1,
            'AreaNo' => 1,
            'StockItemNo' => $itemNo,
            'SellPrice' => 10.00,
            'QtyOpen' => 1,
            'QtyIssued' => 0,
            'QtyClose' => 1,
            'QtyComputer' => 1,
            'QtyOpen_Original' => 1,
            'QtyIssued_Original' => 0,
            'QtyClose_Original' => 1,
            'QtyComputer_Original' => 1,
        ]);
    }

    private function cleanUp(): void
    {
        $this->db()->table('agora.StockItemCountStat')->where('BranchId', self::BRANCH)->delete();
        $this->db()->table('PumpIT.dbo.STK_StockReconLine')->where('SSBranchId', self::BRANCH)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
