<?php

namespace Tests\Feature\Cash;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Models\UserBranch;
use Modules\Core\Models\UserPermission;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

/**
 * Z-read allocation (T036): the proposer's four priors, the replay, and the
 * stage → save cycle, against a site history built so each prior has exactly
 * one Z-read that exercises it.
 *
 * THE HISTORY (branch 999, ARCH, 1–20 July 2026, all allocated in PumpIT):
 *
 *   terminal 2, operator 90   till 21 · Day · TZR01 on 8 of 9     → certain
 *   terminal 3, operator ALT  till 22 · TZR02, Day 3 / Night 3    → likely, no shift
 *   terminal 4, operator 77   till 23 · Night · TZR03 2 / TZR04 2 → no settled person...
 *   terminal 4, operator 55   till 23 · Night · TZR03 on 3 of 3   → ...but TZR03 is who
 *                                                                   works terminal 4 at night → review
 *
 * THE PERIOD (21 July, nobody has allocated them): one Z-read from each of the
 * first three, and one from operator NEW, whom the site has never seen →
 * manual. 22 July carries one Z-read the branch DID allocate, differently from
 * the pattern (a night shift), for the blind replay to disagree with.
 *
 * THE TWO-A-DAY RULE (WINBRANCH, terminal 1): on 1–6 July the terminal printed
 * two Z-reads a day, ANNA and BEA taking turns on day and night, so neither
 * operator has a settled shift. On 23 July it prints two more, unallocated:
 * the first by log file is the Day shift, the second the Night.
 *
 * Writes to the LOCAL PumpIT stub and agora.ZreadAllocation only, on branch
 * 999, and removes every row it wrote. The stamp tests write the stub's
 * DBF_P3TRANS_ZREAD — never the customer's instance, which skipUnlessLocalStub
 * guarantees. Needs the LOCATION column (database/stubs/pumpit-reports.sql, or
 * pumpit-cash.sql on an older container), as the Cash migration itself does.
 */
class ZreadProposerTest extends TestCase
{
    use GrantsAccess;

    private const BRANCH = 999;

    private const EMPLOYEES = ['TZR01', 'TZR02', 'TZR03', 'TZR04'];

    /** @var array<int, User> */
    private array $made = [];

    private int $logFile = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes Z-reads into the PumpIT stub.');
        $this->cleanUp();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();

            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                UserBranch::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }

        parent::tearDown();
    }

    public function test_each_prior_gives_its_confidence_and_its_evidence(): void
    {
        $rows = $this->propose('2026-07-21', '2026-07-21');

        $this->assertCount(4, $rows, 'One row per Z-read in the period, and no fan-out from a label lookup.');

        $certain = $this->row($rows, '90');
        $this->assertSame('certain', $certain->Confidence);
        $this->assertSame([21, 1, 'TZR01'], [(int) $certain->ProposedTillNo, (int) $certain->ProposedShiftNo, $certain->ProposedEmployeeCode]);
        $this->assertTrue((bool) $certain->IsComplete);
        $this->assertStringContainsString('on 8 of 9 prior Z-reads (89%)', $certain->Evidence);
        $this->assertSame('TEST-Amahle Zulu', $certain->ProposedEmployeeName, 'The person is named, not just coded.');

        $likely = $this->row($rows, 'ALT');
        $this->assertSame('likely', $likely->Confidence);
        $this->assertSame('TZR02', $likely->ProposedEmployeeCode);
        $this->assertSame(22, (int) $likely->ProposedTillNo);
        $this->assertNull($likely->ProposedShiftNo, 'An operator who splits day and night has its shift left to a person.');
        $this->assertFalse((bool) $likely->IsComplete);
        $this->assertStringContainsString('splits its shifts', $likely->ShiftEvidence);

        $review = $this->row($rows, '77');
        $this->assertSame('review', $review->Confidence);
        $this->assertSame([23, 3, 'TZR03'], [(int) $review->ProposedTillNo, (int) $review->ProposedShiftNo, $review->ProposedEmployeeCode]);
        $this->assertStringContainsString('no settled person', $review->Evidence);

        $manual = $this->row($rows, 'NEW');
        $this->assertSame('manual', $manual->Confidence);
        $this->assertNull($manual->ProposedEmployeeCode);
        $this->assertStringContainsString('No history for operator ID NEW', $manual->Evidence);
        // Terminal 2 printed two Z-reads on 21 July (90's and NEW's), but it is
        // ARCH, where the log file is a per-operator counter: no two-a-day shift.
        $this->assertNull($manual->ProposedShiftNo, 'The two-a-day rule is off for ARCH.');
        $this->assertNull($manual->ShiftSource);
        $this->assertSame('pattern', $certain->ShiftSource);

        foreach ($rows as $row) {
            $this->assertSame('open', $row->State);
        }
    }

    /**
     * The acceptance's check, on the fixture: under @Replay the period is
     * proposed from history BEFORE it, as though nobody had allocated it, and
     * HistoryMatch compares the proposal with what the branch actually did.
     */
    public function test_the_replay_is_blind_to_the_period_and_says_where_it_disagrees(): void
    {
        $rows = $this->propose('2026-07-21', '2026-07-22', replay: true);

        $allocated = $rows->first(fn ($r) => $r->ZReadDate === '2026-07-22');
        $this->assertNotNull($allocated);
        $this->assertSame('allocated', $allocated->State);
        $this->assertSame('legacy', $allocated->AllocatedBy);
        $this->assertSame('certain', $allocated->Confidence);
        $this->assertSame('differs', $allocated->HistoryMatch,
            'The branch put operator 90 on a night shift; the pattern says day. A replay must say so, not agree with itself.');
        $this->assertStringContainsString('on 8 of 9', $allocated->Evidence,
            'The 22 July allocation must not be part of its own evidence under replay.');

        $this->assertNull($this->row($rows, '90', '2026-07-21')->HistoryMatch, 'An unallocated Z-read has nothing to match.');
    }

    public function test_auto_allocate_stages_only_complete_proposals_at_the_threshold(): void
    {
        $status = $this->procedures()->write('usp_Cash_AutoAllocate', $this->scope('2026-07-21') + [
            'Threshold' => 'certain', 'UserId' => 1,
        ]);

        $this->assertSame('CASH_ZREAD_STAGED', $status->Code);
        $this->assertSame(1, (int) $status->Staged);
        $this->assertSame(4, (int) $status->Waiting);
        $this->assertSame(1, (int) $status->NoShift, 'The likely row with no shift is counted, not staged.');

        $staged = $this->ledger()->where('Status', 'proposed');
        $this->assertCount(1, $staged);
        $this->assertSame(['90', 'auto', 'certain'], [$staged->first()->PosUserId, $staged->first()->Source, $staged->first()->Confidence]);

        // A second press has nothing left to stage at that threshold.
        $again = $this->procedures()->write('usp_Cash_AutoAllocate', $this->scope('2026-07-21') + [
            'Threshold' => 'certain', 'UserId' => 1,
        ]);
        $this->assertSame(0, (int) $again->Staged);

        // At 'review' the review row joins it; the likely one still cannot,
        // because it has no shift to stage.
        $wider = $this->procedures()->write('usp_Cash_AutoAllocate', $this->scope('2026-07-21') + [
            'Threshold' => 'review', 'UserId' => 1,
        ]);
        $this->assertSame(1, (int) $wider->Staged);
        $this->assertEqualsCanonicalizing(['90', '77'], $this->ledger()->where('Status', 'proposed')->pluck('PosUserId')->all());
    }

    /**
     * The fifth prior (Ryan, 27 Sep 2026): a terminal with exactly two Z-reads
     * in a day gives the first by log file the Day shift and the second the
     * Night, where the operator's own history cannot — and says so.
     */
    public function test_the_two_a_day_rule_supplies_the_shift_the_operator_history_cannot(): void
    {
        $rows = $this->propose('2026-07-23', '2026-07-23');

        $first = $this->row($rows, 'ANNA', '2026-07-23');
        $second = $this->row($rows, 'BEA', '2026-07-23');

        foreach ([[$first, 1, 'TZR01', 'first'], [$second, 3, 'TZR02', 'second']] as [$row, $shift, $who, $which]) {
            $this->assertSame('likely', $row->Confidence);
            $this->assertSame('sequence', $row->ShiftSource);
            $this->assertSame([11, $shift, $who], [(int) $row->ProposedTillNo, (int) $row->ProposedShiftNo, $row->ProposedEmployeeCode]);
            $this->assertTrue((bool) $row->IsComplete, 'The rule is what makes the proposal complete.');
            $this->assertStringContainsString('splits its shifts', $row->ShiftEvidence);
            $this->assertStringContainsString("Two-a-day rule: terminal 1 printed two Z-reads that day and this is the {$which} by log file", $row->ShiftEvidence);
        }

        // Complete, so Auto-allocate at 'likely' stages both.
        $status = $this->procedures()->write('usp_Cash_AutoAllocate', $this->scope('2026-07-23') + ['Threshold' => 'likely', 'UserId' => 1]);
        $this->assertSame(2, (int) $status->Staged);

        // And switching the rule off for WINBRANCH leaves the shift to a person.
        $off = $this->procedures()->call('usp_Cash_ProposeZreadAllocation', [
            'BranchId' => self::BRANCH, 'DateFrom' => '2026-07-23', 'DateTo' => '2026-07-23',
            'SequenceExcluded' => 'ARCH,WINBRANCH',
        ]);
        $this->assertNull($this->row($off, 'ANNA', '2026-07-23')->ProposedShiftNo);
    }

    /**
     * Update ZREAD in journal mode writes the allocation AND its audit, and
     * nothing in PumpIT. (The procedure's default is journal; the application
     * passes config('cash.zread.stamp_mode').)
     */
    public function test_a_journal_commit_writes_the_allocation_with_its_audit_and_leaves_pumpit_alone(): void
    {
        $this->procedures()->write('usp_Cash_AutoAllocate', $this->scope('2026-07-21') + ['Threshold' => 'certain', 'UserId' => 1]);
        $this->procedures()->write('usp_Cash_SetZreadAllocation', $this->key('NEW') + [
            'Source' => 'manual', 'TillNo' => 21, 'ShiftNo' => 3, 'EmployeeCode' => 'TZR04', 'UserId' => 1,
        ]);

        $saved = $this->procedures()->write('usp_Cash_CommitZreadAllocation', $this->scope('2026-07-21') + ['UserId' => 1]);

        $this->assertSame('CASH_ZREAD_SAVED', $saved->Code);
        $this->assertSame(2, (int) $saved->Saved);

        $allocated = $this->ledger()->where('Status', 'allocated')->keyBy('PosUserId');
        $this->assertCount(2, $allocated);
        $this->assertSame(1, (int) $allocated['90']->AllocatedBy);
        $this->assertNotNull($allocated['90']->AllocatedAt);
        $this->assertSame((string) $saved->CommitRef, (string) $allocated['90']->CommitRef, 'One press, one CommitRef.');
        $this->assertNull($allocated['90']->PriorSource, 'Nobody had allocated it before.');
        $this->assertSame(['manual', 'manual'], [$allocated['NEW']->Source, $allocated['NEW']->Confidence]);
        $this->assertStringContainsString('The system had no proposal', $allocated['NEW']->Evidence);

        // The view lays Agora's allocation over the legacy row...
        $view = collect($this->db()->select(
            "SELECT PosUserId, AllocatedBy, TillNo, ShiftNo, EmployeeCode FROM agora.vw_CashZread
             WHERE BranchId = ? AND ZReadDate = '2026-07-21' AND PosUserId IN ('90', 'NEW')", [self::BRANCH]
        ))->keyBy('PosUserId');
        $this->assertSame('agora', $view['NEW']->AllocatedBy);
        $this->assertSame([21, 3, 'TZR04'], [(int) $view['NEW']->TillNo, (int) $view['NEW']->ShiftNo, $view['NEW']->EmployeeCode]);

        // ...and the legacy row itself is untouched.
        $legacy = $this->db()->selectOne(
            "SELECT TillNo, ShiftNo, EmployeeCode FROM PumpIT.dbo.DBF_P3TRANS_ZREAD
             WHERE SSBranchId = ? AND [DATE] = '2026-07-21' AND USERID = 'NEW'", [self::BRANCH]
        );
        $this->assertNull($legacy->TillNo);
        $this->assertNull($legacy->ShiftNo);
        $this->assertNull($legacy->EmployeeCode);

        // Re-allocating supersedes, never deletes, and records what it replaced.
        $this->procedures()->write('usp_Cash_SetZreadAllocation', $this->key('NEW') + [
            'Source' => 'manual', 'TillNo' => 22, 'ShiftNo' => 1, 'EmployeeCode' => 'TZR03', 'UserId' => 1,
        ]);
        $this->procedures()->write('usp_Cash_CommitZreadAllocation', $this->scope('2026-07-21') + ['UserId' => 1]);

        $new = $this->ledger()->where('PosUserId', 'NEW')->keyBy('Status');
        $this->assertArrayHasKey('superseded', $new->all());
        $this->assertSame(1, (int) $new['superseded']->SupersededBy);
        $this->assertSame('agora', $new['allocated']->PriorSource);
        $this->assertSame((int) $new['superseded']->Id, (int) $new['allocated']->SupersedesId);
        $this->assertSame([21, 3, 'TZR04'], [(int) $new['allocated']->PriorTillNo, (int) $new['allocated']->PriorShiftNo, $new['allocated']->PriorEmployeeCode]);
    }

    /**
     * THE STAMP. A live commit writes TillNo / ShiftNo / EmployeeCode onto the
     * one PumpIT row, records exactly what the row held, and a re-allocation
     * stamps again over the first.
     */
    public function test_a_live_commit_stamps_the_pumpit_row_and_a_reallocation_restamps_it(): void
    {
        $this->stage('NEW', 21, 3, 'TZR04');
        $first = $this->commit('live');

        $this->assertSame(1, (int) $first->Stamped);
        $this->assertSame([21, 3, 'TZR04'], $this->pumpit('NEW'), 'The PumpIT row carries the allocation.');

        $row = $this->ledger()->firstWhere('Status', 'allocated');
        $this->assertSame('live', $row->StampMode);
        $this->assertNotNull($row->StampedAt);
        $this->assertNull($row->StampPriorTillNo, 'What the row held before: nothing.');
        $this->assertNull($row->StampPriorEmployeeCode);

        // The other 21 July rows were not touched.
        $this->assertSame([null, null, null], $this->pumpit('77'));

        $this->stage('NEW', 22, 1, 'TZR03');
        $this->commit('live');

        $this->assertSame([22, 1, 'TZR03'], $this->pumpit('NEW'));
        $now = $this->ledger()->where('PosUserId', 'NEW')->firstWhere('Status', 'allocated');
        $this->assertSame([21, 3, 'TZR04'], [(int) $now->StampPriorTillNo, (int) $now->StampPriorShiftNo, trim($now->StampPriorEmployeeCode)]);
        $this->assertSame('agora', $now->PriorSource);
    }

    /**
     * Reverse puts back exactly what a press replaced, on both sides, and only
     * the latest press over a Z-read can be reversed.
     */
    public function test_reverse_restores_pumpit_and_what_the_press_replaced(): void
    {
        $this->stage('NEW', 21, 3, 'TZR04');
        $first = $this->commit('live');
        $this->stage('NEW', 22, 1, 'TZR03');
        $second = $this->commit('live');

        try {
            $this->reverse($first->CommitRef);
            $this->fail('A press a later one superseded must not be reversible.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CASH_COMMIT_SUPERSEDED', $e->code());
        }

        $undone = $this->reverse($second->CommitRef);
        $this->assertSame(['CASH_COMMIT_REVERSED', 1, 1, 1], [$undone->Code, (int) $undone->Reversed, (int) $undone->Unstamped, (int) $undone->Restored]);
        $this->assertSame([21, 3, 'TZR04'], $this->pumpit('NEW'), 'PumpIT is back to the first press.');

        $states = $this->ledger()->where('PosUserId', 'NEW')->pluck('Status', 'Id')->all();
        $this->assertEqualsCanonicalizing(['allocated', 'reversed'], array_values($states));
        $back = $this->ledger()->where('PosUserId', 'NEW')->firstWhere('Status', 'allocated');
        $this->assertSame((string) $first->CommitRef, (string) $back->CommitRef, 'The first allocation is back in force.');
        $this->assertSame('Wrong person', $this->ledger()->firstWhere('Status', 'reversed')->ReversalReason);

        // Now the first can be reversed, and PumpIT goes back to empty.
        $this->reverse($first->CommitRef);
        $this->assertSame([null, null, null], $this->pumpit('NEW'));
        $this->assertCount(0, $this->ledger()->where('Status', 'allocated'));

        try {
            $this->reverse($first->CommitRef);
            $this->fail('A reversed press cannot be reversed twice.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CASH_COMMIT_REVERSED', $e->code());
        }
    }

    /**
     * Somebody allocated the Z-read again on the PumpIT screen after Agora
     * stamped it: reversing would write over their decision, so it refuses
     * and changes nothing.
     */
    public function test_reverse_refuses_when_pumpit_has_moved_since(): void
    {
        $this->stage('NEW', 21, 3, 'TZR04');
        $press = $this->commit('live');

        $this->db()->update(
            "UPDATE PumpIT.dbo.DBF_P3TRANS_ZREAD SET EmployeeCode = 'TZR02' WHERE SSBranchId = ? AND [DATE] = '2026-07-21' AND USERID = 'NEW'",
            [self::BRANCH]
        );

        try {
            $this->reverse($press->CommitRef);
            $this->fail('Reversing over a legacy re-allocation must be refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CASH_COMMIT_MOVED', $e->code());
        }

        $this->assertSame([21, 3, 'TZR02'], $this->pumpit('NEW'), 'Nothing was put back.');
        $this->assertSame('allocated', $this->ledger()->first()->Status);
    }

    /**
     * Never 0 rows and never 2+: a Z-read whose key names two PumpIT rows, or
     * none any more, is skipped — not saved, not written — and says why.
     */
    public function test_a_zread_that_does_not_name_exactly_one_row_is_skipped_never_written(): void
    {
        $this->stage('NEW', 21, 3, 'TZR04');
        $this->stage('77', 23, 3, 'TZR03');
        $this->stage('ALT', 22, 1, 'TZR02');

        // NEW's key now names two rows; 77's names none.
        $this->db()->insert(
            "INSERT INTO PumpIT.dbo.DBF_P3TRANS_ZREAD (SSBranchId, [DATE], EOD_CNTR, TERMNUM, LOGFILE, USERID, LOCATION, Sales, TillNo, ShiftNo, EmployeeCode, isCaptured, MIST_EOD)
             SELECT SSBranchId, [DATE], EOD_CNTR, TERMNUM, LOGFILE, USERID, LOCATION, Sales, TillNo, ShiftNo, EmployeeCode, isCaptured, MIST_EOD
             FROM PumpIT.dbo.DBF_P3TRANS_ZREAD WHERE SSBranchId = ? AND [DATE] = '2026-07-21' AND USERID = 'NEW'", [self::BRANCH]
        );
        $this->db()->delete(
            "DELETE FROM PumpIT.dbo.DBF_P3TRANS_ZREAD WHERE SSBranchId = ? AND [DATE] = '2026-07-21' AND USERID = '77'", [self::BRANCH]
        );

        $saved = $this->commit('live');

        $this->assertSame([1, 2, 1], [(int) $saved->Saved, (int) $saved->Skipped, (int) $saved->Stamped]);
        $this->assertSame([22, 1, 'TZR02'], $this->pumpit('ALT'));

        foreach ($this->db()->select("SELECT TillNo, ShiftNo, EmployeeCode FROM PumpIT.dbo.DBF_P3TRANS_ZREAD
                                      WHERE SSBranchId = ? AND [DATE] = '2026-07-21' AND USERID = 'NEW'", [self::BRANCH]) as $twin) {
            $this->assertNull($twin->EmployeeCode, 'Neither of two rows under one key is written.');
        }

        $cleared = $this->ledger()->where('Status', 'cleared')->keyBy('PosUserId');
        $this->assertStringContainsString('names 2 rows in PumpIT', $cleared['NEW']->Note);
        $this->assertStringContainsString('no longer in the site', $cleared['77']->Note);
    }

    /**
     * THE LATEST WINS (Ryan, 27 Sep 2026): Agora stamped a Z-read, then somebody
     * allocated it differently on the PumpIT screen. The PumpIT allocation is
     * the one every read shows, flagged; the press can no longer be reversed;
     * and a PumpIT clear puts the Z-read back in the Unallocated queue.
     */
    public function test_a_later_pumpit_reallocation_wins_and_is_flagged(): void
    {
        $this->stage('NEW', 21, 3, 'TZR04');
        $press = $this->commit('live');
        $agoraId = (int) $this->ledger()->firstWhere('Status', 'allocated')->Id;

        $this->assertSame(['agora', 0], $this->viewOf('NEW'), 'Freshly stamped, Agora and PumpIT agree.');

        // The PumpIT screen re-allocates it to somebody else.
        $this->db()->update("UPDATE PumpIT.dbo.DBF_P3TRANS_ZREAD SET EmployeeCode = 'TZR02'
                             WHERE SSBranchId = ? AND [DATE] = '2026-07-21' AND USERID = 'NEW'", [self::BRANCH]);

        $view = $this->db()->selectOne("SELECT AllocatedBy, EmployeeCode, TillNo, ShiftNo, IsAllocated, AllocationId, ChangedInPumpItAfterAgora
                                        FROM agora.vw_CashZread WHERE BranchId = ? AND ZReadDate = '2026-07-21' AND PosUserId = 'NEW'", [self::BRANCH]);
        $this->assertSame(['legacy', 'TZR02', 21, 3, 1, 1], [$view->AllocatedBy, $view->EmployeeCode, (int) $view->TillNo,
            (int) $view->ShiftNo, (int) $view->IsAllocated, (int) $view->ChangedInPumpItAfterAgora], 'The later PumpIT allocation wins.');
        $this->assertSame($agoraId, (int) $view->AllocationId, 'Agora\'s allocation is still on record, just not in force.');

        $row = $this->row($this->propose('2026-07-21', '2026-07-21'), 'NEW');
        $this->assertSame(['allocated', 'legacy', 'TZR02', 1], [$row->State, $row->AllocatedBy, $row->EmployeeCode, (int) $row->ChangedInPumpIt]);

        $commit = collect($this->procedures()->call('usp_Cash_ZreadCommits', [
            'BranchId' => self::BRANCH, 'DateFrom' => '2026-07-21', 'DateTo' => '2026-07-21',
        ]))->firstWhere('CommitRef', $press->CommitRef);
        $this->assertSame(['changed in PumpIT', 1, 0], [$commit->State, (int) $commit->ChangedInPumpIt, (int) $commit->CanReverse]);

        try {
            $this->reverse($press->CommitRef);
            $this->fail('A press whose Z-read PumpIT has since re-allocated must not be reversible.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CASH_COMMIT_MOVED', $e->code());
        }

        // PumpIT clears it altogether: the latest word is "nobody", so the
        // Z-read is open again and back in the Unallocated Z-reads queue.
        $this->db()->update("UPDATE PumpIT.dbo.DBF_P3TRANS_ZREAD SET TillNo = NULL, ShiftNo = NULL, EmployeeCode = NULL
                             WHERE SSBranchId = ? AND [DATE] = '2026-07-21' AND USERID = 'NEW'", [self::BRANCH]);

        $row = $this->row($this->propose('2026-07-21', '2026-07-21'), 'NEW');
        $this->assertSame(['open', 1], [$row->State, (int) $row->ChangedInPumpIt]);

        // The queue's own predicate (usp_Reports_GridUnallocatedZReads). Asked
        // of the view rather than through the report, because the report joins
        // agora.vw_Branch, which reads PumpIT's SS_Branch — and the fixture
        // branch 999 is Agora's, not PumpIT's.
        $queued = $this->db()->selectOne("SELECT COUNT(*) AS n FROM agora.vw_CashZread
                                          WHERE BranchId = ? AND ZReadDate = '2026-07-21' AND PosUserId = 'NEW'
                                            AND IsAllocated = 0 AND IsPartlyAllocated = 0", [self::BRANCH]);
        $this->assertSame(1, (int) $queued->n, 'Cleared in PumpIT after Agora saved it: back in the Unallocated queue.');
    }

    /** A journal allocation never reached PumpIT, so PumpIT differing from it is not a change: Agora's stands. */
    public function test_a_journal_allocation_is_not_overruled_by_a_pumpit_row_it_never_wrote(): void
    {
        $this->stage('NEW', 21, 3, 'TZR04');
        $this->commit('journal');

        $this->assertSame([null, null, null], $this->pumpit('NEW'));
        $this->assertSame(['agora', 0], $this->viewOf('NEW'));
    }

    /** The screen says what Update ZREAD writes, lists the presses, and offers Reverse only where it may. */
    public function test_the_screen_says_what_is_written_and_offers_reverse_only_where_it_may(): void
    {
        $admin = $this->person('admin');
        $auditor = $this->person('auditor');
        $scope = ['branch_id' => self::BRANCH, 'date_from' => '2026-07-21', 'date_to' => '2026-07-21', 'show' => 'all'];

        $this->stage('NEW', 21, 3, 'TZR04');
        $this->commit('live');

        $this->actingAs($admin)->get(route('app.cash.zread', $scope))
            ->assertOk()
            ->assertSee('Update ZREAD writes to PumpIT')
            ->assertSee('Saved presses')
            ->assertSee('agora.usp_Cash_ZreadCommits')
            ->assertSee('name="commit_ref"', false)
            ->assertSee('in force')
            ->assertSee('ago');

        $this->actingAs($auditor)->get(route('app.cash.zread', $scope))
            ->assertOk()
            ->assertSee('Saved presses')
            ->assertDontSee('name="commit_ref"', false);

        // Re-allocated in PumpIT: the grid and the row say so in words, and the
        // press loses its Reverse.
        $this->db()->update("UPDATE PumpIT.dbo.DBF_P3TRANS_ZREAD SET EmployeeCode = 'TZR02'
                             WHERE SSBranchId = ? AND [DATE] = '2026-07-21' AND USERID = 'NEW'", [self::BRANCH]);

        $this->actingAs($admin)->get(route('app.cash.zread', $scope))
            ->assertSee('changed in PumpIT after Agora saved it')
            ->assertSee('can no longer be reversed')
            ->assertDontSee('name="commit_ref"', false);

        $this->actingAs($admin)
            ->get(route('app.cash.zread.row', $scope + ['date' => '2026-07-21', 'location' => 'ARCH', 'eod' => '21',
                'term' => '2', 'log' => $this->logOf('NEW', '2026-07-21'), 'user' => 'NEW']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Changed in PumpIT:');

        // With the stamp off the screen says so instead.
        config(['cash.zread.stamp_mode' => 'journal']);
        $this->actingAs($admin)->get(route('app.cash.zread', $scope))
            ->assertSee('The PumpIT stamp is off')
            ->assertDontSee('Update ZREAD writes to PumpIT');
    }

    /** Every timestamp Cash writes into Agora is UTC, like the application's. */
    public function test_the_ledger_is_stamped_in_utc(): void
    {
        $this->stage('NEW', 21, 3, 'TZR04');
        $this->commit('live');

        $row = $this->db()->selectOne(
            'SELECT ABS(DATEDIFF(second, a.AllocatedAt, SYSUTCDATETIME())) AS AllocatedDrift,
                    ABS(DATEDIFF(second, a.CreatedAt, SYSUTCDATETIME())) AS CreatedDrift,
                    ABS(DATEDIFF(second, a.StampedAt, SYSUTCDATETIME())) AS StampedDrift
             FROM agora.ZreadAllocation a WHERE a.BranchId = ?', [self::BRANCH]
        );

        $this->assertLessThan(120, (int) $row->AllocatedDrift);
        $this->assertLessThan(120, (int) $row->CreatedDrift);
        $this->assertLessThan(120, (int) $row->StampedDrift);

        foreach (glob(base_path('Modules/Cash/Database/Procedures/*.sql')) as $file) {
            $this->assertStringNotContainsString('SYSDATETIME()', (string) file_get_contents($file),
                basename($file).' stamps local time into an Agora table.');
        }
    }

    public function test_a_save_with_nothing_staged_is_a_refusal_not_a_silent_success(): void
    {
        $this->expectException(AgoraProcException::class);
        $this->expectExceptionMessage('Nothing is staged here');

        $this->procedures()->write('usp_Cash_CommitZreadAllocation', $this->scope('2026-07-21') + ['UserId' => 1]);
    }

    public function test_accepting_an_incomplete_proposal_is_refused(): void
    {
        try {
            $this->procedures()->write('usp_Cash_SetZreadAllocation', $this->key('ALT') + ['Source' => 'accepted', 'UserId' => 1]);
            $this->fail('Accepting a proposal with no shift must be refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CASH_ZREAD_INCOMPLETE', $e->code());
        }

        // Accepting a complete one takes the proposer's values, not the form's.
        $this->procedures()->write('usp_Cash_SetZreadAllocation', $this->key('77') + [
            'Source' => 'accepted', 'TillNo' => 99, 'ShiftNo' => 99, 'EmployeeCode' => 'NOPE', 'UserId' => 1,
        ]);
        $staged = $this->ledger()->firstWhere('PosUserId', '77');
        $this->assertSame([23, 3, 'TZR03', 'review'], [(int) $staged->TillNo, (int) $staged->ShiftNo, $staged->EmployeeCode, $staged->Confidence]);
    }

    public function test_a_till_or_person_from_another_site_is_refused(): void
    {
        foreach ([
            ['TillNo' => 99, 'ShiftNo' => 1, 'EmployeeCode' => 'TZR01', 'code' => 'CASH_ZREAD_TILL'],
            ['TillNo' => 21, 'ShiftNo' => 2, 'EmployeeCode' => 'TZR01', 'code' => 'CASH_ZREAD_SHIFT'],
            ['TillNo' => 21, 'ShiftNo' => 1, 'EmployeeCode' => 'NOBODY', 'code' => 'CASH_ZREAD_EMPLOYEE'],
        ] as $case) {
            $code = $case['code'];
            unset($case['code']);

            try {
                $this->procedures()->write('usp_Cash_SetZreadAllocation', $this->key('NEW') + $case + ['Source' => 'manual', 'UserId' => 1]);
                $this->fail("{$code} was not refused.");
            } catch (AgoraProcException $e) {
                $this->assertSame($code, $e->code());
            }
        }

        $this->assertCount(0, $this->ledger(), 'A refused set leaves nothing staged.');
    }

    public function test_clearing_marks_the_staging_and_deletes_nothing(): void
    {
        $this->procedures()->write('usp_Cash_AutoAllocate', $this->scope('2026-07-21') + ['Threshold' => 'review', 'UserId' => 1]);

        $cleared = $this->procedures()->write('usp_Cash_ClearZreadProposals', $this->scope('2026-07-21') + ['UserId' => 1]);

        $this->assertSame(2, (int) $cleared->Cleared);
        $this->assertCount(2, $this->ledger(), 'Cleared rows stay, as the record of who staged and who took it back.');
        $this->assertSame(['cleared'], $this->ledger()->pluck('Status')->unique()->values()->all());
    }

    /** The grid procedure honours the template: page, TotalRows, grand totals, header filters. */
    public function test_the_grid_procedure_keeps_the_grid_contract(): void
    {
        $sets = $this->procedures()->callSets('usp_Cash_GridZread', [
            'BranchId' => self::BRANCH, 'PeriodFrom' => '2026-07-21', 'PeriodTo' => '2026-07-21',
            'FiltersJson' => json_encode([['column' => 'Decision', 'type' => 'set', 'in' => ['Certain', 'Review']]]),
        ]);

        $this->assertCount(3, $sets);
        $this->assertSame(2, (int) $sets[1]->first()->TotalRows);
        $this->assertEqualsCanonicalizing(['Certain', 'Review'], $sets[0]->pluck('Decision')->all());
        $this->assertEqualsWithDelta(200.0, (float) $sets[2]->first()->Sales, 0.001, 'Grand total of the filtered set, not of the page.');

        // No site, no answer — a refusal the grid shows, not an empty table.
        $this->expectException(AgoraProcException::class);
        $this->procedures()->callSets('usp_Cash_GridZread', ['PeriodFrom' => '2026-07-21', 'PeriodTo' => '2026-07-21']);
    }

    /**
     * The screen, the row panel and the three presses, as a person with the
     * grants and as one without.
     */
    public function test_the_screen_stages_and_saves_for_an_allocator_and_only_shows_for_an_auditor(): void
    {
        $admin = $this->person('admin');
        $scope = ['branch_id' => self::BRANCH, 'date_from' => '2026-07-21', 'date_to' => '2026-07-21'];

        $this->actingAs($admin)->get(route('app.cash.zread', $scope))
            ->assertOk()
            ->assertSee('ZREAD Allocation')
            ->assertSee('id="zread-auto"', false)
            ->assertSee('id="zread-commit"', false)
            ->assertSee('agora.usp_Cash_GridZread')
            ->assertSee('Needs a person');

        $this->actingAs($admin)
            ->get(route('app.cash.zread.row', $scope + ['date' => '2026-07-21', 'location' => 'ARCH',
                'eod' => '21', 'term' => '2', 'log' => $this->logOf('90', '2026-07-21'), 'user' => '90']),
                ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('on 8 of 9 prior Z-reads')
            ->assertSee('Accept suggestion')
            ->assertSee('agora.usp_Cash_ZreadTerminalHistory');

        $this->actingAs($admin)->post(route('app.cash.zread.auto'), $scope)
            ->assertRedirect(route('app.cash.zread', $scope))
            ->assertSessionHas('zreadCode', 'CASH_ZREAD_STAGED');

        $this->actingAs($admin)->post(route('app.cash.zread.commit'), $scope)
            ->assertSessionHas('zreadCode', 'CASH_ZREAD_SAVED');

        $this->assertSame(1, $this->ledger()->where('Status', 'allocated')->count());

        $auditor = $this->person('auditor');

        $this->actingAs($auditor)->get(route('app.cash.zread', $scope))
            ->assertOk()
            ->assertSee('agora.usp_Cash_GridZread')
            ->assertDontSee('id="zread-auto"', false)
            ->assertDontSee('id="zread-commit"', false);

        $this->actingAs($auditor)->post(route('app.cash.zread.commit'), $scope)->assertForbidden();
        $this->actingAs($auditor)->post(route('app.cash.zread.auto'), $scope)->assertForbidden();

        $ref = (string) $this->ledger()->firstWhere('Status', 'allocated')->CommitRef;
        $this->actingAs($auditor)->post(route('app.cash.zread.reverse'), $scope + ['commit_ref' => $ref, 'reason' => 'TEST'])
            ->assertForbidden();

        // The press stamped the stub row (stamp_mode is 'live'), and Reverse
        // puts it back.
        $this->assertSame([21, 1, 'TZR01'], $this->pumpit('90'));
        $this->actingAs($admin)->post(route('app.cash.zread.reverse'), $scope + ['commit_ref' => $ref, 'reason' => 'TEST-undo'])
            ->assertSessionHas('zreadCode', 'CASH_COMMIT_REVERSED');
        $this->assertSame([null, null, null], $this->pumpit('90'));
    }

    /** The phone lists what it can confirm and confirms one Z-read in one press. */
    public function test_the_phone_confirms_one_proposal_and_saves_it(): void
    {
        $admin = $this->person('admin');
        $scope = ['branch_id' => self::BRANCH, 'date_from' => '2026-07-21', 'date_to' => '2026-07-21'];

        $this->actingAs($admin)->get(route('app.cash.zread.confirm', $scope))
            ->assertOk()
            ->assertSee('Confirm Z-reads')
            ->assertSee('Confirm writes to PumpIT')
            ->assertSee('its till, shift and person are written onto its PumpIT row', false)
            ->assertSee('TEST-Amahle Zulu')
            ->assertSee('2 more Z-reads the system cannot call');

        $this->actingAs($admin)->post(route('app.cash.zread.confirm.store'), $scope + [
            'date' => '2026-07-21', 'location' => 'ARCH', 'eod' => '21', 'term' => '4',
            'log' => $this->logOf('77', '2026-07-21'), 'user' => '77',
        ])->assertSessionHas('zreadStatus');

        $allocated = $this->ledger()->where('Status', 'allocated');
        $this->assertCount(1, $allocated);
        $this->assertSame(['77', 'accepted', 'review'], [$allocated->first()->PosUserId, $allocated->first()->Source, $allocated->first()->Confidence]);
    }

    /* ------------------------------------------------------------------ */

    /** @return Collection<int, object> */
    private function propose(string $from, string $to, bool $replay = false): Collection
    {
        return $this->procedures()->call('usp_Cash_ProposeZreadAllocation', [
            'BranchId' => self::BRANCH, 'DateFrom' => $from, 'DateTo' => $to, 'Replay' => $replay ? 1 : 0,
        ]);
    }

    /** @param  Collection<int, object>  $rows */
    private function row(Collection $rows, string $operator, string $date = '2026-07-21'): object
    {
        $row = $rows->first(fn ($r) => $r->PosUserId === $operator && $r->ZReadDate === $date);
        $this->assertNotNull($row, "No Z-read for operator {$operator} on {$date}.");

        return $row;
    }

    /** @return array<string, string|int> */
    private function scope(string $date): array
    {
        return ['BranchId' => self::BRANCH, 'DateFrom' => $date, 'DateTo' => $date];
    }

    /** @return array<string, string|int> the key of one 21 July Z-read, as the procedures name it */
    private function key(string $operator): array
    {
        $terminal = ['90' => '2', 'ALT' => '3', '77' => '4', 'NEW' => '2'][$operator];

        return [
            'BranchId' => self::BRANCH, 'ZReadDate' => '2026-07-21', 'Location' => 'ARCH', 'EodCounter' => '21',
            'TerminalNo' => $terminal, 'LogFile' => $this->logOf($operator, '2026-07-21'), 'PosUserId' => $operator,
        ];
    }

    private function logOf(string $operator, string $date): string
    {
        return trim((string) $this->db()->selectOne(
            'SELECT LOGFILE FROM PumpIT.dbo.DBF_P3TRANS_ZREAD WHERE SSBranchId = ? AND [DATE] = ? AND USERID = ?',
            [self::BRANCH, $date, $operator]
        )->LOGFILE);
    }

    private function stage(string $operator, int $till, int $shift, string $employee): void
    {
        $this->procedures()->write('usp_Cash_SetZreadAllocation', $this->key($operator) + [
            'Source' => 'manual', 'TillNo' => $till, 'ShiftNo' => $shift, 'EmployeeCode' => $employee, 'UserId' => 1,
        ]);
    }

    private function commit(string $mode): object
    {
        return $this->procedures()->write('usp_Cash_CommitZreadAllocation', $this->scope('2026-07-21') + [
            'StampMode' => $mode, 'UserId' => 1,
        ]);
    }

    private function reverse(string $commitRef): object
    {
        return $this->procedures()->write('usp_Cash_ReverseZreadCommit', [
            'BranchId' => self::BRANCH, 'CommitRef' => $commitRef, 'Reason' => 'Wrong person', 'UserId' => 1,
        ]);
    }

    /** @return array{0: ?int, 1: ?int, 2: ?string} the stub row's three stamped columns */
    private function pumpit(string $operator, string $date = '2026-07-21'): array
    {
        $row = $this->db()->selectOne(
            'SELECT TillNo, ShiftNo, EmployeeCode FROM PumpIT.dbo.DBF_P3TRANS_ZREAD WHERE SSBranchId = ? AND [DATE] = ? AND USERID = ?',
            [self::BRANCH, $date, $operator]
        );

        return [
            $row->TillNo === null ? null : (int) $row->TillNo,
            $row->ShiftNo === null ? null : (int) $row->ShiftNo,
            $row->EmployeeCode === null ? null : trim((string) $row->EmployeeCode),
        ];
    }

    /** @return array{0: ?string, 1: int} AllocatedBy and the changed-in-PumpIT flag, as the view reads them */
    private function viewOf(string $operator): array
    {
        $row = $this->db()->selectOne("SELECT AllocatedBy, ChangedInPumpItAfterAgora FROM agora.vw_CashZread
                                       WHERE BranchId = ? AND ZReadDate = '2026-07-21' AND PosUserId = ?", [self::BRANCH, $operator]);

        return [$row->AllocatedBy, (int) $row->ChangedInPumpItAfterAgora];
    }

    /** @return Collection<int, object> */
    private function ledger(): Collection
    {
        return collect($this->db()->select('SELECT * FROM agora.ZreadAllocation WHERE BranchId = ? ORDER BY Id', [self::BRANCH]));
    }

    private function seedFixture(): void
    {
        $db = $this->db();

        foreach ([21 => 'TEST-Till A', 22 => 'TEST-Till B', 23 => 'TEST-Till C', 11 => 'TEST-Till W'] as $no => $name) {
            $db->table('PumpIT.dbo.BRN_Tills')->insert(['SSBranchId' => self::BRANCH, 'TillNo' => $no, 'TillDescription' => $name, 'Location' => $no === 11 ? 'WINBRANCH' : 'ARCH']);
        }

        foreach ([1 => 'TEST-Day', 3 => 'TEST-Night'] as $no => $name) {
            $db->table('PumpIT.dbo.BRN_Shifts')->insert(['SSBranchId' => self::BRANCH, 'ShiftNo' => $no, 'ShiftDescription' => $name, 'IsActive' => 1]);
        }

        foreach (array_combine(self::EMPLOYEES, ['Amahle', 'Bongani', 'Cebo', 'Dudu']) as $code => $first) {
            $db->table('PumpIT.dbo.BRN_Employee')->insert([
                'SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'FirstName' => 'TEST-'.$first, 'Surname' => 'Zulu',
                'NickName' => null, 'IsActive' => 1, 'IsPumpAttendant' => 0, 'PostNo' => 0,
            ]);
        }

        // terminal 2 / operator 90: TZR01 on till 21, day, 8 of 9.
        foreach (range(1, 8) as $day) {
            $this->zread($day, '2', '90', [21, 1, 'TZR01']);
        }
        $this->zread(9, '2', '90', [21, 3, 'TZR01']);

        // terminal 3 / ALT: always TZR02, day and night in equal measure.
        foreach (range(1, 6) as $day) {
            $this->zread($day, '3', 'ALT', [22, $day % 2 === 0 ? 1 : 3, 'TZR02']);
        }

        // terminal 4 at night: 77 has been two people; 55 is always TZR03.
        foreach ([1 => 'TZR03', 2 => 'TZR03', 3 => 'TZR04', 4 => 'TZR04'] as $day => $who) {
            $this->zread($day, '4', '77', [23, 3, $who]);
        }
        foreach ([5, 6, 7] as $day) {
            $this->zread($day, '4', '55', [23, 3, 'TZR03']);
        }

        // The period: nobody has allocated these.
        foreach ([['2', '90'], ['3', 'ALT'], ['4', '77'], ['2', 'NEW']] as [$terminal, $operator]) {
            $this->zread(21, $terminal, $operator, null, 100.0);
        }

        // 22 July: allocated by the branch, against the pattern (a night shift).
        $this->zread(22, '2', '90', [21, 3, 'TZR01']);

        // WINBRANCH terminal 1: two Z-reads a day, ANNA and BEA taking turns.
        // Inserted in log-file order — the first of each day is the Day shift.
        foreach (range(1, 6) as $day) {
            [$early, $late] = $day % 2 === 0 ? ['ANNA', 'BEA'] : ['BEA', 'ANNA'];
            $this->zread($day, '1', $early, [11, 1, $early === 'ANNA' ? 'TZR01' : 'TZR02'], 50.0, 'WINBRANCH');
            $this->zread($day, '1', $late, [11, 3, $late === 'ANNA' ? 'TZR01' : 'TZR02'], 50.0, 'WINBRANCH');
        }
        $this->zread(23, '1', 'ANNA', null, 100.0, 'WINBRANCH');
        $this->zread(23, '1', 'BEA', null, 100.0, 'WINBRANCH');
    }

    /** @param  array{0: int, 1: int, 2: string}|null  $allocation */
    private function zread(int $day, string $terminal, string $operator, ?array $allocation, float $sales = 50.0, string $location = 'ARCH'): void
    {
        $this->db()->table('PumpIT.dbo.DBF_P3TRANS_ZREAD')->insert([
            'SSBranchId' => self::BRANCH,
            'DATE' => sprintf('2026-07-%02d', $day),
            'EOD_CNTR' => (string) $day,
            'TERMNUM' => $terminal,
            'LOGFILE' => (string) $this->logFile++,
            'USERID' => $operator,
            'LOCATION' => $location,
            'Sales' => $sales,
            'TillNo' => $allocation[0] ?? null,
            'ShiftNo' => $allocation[1] ?? null,
            'EmployeeCode' => $allocation[2] ?? null,
            'isCaptured' => 0,
            'MIST_EOD' => null,
        ]);
    }

    private function person(string $profile): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-t036-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-'.$profile,
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        $this->grantProfile($user, $profile);
        $this->made[] = $user;

        return $user;
    }

    private function cleanUp(): void
    {
        $db = $this->db();

        $db->table('agora.ZreadAllocation')->where('BranchId', self::BRANCH)->delete();

        foreach (['DBF_P3TRANS_ZREAD', 'BRN_Tills', 'BRN_Shifts'] as $table) {
            $db->table("PumpIT.dbo.{$table}")->where('SSBranchId', self::BRANCH)->delete();
        }

        // Only this fixture's people: other fixtures seed branch 999 staff too.
        $db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)->whereIn('EmployeeCode', self::EMPLOYEES)->delete();
    }

    private function procedures(): ProcedureService
    {
        return new ProcedureService;
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
