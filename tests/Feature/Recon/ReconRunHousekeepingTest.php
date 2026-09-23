<?php

namespace Tests\Feature\Recon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Services\ReconService;
use Tests\TestCase;

/**
 * The clerks' working list (Ryan, 23 Sep 2026 — Bank Recon Close-out, q2).
 *
 * On live that morning the clerks' Runs tabs held 1,136 open previews and 236
 * failed runs, and three runs had been committed that stamped nothing because
 * another run had already reconciled every row in them. What this pins:
 *
 *  · a finished preview can be closed, and reopened, and a run with anything
 *    processed against it cannot be closed — Ryan's rule of 9 Sep, decided in
 *    one place, usp_Recon_DiscardRuns;
 *  · a closed run cannot be executed until it is reopened;
 *  · a clear-previews sweep honours an age limit and "only mine", and never
 *    takes a run marked complete;
 *  · the runs grid shows open work unless asked for everything;
 *  · a preview that another run has overtaken says so before the press;
 *  · the every-site runner knows which sites have rules.
 *
 * Local container only, branch 998, cleaned up in tearDown. Nothing here
 * writes to PumpIT: the commits run in journal mode, which records batches in
 * Agora's own ledger and leaves the estate alone.
 */
class ReconRunHousekeepingTest extends TestCase
{
    private const BRANCH = 998;

    private ReconService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->service = new ReconService(new ProcedureService);
        $this->cleanUp();
        $this->seedFixture();
        $this->actingAs($this->admin());

        config(['recon.stamp_mode' => 'journal']);
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    public function test_a_finished_preview_is_closed_and_reopened(): void
    {
        $run = $this->previewed();

        $this->assertSame('CLOSED', $this->service->close($run)->Code);
        $this->assertSame('closed', $run->fresh()->Status);

        $this->assertSame('REOPENED', $this->service->reopen($run->fresh())->Code);
        $this->assertSame('previewed', $run->fresh()->Status);
    }

    /** A refused preview stays refused: reopening puts it back to 'failed', not 'previewed'. */
    public function test_a_refused_preview_reopens_as_refused(): void
    {
        $run = $this->makeRun(['Status' => 'failed', 'FailureCode' => 'NO_CRITERIA', 'FailureMessage' => 'TEST- no rule']);

        $this->service->close($run);
        $this->assertSame('closed', $run->fresh()->Status);

        $this->service->reopen($run->fresh());
        $this->assertSame('failed', $run->fresh()->Status);
    }

    public function test_a_committed_run_cannot_be_closed(): void
    {
        $run = $this->previewed();
        $this->service->select($run, [$run->lines->firstWhere('KeyRef', '125')->Id]);
        $this->service->commit($run->fresh());

        $this->assertRefused('RUN_PROCESSED', fn () => $this->service->close($run->fresh()));
    }

    /**
     * The literal half of the rule: evidence hanging off a run protects it even
     * when its status still says 'previewed'. The status test alone is the hole
     * the reversed-run bug came through.
     */
    public function test_a_run_with_evidence_against_it_cannot_be_closed_whatever_its_status(): void
    {
        $run = $this->makeRun(['Status' => 'previewed']);

        $this->db()->table('agora.ReconBatch')->insert([
            'BranchId' => self::BRANCH, 'RunId' => $run->Id, 'BatchNo' => 1, 'ReconArea' => 'ABSA',
            'KeyRef' => 'TEST-1', 'BankLineCount' => 0, 'MopsRowCount' => 0, 'BankTotal' => 0,
            'MopsTotal' => 0, 'State' => 'committed', 'CreatedAt' => now(),
        ]);

        $this->assertRefused('RUN_PROCESSED', fn () => $this->service->close($run));
    }

    public function test_a_closed_run_cannot_be_executed_until_it_is_reopened(): void
    {
        $run = $this->previewed();
        $this->service->select($run, [$run->lines->firstWhere('KeyRef', '125')->Id]);
        $this->service->close($run->fresh());

        $this->assertRefused('RUN_NOT_PREVIEWED', fn () => $this->service->commit($run->fresh()));

        $this->service->reopen($run->fresh());
        $this->assertSame('JOURNALLED', $this->service->commit($run->fresh())['status']->Code);
    }

    public function test_reopening_refuses_a_run_that_is_not_closed(): void
    {
        $run = $this->previewed();

        $this->assertRefused('RUN_NOT_CLOSED', fn () => $this->service->reopen($run));
    }

    public function test_a_sweep_honours_age_and_person_and_keeps_closed_runs(): void
    {
        $me = (int) auth()->id();

        $oldMine = $this->makeRun(['CreatedBy' => $me, 'CreatedAt' => now()->subDays(20)]);
        $newMine = $this->makeRun(['CreatedBy' => $me, 'CreatedAt' => now()->subDay()]);
        $oldTheirs = $this->makeRun(['CreatedBy' => $me + 100000, 'CreatedAt' => now()->subDays(20)]);
        $oldClosed = $this->makeRun(['CreatedBy' => $me, 'CreatedAt' => now()->subDays(20), 'Status' => 'closed']);

        $this->assertSame(1, $this->service->discard(self::BRANCH, 'ABSA', null, 14, $me));

        $this->assertFalse($this->exists($oldMine), 'Only my run older than 14 days goes.');
        $this->assertTrue($this->exists($newMine), 'A recent run is not old enough.');
        $this->assertTrue($this->exists($oldTheirs), 'Somebody else\'s run is not mine to clear.');
        $this->assertTrue($this->exists($oldClosed), 'A sweep never takes a run marked complete.');

        // Asked for by its own id, a closed run can still go.
        $this->assertSame(1, $this->service->discard(self::BRANCH, null, $oldClosed->Id));
        $this->assertFalse($this->exists($oldClosed));
    }

    public function test_the_runs_grid_shows_open_work_unless_asked_for_everything(): void
    {
        $open = $this->makeRun(['Status' => 'previewed']);
        $closed = $this->makeRun(['Status' => 'closed']);
        $failed = $this->makeRun(['Status' => 'failed', 'FailureCode' => 'NO_CRITERIA']);

        $ids = fn (int $openOnly): array => (new ProcedureService)->callSets('usp_Recon_GridRuns', [
            'BranchIds' => (string) self::BRANCH,
            'ReconArea' => 'ABSA',
            'MineOnly' => 0,
            'OpenOnly' => $openOnly,
            'PageSize' => 1000,
        ])[0]->pluck('Id')->map(fn (mixed $id) => (int) $id)->all();

        $this->assertContains($open->Id, $ids(1));
        $this->assertNotContains($closed->Id, $ids(1));
        $this->assertNotContains($failed->Id, $ids(1));

        $this->assertContains($closed->Id, $ids(0));
        $this->assertContains($failed->Id, $ids(0));
    }

    /**
     * The warning the clerk used to get only after pressing Reconcile.
     *
     * The preview is back-dated a few minutes rather than slept on: both
     * timestamps are to the second, and a claim in the same second as the
     * preview would not be "since" it.
     */
    public function test_a_proposal_another_run_has_committed_since_is_named_before_the_press(): void
    {
        $mine = $this->previewed();
        $this->db()->table('agora.ReconRun')->where('Id', $mine->Id)->update(['CreatedAt' => now()->subMinutes(5)]);

        $theirs = $this->previewed();
        $this->service->select($theirs, [$theirs->lines->firstWhere('KeyRef', '125')->Id]);
        $this->service->commit($theirs->fresh());

        $freshness = $this->service->freshness($mine->fresh());
        $line = $mine->lines->firstWhere('KeyRef', '125');

        $this->assertNotNull($freshness);
        $this->assertSame(1, (int) $freshness['summary']->Pending);
        $this->assertSame(1, (int) $freshness['summary']->Claimed);
        $this->assertSame($theirs->Id, (int) $freshness['claimed']->get($line->Id)->ClaimedByRunId);

        $this->get(route('app.recon.run', $mine))
            ->assertOk()
            ->assertSee('been reconciled by another run since this preview')
            ->assertSee('reconciled by run #'.$theirs->Id);
    }

    public function test_a_preview_nobody_has_overtaken_carries_no_warning(): void
    {
        $run = $this->previewed();
        $freshness = $this->service->freshness($run);

        $this->assertNotNull($freshness);
        $this->assertSame(1, (int) $freshness['summary']->Pending);
        $this->assertSame(0, (int) $freshness['summary']->Claimed);

        $this->get(route('app.recon.run', $run))
            ->assertOk()
            ->assertDontSee('since this preview');
    }

    public function test_closing_and_reopening_through_the_screen(): void
    {
        $run = $this->previewed();

        $this->post(route('app.recon.close', $run))->assertRedirect(route('app.recon.run', $run));
        $this->assertSame('closed', $run->fresh()->Status);

        $this->get(route('app.recon.run', $run))
            ->assertOk()
            ->assertSee('Marked complete')
            ->assertSee('Reopen');

        $this->post(route('app.recon.reopen', $run))->assertRedirect(route('app.recon.run', $run));
        $this->assertSame('previewed', $run->fresh()->Status);
    }

    public function test_the_every_site_runner_knows_which_sites_have_rules(): void
    {
        $this->assertContains(self::BRANCH, $this->service->branchesWithRules('ABSA'));
        $this->assertNotContains(self::BRANCH, $this->service->branchesWithRules('SmartATM'));

        // The form names what it leaves out rather than hiding it.
        $this->get(route('app.recon.all', 'ABSA'))
            ->assertOk()
            ->assertSee('left out — no');
    }

    /* ------------------------------------------------------------------ */

    private function previewed(): ReconRun
    {
        return $this->service->preview(
            'ABSA', self::BRANCH,
            Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'),
        );
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeRun(array $attributes): ReconRun
    {
        return ReconRun::create($attributes + [
            'BranchId' => self::BRANCH,
            'GroupRef' => (string) Str::uuid(),
            'ReconArea' => 'ABSA',
            'FromDate' => '2026-07-01',
            'ToDate' => '2026-07-31',
            'Status' => 'previewed',
            'StampMode' => 'journal',
            'ProcedureName' => 'agora.usp_Recon_PreviewABSA',
            'ParamsJson' => '[]',
            'Note' => 'TEST- housekeeping',
            'CreatedBy' => auth()->id(),
            'CreatedAt' => now(),
        ]);
    }

    private function exists(ReconRun $run): bool
    {
        return $this->db()->table('agora.ReconRun')->where('Id', $run->Id)->exists();
    }

    private function assertRefused(string $code, callable $call): void
    {
        try {
            $call();
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code());

            return;
        }

        $this->fail("Expected the procedure to refuse with AGORA:{$code}.");
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }

    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    /** The ExecuteReconciliationTest fixture, on its own branch: one batch that balances, one bank line with nothing behind it. */
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
        $line = fn (string $date, string $description, float $amount) => [
            'SSBranchId' => self::BRANCH, 'LineDate' => $date, 'Description' => $description,
            'Amount' => $amount, 'Type' => 'ABSA', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ];

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            $line('2026-07-08', $narrative('125', 'CC'), 14937.40),
            $line('2026-07-08', $narrative('125', 'DD'), 200.00),
            $line('2026-07-10', $narrative('300', 'CC'), 900.00),
        ]);

        $db->table('PumpIT.dbo.BRN_DailyBankingABSA')->insert([[
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-07-08', 'BatchNumber' => 125,
            'MerchantNumber' => '2026318', 'TransactionAmount' => 15137.40, 'ReconBatchNoPumpIT' => 0,
        ]]);
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
