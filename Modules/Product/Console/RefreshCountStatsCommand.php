<?php

namespace Modules\Product\Console;

use App\Support\ProcedureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild `agora.StockItemCountStat` — when each stock line was last counted.
 *
 * The stock master listing carries a "last counted" column, and its source is
 * 6.2 million rows of `STK_StockReconLine`. Computing it per page took about
 * four seconds against the customer's instance, which is not a grid; so it is
 * materialised, and this is what materialises it.
 *
 * Safe to run at any time and against the customer's instance: the procedure
 * READS the count lines and writes only Agora's own table.
 *
 * ---------------------------------------------------------------------------
 * `--if-stale` IS THE SCHEDULED FORM, AND THE SIGNAL IS THE DATA ITSELF
 * ---------------------------------------------------------------------------
 *
 * Ryan asked (20 September 2026) for this to run "after the overnight loads
 * land — when the count lines actually change", and the option I offered him
 * said it would need the load pipeline to signal it. It does not. The count
 * lines carry `CreateDateTime`, and on the live instance it moves during the
 * day — the newest count DATE was 19 September while rows for it were being
 * written at 19:44 on the 20th. So "have new count lines arrived" is a
 * question the data answers directly, and asking it costs one MAX.
 *
 * That is better than a fixed 04:00 cron in both directions: the rebuild
 * happens when the load actually lands rather than when somebody guessed it
 * would, and a night with no load costs nothing instead of a pointless
 * four-second pass over 6.2 million rows.
 *
 * The comparison is deliberately one-sided. A row created DURING a rebuild is
 * newer than the watermark that rebuild stamps, so the next tick runs again
 * and includes it. Redundant work is the failure mode; a silently missed
 * count is not.
 *
 *     php artisan agora:refresh-count-stats             # the whole estate, always
 *     php artisan agora:refresh-count-stats --branch=9  # one site
 *     php artisan agora:refresh-count-stats --if-stale  # only if counts have landed
 */
class RefreshCountStatsCommand extends Command
{
    protected $signature = 'agora:refresh-count-stats
        {--branch= : One branch id; omit for the whole estate}
        {--if-stale : Skip unless count lines have arrived since the last rebuild}';

    protected $description = 'Rebuild the last-counted rollup behind the stock master listing';

    public function handle(ProcedureService $procedures): int
    {
        $branch = $this->option('branch');
        $branchId = $branch === null ? null : (int) $branch;

        if ($this->option('if-stale') && ! $this->isStale($branchId)) {
            $this->components->twoColumnDetail(
                'skipped',
                'no count lines have arrived since the last rebuild'
            );

            return self::SUCCESS;
        }

        $started = microtime(true);

        $result = $procedures->write('agora.usp_Product_RefreshCountStats', [
            'BranchId' => $branchId,
        ]);

        $elapsed = number_format((microtime(true) - $started) * 1000, 0);

        $this->components->twoColumnDetail(
            'scope',
            $branchId === null ? 'every branch' : "branch {$branchId}"
        );
        $this->components->twoColumnDetail('rows written', (string) ($result->RowsWritten ?? 0));
        $this->components->twoColumnDetail('refreshed at', (string) ($result->RefreshedAt ?? '—'));
        $this->components->twoColumnDetail('took', "{$elapsed} ms");

        return self::SUCCESS;
    }

    /**
     * Have any count lines been written since the rollup was last rebuilt?
     *
     * A rollup that has never been built is stale by definition, and so is one
     * whose scope holds no rows — otherwise a branch that has never counted
     * would never get its first pass.
     */
    private function isStale(?int $branchId): bool
    {
        $db = DB::connection(config('agora.connections.app'));

        $lastRefreshed = $db->scalar(
            'SELECT MAX(RefreshedAt) FROM [agora].[StockItemCountStat] WHERE (? IS NULL OR BranchId = ?)',
            [$branchId, $branchId]
        );

        if ($lastRefreshed === null) {
            return true;
        }

        $newestLine = $db->scalar(
            'SELECT MAX(l.CreateDateTime) FROM ['.config('agora.source_databases.erp').'].dbo.STK_StockReconLine l
             WHERE (? IS NULL OR l.SSBranchId = ?)',
            [$branchId, $branchId]
        );

        // A source with no CreateDateTime at all cannot be compared, so the
        // honest answer is to rebuild rather than to assume nothing changed.
        if ($newestLine === null) {
            return true;
        }

        return strtotime((string) $newestLine) > strtotime((string) $lastRefreshed);
    }
}
