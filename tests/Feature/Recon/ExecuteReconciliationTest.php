<?php

namespace Tests\Feature\Recon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunLine;
use Modules\Recon\Services\ReconService;
use Tests\TestCase;

/**
 * Executing a reconciliation, and undoing it.
 *
 * This is the only part of Agora that writes to the customer's estate, so it
 * is the part that has to be provable. The three assertions that matter:
 *
 *  1. In JOURNAL mode nothing in PumpIT moves. That is the fallback offered to
 *     ZP in writing on 18 August 2026 and it has to be genuinely inert.
 *  2. In LIVE mode the bank line and the deposit row really do change, to the
 *     batch number Agora allocated.
 *  3. A reversal puts back exactly what was there — including a prior batch
 *     number, not a blanket zero.
 *
 * And the one that guards the defect: a bank line with no deposit behind it
 * cannot be committed however it is ticked, because the tick itself is refused
 * on a row that is not reconcilable.
 *
 * Local container only, branch 999, cleaned up in tearDown. It writes to
 * PumpIT — the stub — through the APP connection by three-part name, never
 * through the `pumpit` connection, which in a working development .env points
 * at the customer's live instance.
 */
class ExecuteReconciliationTest extends TestCase
{
    private const BRANCH = 999;

    private ReconService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->service = new ReconService(new ProcedureService);
        $this->cleanUp();
        $this->seedFixture();
        $this->actingAs($this->auditor());
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    public function test_journal_mode_records_the_decision_and_touches_nothing_in_pumpit(): void
    {
        config(['recon.stamp_mode' => 'journal']);

        $run = $this->previewed();
        $line = $run->lines->firstWhere('KeyRef', '125');
        $this->service->select($run, [$line->Id]);

        $result = $this->service->commit($run->fresh());

        $this->assertSame('JOURNALLED', $result['status']->Code);
        $this->assertSame(1, (int) $result['status']->Id);

        // The ledger recorded it.
        $this->assertSame(1, $this->rowsIn('ReconBatch'));
        $this->assertSame(3, $this->rowsIn('ReconMatch'), 'Two bank legs and one deposit.');
        $this->assertSame(3, $this->rowsIn('ReconStamp'));
        $this->assertSame('journal', $this->stampState());

        // And PumpIT did not move.
        $this->assertSame(0, $this->reconciledBankLines(), 'Journal mode must not stamp the statement.');
        $this->assertSame(0, $this->reconciledDeposits());
    }

    public function test_live_mode_stamps_both_sides_and_a_reversal_puts_them_back(): void
    {
        config(['recon.stamp_mode' => 'live']);

        $counterBefore = (int) $this->db()->table('PumpIT.dbo.SS_UniqueNumber')
            ->where('SectionId', 1)->value('NextUniqueNumber');

        $run = $this->previewed();
        $line = $run->lines->firstWhere('KeyRef', '125');
        $this->service->select($run, [$line->Id]);

        $result = $this->service->commit($run->fresh());
        $this->assertSame('COMMITTED', $result['status']->Code);

        $batchNo = $this->batchNo();

        // Allocated from the customer's own counter, so it cannot collide with
        // a number the executable hands out.
        $this->assertSame($counterBefore, $batchNo);
        $this->assertSame(
            $counterBefore + 1,
            (int) $this->db()->table('PumpIT.dbo.SS_UniqueNumber')->where('SectionId', 1)->value('NextUniqueNumber')
        );

        // Both legs and the deposit really did change.
        $this->assertSame(2, $this->reconciledBankLines($batchNo));
        $this->assertSame(1, $this->reconciledDeposits($batchNo));

        // The row that had no deposit behind it was never touched.
        $this->assertSame(
            1,
            (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
                ->where('SSBranchId', self::BRANCH)->where('ReconState', 1)
                ->where('Description', 'like', '%300 CC')->count(),
            'A bank line with no deposit must still be sitting there unreconciled.'
        );

        $this->service->reverse($run->fresh(), 'Test reversal');

        $this->assertSame(0, $this->reconciledBankLines($batchNo), 'The bank lines must go back.');
        $this->assertSame(0, $this->reconciledDeposits($batchNo), 'The deposits must go back.');
        $this->assertSame('reversed', $this->batchState());
        $this->assertSame('reversed', $run->fresh()->Status);

        // And the proposal is available again.
        $this->assertSame('pending', ReconRunLine::query()->acrossBranches()->find($line->Id)->CommitState);
    }

    /** A row that cannot reconcile cannot be ticked, so it can never be committed. */
    public function test_an_unreconcilable_row_cannot_be_selected(): void
    {
        $run = $this->previewed();
        $orphan = $run->lines->firstWhere('KeyRef', '300');

        $this->assertFalse($orphan->WouldReconcile);
        $this->assertSame(0, $this->service->select($run, [$orphan->Id]));

        config(['recon.stamp_mode' => 'live']);

        try {
            $this->service->commit($run->fresh());
            $this->fail('A run with nothing selectable must be refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('NOTHING_SELECTED', $e->code());
        }

        $this->assertSame(0, $this->reconciledBankLines());
    }

    /** Executing the same run twice is refused, not silently repeated. */
    public function test_a_committed_run_cannot_be_executed_again(): void
    {
        config(['recon.stamp_mode' => 'journal']);

        $run = $this->previewed();
        $this->service->select($run, [$run->lines->firstWhere('KeyRef', '125')->Id]);
        $this->service->commit($run->fresh());

        try {
            $this->service->commit($run->fresh());
            $this->fail('A committed run must not be executable again.');
        } catch (AgoraProcException $e) {
            $this->assertSame('RUN_ALREADY_COMMITTED', $e->code());
        }
    }

    /**
     * The re-check. A preview may be hours old; if something reconciles a bank
     * line in between, the commit must skip that row rather than stamp over it.
     */
    public function test_a_row_reconciled_since_the_preview_is_skipped_not_overwritten(): void
    {
        config(['recon.stamp_mode' => 'live']);

        $run = $this->previewed();
        $line = $run->lines->firstWhere('KeyRef', '125');
        $this->service->select($run, [$line->Id]);

        // Something else gets there first — the executable, say.
        $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
            ->where('SSBranchId', self::BRANCH)
            ->where('Description', 'like', '%125 CC')
            ->update(['ReconState' => 2, 'ReconBatchNo' => 9999]);

        $result = $this->service->commit($run->fresh());

        $this->assertSame(0, (int) $result['status']->Id, 'Nothing should have been committed.');
        $this->assertStringContainsString(
            'reconciled by something else',
            (string) $result['lines']->first()->Reason
        );

        // And the other leg was left alone rather than half-stamped.
        $this->assertSame(
            9999,
            (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
                ->where('SSBranchId', self::BRANCH)->where('Description', 'like', '%125 CC')->value('ReconBatchNo')
        );
        $this->assertSame(0, $this->reconciledBankLines(0));
    }

    public function test_a_reconciled_line_that_merely_shares_the_key_does_not_block_the_batch(): void
    {
        config(['recon.stamp_mode' => 'live']);

        /*
         * RUN 38, BRANCH 18, 7 SEPTEMBER 2026 — 127 of 130 batches skipped
         * saying "a bank line has been reconciled by something else", and it
         * was not true. Re-drilling those batches without reconciled rows
         * returned the preview's line count and its total to the cent.
         *
         * The cause is here: the preview matches ReconState = 1 only, and the
         * commit re-drilled with @IncludeReconciled = 1 and then blocked on
         * ANY reconciled row it got back — including lines that merely share
         * the key and the window and were never in the batch.
         *
         * So: a third line on batch 125, already reconciled to somebody
         * else's batch. The preview cannot see it. The batch it proposed is
         * untouched and still balances. It must commit.
         */
        $narrative = str_repeat('X', 48).'02026318 125 CC';

        $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            'SSBranchId' => self::BRANCH, 'LineDate' => '2026-07-09', 'Description' => $narrative,
            'Amount' => 6543.21, 'Type' => 'ABSA', 'IDState' => 2,
            'ReconState' => 2, 'ReconBatchNo' => 8888,
        ]);

        $run = $this->previewed();
        $line = $run->lines->firstWhere('KeyRef', '125');

        // The preview is unchanged by a line it cannot see.
        $this->assertNotNull($line, 'Batch 125 should still be proposed.');
        $this->assertEqualsWithDelta(15137.40, (float) $line->BankTotal, 0.005);
        $this->assertEqualsWithDelta(15137.40, (float) $line->MopsTotal, 0.005);

        $this->service->select($run, [$line->Id]);
        $result = $this->service->commit($run->fresh());

        $this->assertSame(
            1,
            (int) $result['status']->Id,
            'The batch is intact — a reconciled line sharing its key is not a reason to skip it.'
        );

        // The two lines the batch actually needed were stamped...
        $this->assertSame(2, $this->reconciledBankLines($this->batchNo()));

        // ...and the bystander was left exactly as it was found.
        $this->assertSame(
            8888,
            (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
                ->where('SSBranchId', self::BRANCH)->where('ReconBatchNo', 8888)->value('ReconBatchNo')
        );
    }

    public function test_a_committed_row_still_shows_both_sides_when_it_is_expanded(): void
    {
        config(['recon.stamp_mode' => 'live']);

        $run = $this->previewed();
        $line = $run->lines->firstWhere('KeyRef', '125');
        $this->service->select($run, [$line->Id]);
        $this->service->commit($run->fresh());

        $line = $line->fresh();
        $this->assertTrue($line->isCommitted(), 'The fixture batch should have committed.');

        /*
         * Reported by Ryan, 7 September 2026. The drill filters both sides to
         * what is still OUTSTANDING — ReconState = 1 and ReconBatchNoPumpIT = 0
         * — and committing sets exactly those columns, so expanding a row that
         * had just reconciled perfectly returned nothing on both sides and the
         * panel said "Nothing on the statement carries this reference".
         */
        $sides = $this->service->sides($run->fresh(), $line);

        $this->assertCount(2, $sides['bank'], 'Both bank legs were stamped and both must still show.');
        $this->assertCount(1, $sides['mops'], 'The deposit that was stamped must still show.');
        $this->assertEqualsWithDelta(15137.40, (float) $sides['bank']->sum('Amount'), 0.005);
        $this->assertEqualsWithDelta(15137.40, (float) $sides['mops']->sum('Amount'), 0.005);

        // The narrative comes back too — it is what carries the marked
        // extraction window, and ReconMatch has no column for it.
        $this->assertNotEmpty($sides['bank']->first()->Description);
        $this->assertSame(
            (int) $line->UsedBankStart,
            (int) $sides['bank']->first()->UsedBankStart,
            'The marked characters must be the ones this proposal matched on.'
        );

        // And the rendered panel says which of the two sources it read.
        $html = $this->actingAs($this->auditor())
            ->get(route('app.recon.line', ['run' => $run->Id, 'line' => $line->Id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('agora.ReconMatch', (string) $html);
        $this->assertStringNotContainsString('Nothing on the statement carries this reference', (string) $html);
        $this->assertStringNotContainsString('Nothing was declared against this reference', (string) $html);
    }

    public function test_the_run_screen_offers_the_same_extract_every_other_grid_does(): void
    {
        $run = $this->previewed();

        $html = (string) $this->actingAs($this->auditor())
            ->get(route('app.recon.run', $run))
            ->assertOk()
            ->getContent();

        // The drawer, the two downloads and the copy box — the same partial
        // the grid shell includes, not a second implementation of it.
        $this->assertStringContainsString('data-drawer-open="dg-recon-'.$run->Id.'-extract"', $html);
        $this->assertStringContainsString('Download .xlsx', $html);
        $this->assertStringContainsString('Download .csv', $html);
        $this->assertStringContainsString('as comma-separated text', $html);

        // CSV comes back as a file, with the run's own rows in it.
        $csv = $this->actingAs($this->auditor())
            ->get(route('app.grids.extract', ['grid' => 'app.recon.run', 'run' => $run->Id, 'format' => 'csv']));

        $csv->assertOk();
        $body = $csv->streamedContent();

        $this->assertStringContainsString('Reference', $body, 'The header row is the column labels.');
        $this->assertStringContainsString('125', $body, 'The fixture batch should be in the file.');
        $this->assertStringContainsString('15137.4', $body, 'Money exports as a number, not as R15 137.40.');

        // XLSX comes back as a real workbook rather than a renamed CSV.
        $xlsx = $this->actingAs($this->auditor())
            ->get(route('app.grids.extract', ['grid' => 'app.recon.run', 'run' => $run->Id, 'format' => 'xlsx']));
        $xlsx->assertOk();
        $this->assertSame('PK', substr($xlsx->streamedContent(), 0, 2), 'An .xlsx is a zip.');
    }

    public function test_an_extract_without_a_run_returns_nothing_rather_than_everything(): void
    {
        // The endpoint is reachable by anyone who may open the recon screen,
        // and a missing parameter must not widen it to every proposal ever
        // made. The source turns an absent run into RunId = 0.
        $body = $this->actingAs($this->auditor())
            ->get(route('app.grids.extract', ['grid' => 'app.recon.run', 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        // Header row only.
        $this->assertSame(1, substr_count(trim($body), "\n") + 1);
    }

    public function test_either_side_of_a_proposal_can_be_exported_on_its_own(): void
    {
        $run = $this->previewed();
        $line = $run->lines->firstWhere('KeyRef', '125');

        // The panel offers both, per side, scoped to this proposal.
        $panel = (string) $this->actingAs($this->auditor())
            ->get(route('app.recon.line', ['run' => $run->Id, 'line' => $line->Id]))
            ->assertOk()
            ->getContent();

        // The grid key carries a colon and the route pattern allows it, so it
        // appears in the URL as itself rather than percent-encoded.
        foreach (['app.recon.run:bank', 'app.recon.run:mops'] as $grid) {
            $this->assertStringContainsString($grid.'/extract', $panel, "The panel should offer {$grid}.");
        }
        $this->assertStringContainsString('line='.$line->Id, $panel, 'Scoped to this proposal, not the run.');

        // Bank side: the two legs of batch 125, each stamped with the proposal
        // they belong to — that is what makes the file answer "which lines
        // settled this batch" rather than being a list of statement rows.
        $bank = $this->actingAs($this->auditor())
            ->get(route('app.grids.extract', ['grid' => 'app.recon.run:bank', 'line' => $line->Id, 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertSame(3, substr_count(trim($bank), "\n") + 1, 'Header plus the two legs.');
        $this->assertStringContainsString('14937.4', $bank);
        $this->assertStringContainsString('200', $bank);
        // Reference is the first column, so a row STARTS with it. That column
        // is the point of the file: without it these are just statement lines.
        $this->assertSame(2, substr_count($bank, "\n125,"), 'Every row carries its proposal reference.');

        // Deposit side: the one deposit behind it.
        $mops = $this->actingAs($this->auditor())
            ->get(route('app.grids.extract', ['grid' => 'app.recon.run:mops', 'line' => $line->Id, 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertSame(2, substr_count(trim($mops), "\n") + 1, 'Header plus the one deposit.');
        $this->assertStringContainsString('15137.4', $mops);
    }

    public function test_a_whole_run_can_be_exported_a_side_at_a_time(): void
    {
        $run = $this->previewed();

        $bank = $this->actingAs($this->auditor())
            ->get(route('app.grids.extract', ['grid' => 'app.recon.run:bank', 'run' => $run->Id, 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        // Every bank line the fixture put in the window, across BOTH
        // proposals: the two legs of batch 125 and the orphan on 300. Header
        // plus three.
        $this->assertSame(4, substr_count(trim($bank), "\n") + 1);
        $this->assertStringContainsString('300,"Bank only', $bank, 'The bank-only proposal contributes its line too.');
        $this->assertStringContainsString('14937.4', $bank);
        $this->assertStringContainsString('900', $bank);

        // And the run screen offers both, with the whole-run scope.
        $screen = (string) $this->actingAs($this->auditor())
            ->get(route('app.recon.run', $run))
            ->assertOk()
            ->getContent();

        // All four: both sides, both formats, whole run.
        $this->assertStringContainsString('All rows, both sides', $screen);

        foreach (['bank', 'mops'] as $side) {
            foreach (['xlsx', 'csv'] as $format) {
                $this->assertStringContainsString(
                    'app.recon.run:'.$side.'/extract?run='.$run->Id.'&amp;format='.$format,
                    $screen,
                    "The run screen should offer the {$side} side as .{$format}."
                );
            }
        }
    }

    public function test_a_side_export_naming_neither_a_run_nor_a_line_returns_nothing(): void
    {
        // Same guard as the proposals extract, and it matters more here: these
        // are the individual statement lines.
        foreach (['app.recon.run:bank', 'app.recon.run:mops'] as $grid) {
            $body = $this->actingAs($this->auditor())
                ->get(route('app.grids.extract', ['grid' => $grid, 'format' => 'csv']))
                ->assertOk()
                ->streamedContent();

            $this->assertSame(1, substr_count(trim($body), "\n") + 1, "{$grid} should return the header only.");
        }
    }

    private function previewed(): ReconRun
    {
        return $this->service->preview(
            'ABSA', self::BRANCH,
            Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'),
        );
    }

    /*
     * Every read is branch-scoped, and that is not politeness.
     *
     * These tables are shared with whatever else the local database holds —
     * a browser session driving branch 18 will happily leave committed and
     * reversed rows in them. An unscoped ->value() picks the first row in the
     * table, which made this file fail on somebody else's data rather than on
     * its own.
     */
    private function batchNo(): int
    {
        return (int) $this->db()->table('agora.ReconBatch')
            ->where('BranchId', self::BRANCH)->value('BatchNo');
    }

    private function batchState(): ?string
    {
        return $this->db()->table('agora.ReconBatch')
            ->where('BranchId', self::BRANCH)->value('State');
    }

    private function stampState(): ?string
    {
        return $this->db()->table('agora.ReconStamp')
            ->where('BranchId', self::BRANCH)->value('State');
    }

    private function rowsIn(string $table): int
    {
        return (int) $this->db()->table("agora.{$table}")->where('BranchId', self::BRANCH)->count();
    }

    private function reconciledBankLines(?int $batchNo = null): int
    {
        $q = $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
            ->where('SSBranchId', self::BRANCH)->where('ReconState', 2);

        return (int) ($batchNo === null ? $q->count() : $q->where('ReconBatchNo', $batchNo)->count());
    }

    private function reconciledDeposits(?int $batchNo = null): int
    {
        $q = $this->db()->table('PumpIT.dbo.BRN_DailyBankingABSA')
            ->where('SSBranchId', self::BRANCH)->where('ReconBatchNoPumpIT', '>', 0);

        return (int) ($batchNo === null ? $q->count() : $q->where('ReconBatchNoPumpIT', $batchNo)->count());
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }

    private function auditor(): User
    {
        $user = User::query()->acrossBranches()
            ->where('EmailAddress', config('agora.e2e.email'))->first();

        if (! $user) {
            $this->markTestSkipped('No E2E fixture user — run the E2eFixtureSeeder.');
        }

        return $user;
    }

    private function skipUnlessLocalStub(): void
    {
        $connection = config('agora.connections.app');
        $host = config("database.connections.{$connection}.host");

        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->markTestSkipped(
                "This test STAMPS RECONCILIATIONS and [{$connection}] points at [{$host}]. Local container only."
            );
        }
    }

    private function seedFixture(): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.BRN_AutoReconCriteria')->insert([
            'SSBranchId' => self::BRANCH, 'BankReconArea' => 'ABSA', 'ProcessOrder' => 1,
            'BANK_StartPosition' => 58, 'BANK_EndPosition' => 3,
            'BANK_StartPosition2' => 49, 'BANK_EndPosition2' => 8,
            'MOPS_StartPosition' => 1, 'MOPS_EndPosition' => 10,
            'FILTER_Value' => null, 'FILTER_StartPosition' => 0, 'FILTER_EndPosition' => 0,
        ]);

        $narrative = fn (string $batch, string $leg) => str_repeat('X', 48).'02026318 '.$batch.' '.$leg;

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            $this->bankLine('2026-07-08', $narrative('125', 'CC'), 14937.40),
            $this->bankLine('2026-07-08', $narrative('125', 'DD'), 200.00),
            $this->bankLine('2026-07-10', $narrative('300', 'CC'), 900.00),
        ]);

        $db->table('PumpIT.dbo.BRN_DailyBankingABSA')->insert([
            $this->deposit('2026-07-08', 125, 15137.40),
        ]);
    }

    /** @return array<string, mixed> */
    private function bankLine(string $date, string $description, float $amount): array
    {
        return [
            'SSBranchId' => self::BRANCH, 'LineDate' => $date, 'Description' => $description,
            'Amount' => $amount, 'Type' => 'ABSA', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function deposit(string $date, int $batch, float $amount): array
    {
        return [
            'SSBranchId' => self::BRANCH, 'TransactionDate' => $date, 'BatchNumber' => $batch,
            'MerchantNumber' => '2026318', 'TransactionAmount' => $amount, 'ReconBatchNoPumpIT' => 0,
        ];
    }

    private function cleanUp(): void
    {
        $db = $this->db();

        foreach ([
            'PumpIT.dbo.RCN_BankStatementLinesPumpIT',
            'PumpIT.dbo.BRN_DailyBankingABSA',
            'PumpIT.dbo.BRN_AutoReconCriteria',
        ] as $table) {
            $db->table($table)->where('SSBranchId', self::BRANCH)->delete();
        }

        foreach (['ReconStamp', 'ReconMatch', 'ReconBatch', 'ReconRunLine', 'ReconRun'] as $table) {
            $db->table("agora.{$table}")->where('BranchId', self::BRANCH)->delete();
        }
    }
}
