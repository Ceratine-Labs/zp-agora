<?php

namespace Tests\Feature\Recon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Cash\Mail\StaffShortDecidedMail;
use Modules\Cash\Mail\StaffShortRaisedMail;
use Modules\Cash\Services\StaffShortService;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Services\ReconMatchService;
use Modules\Recon\Services\ReconService;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * The split by cashier is kept with its batch (task 01a084fd-07f8).
 *
 * usp_Recon_ManualMatch writes agora.ReconAllocation — one row per cashier
 * line of a confirmed cash-bag split — inside the match's own transaction.
 * Each line carries the staff short it is due: a short only for a NEGATIVE
 * share (bank less bags) with a cashier to answer for it — RAISED in live mode,
 * inside the match, through T040's usp_Cash_RaiseStaffShort ('raised', with
 * its StaffShortsNo), 'journal' when the match was journalled, 'none' for an
 * over or nothing, 'no_cashier' for the bags that record none (Ryan, 27 Sep
 * 2026). usp_Recon_Reverse marks the lines reversed and never deletes them —
 * declining a raised short still waiting for approval, and refusing while one
 * is approved or posted (PM decision 01a0e388); usp_Recon_DiscardRuns can
 * never orphan one; usp_Recon_Trace shows them.
 *
 * Writes into the local PumpIT stub only (skipUnlessLocalStub), at branch 995,
 * which no other test uses, and removes only the TEST-A4 rows it made and the
 * ledger rows at that branch — the staff shorts raised included. Live mode
 * where the short's status depends on it — the stub's batch and staff-short
 * counters move, nothing else does.
 */
class ReconAllocationTest extends TestCase
{
    private const BRANCH = 995;

    private const FROM = '2026-09-15';

    private const TO = '2026-09-25';

    private ProcedureService $procedures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();

        if (! $this->db()->table('agora.StaffShortReason')->where('Code', 'cashier_short')->exists()) {
            $this->markTestSkipped('A live cash-bag match raises its shorts: run php artisan seed:master --only=StaffShortReasonSeeder');
        }

        $this->procedures = app(ProcedureService::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    /**
     * Three cashiers, R500 / R300 / R200 banked, the bank R9.99 short, the
     * derived split confirmed: three lines, the procedure's own shares
     * (-4.99 with the remainder on the largest, -3.00, -2.00), each a short
     * RAISED in the same press — one BRN_StaffShorts row per line, as T040
     * writes it, waiting for an approver.
     */
    public function test_a_three_cashier_match_keeps_three_lines_each_with_its_short_raised(): void
    {
        $this->threeCashiers();
        $bank = $this->bankLine(990.01);

        $status = $this->match([$bank], $this->bags, [
            ['code' => 'TEST-A4C1', 'banked' => '500.00', 'share' => '-4.99'],
            ['code' => 'TEST-A4C2', 'banked' => '300.00', 'share' => '-3.00'],
            ['code' => 'TEST-A4C3', 'banked' => '200.00', 'share' => '-2.00'],
        ], 'TEST-A4 short banking', 'live');

        $batch = $this->db()->table('agora.ReconBatch')->where('BranchId', self::BRANCH)->sole();
        $lines = $this->lines();

        $this->assertCount(3, $lines);
        $this->assertSame([(int) $status->Id], $lines->pluck('RunId')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $this->assertSame([(int) $batch->Id], $lines->pluck('BatchId')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $this->assertSame([(int) $status->BatchNo], $lines->pluck('BatchNo')->map(fn ($v) => (int) $v)->unique()->values()->all(),
            'Each line carries the batch number the match took.');

        $this->assertSame([
            // code, name, bags, banked, drop safe, derived, share, accountable, basis, short
            ['TEST-A4C1', 'TEST-A4 Largest Ayanda', 1, '500.00', '520.00', '-4.99', '-4.99', '495.01', 'derived', 'raised'],
            ['TEST-A4C2', 'TEST-A4 Middle Bongani', 1, '300.00', '300.00', '-3.00', '-3.00', '297.00', 'derived', 'raised'],
            ['TEST-A4C3', 'TEST-A4 Smallest Cebo', 2, '200.00', '200.00', '-2.00', '-2.00', '198.00', 'derived', 'raised'],
        ], $this->shape($lines));

        $first = $lines->first();
        $this->assertSame(1, (int) $first->ConfirmedBy);
        $this->assertNotNull($first->ConfirmedAt);
        $this->assertNull($first->ReversedAt);

        // T040: one short per line, raised through usp_Cash_RaiseStaffShort —
        // -Share, dated by the cashier's last bag, a cashier short naming the batch.
        $shorts = $this->shorts();
        $this->assertSame($lines->pluck('StaffShortsNo')->map(fn ($v) => (int) $v)->sort()->values()->all(),
            $shorts->pluck('StaffShortsNo')->map(fn ($v) => (int) $v)->sort()->values()->all(), 'Each line names its short.');
        $this->assertSame([
            ['TEST-A4C1', '4.99', '2026-09-20', 'Cashier short', 0, 0],
            ['TEST-A4C2', '3.00', '2026-09-20', 'Cashier short', 0, 0],
            ['TEST-A4C3', '2.00', '2026-09-20', 'Cashier short', 0, 0],
        ], $shorts->map(fn ($s) => [$s->EmployeeCode, number_format((float) $s->AmountShort, 2, '.', ''),
            substr((string) $s->TransactionDate, 0, 10), $s->Reason, (int) $s->Approved, (int) $s->IsDeclined])->values()->all());
        $this->assertStringContainsString('recon batch '.$status->BatchNo, (string) $shorts->first()->Note);

        $agora = $this->db()->table('agora.StaffShort')->where('BranchId', self::BRANCH)->orderBy('StaffShortsNo')->get();
        $this->assertSame(['recon'], $agora->pluck('Source')->unique()->values()->all());
        $this->assertSame($lines->sortBy('StaffShortsNo')->pluck('Id')->map(fn ($v) => (int) $v)->values()->all(),
            $agora->pluck('SourceId')->map(fn ($v) => (int) $v)->values()->all(), 'Each short leads back to its line.');
        $this->assertSame(['cashier_short'], $agora->pluck('ReasonCode')->unique()->values()->all());

        $numbers = $shorts->pluck('StaffShortsNo')->map(fn ($v) => (int) $v)->sort()->implode(', ');
        $this->assertStringContainsString("Staff shorts {$numbers} raised for approval under Cash.", (string) $status->Message);
        $this->assertSame(['2026-09-19', '2026-09-20'], [substr($lines[2]->FirstBagDate, 0, 10), substr($lines[2]->LastBagDate, 0, 10)],
            "The span of the third cashier's two bags.");

        // The split adds up to the batch it belongs to.
        $this->assertSame((float) $batch->MopsTotal, round((float) $lines->sum('Banked'), 2));
        $this->assertSame((float) $batch->BankTotal, round((float) $lines->sum('Accountable'), 2));
    }

    /**
     * A split changed by hand is kept line by line as the clerk left it, each
     * labelled against the procedure's own share. Journalled, so no short is
     * ever due: journal mode writes nothing to PumpIT, and a short would.
     */
    public function test_a_journalled_split_changed_by_hand_is_kept_as_confirmed_with_no_short_due(): void
    {
        $this->threeCashiers();
        $bank = $this->bankLine(990.01);

        $this->match([$bank], $this->bags, [
            ['code' => 'TEST-A4C1', 'banked' => '500.00', 'share' => '-5.00'],
            ['code' => 'TEST-A4C2', 'banked' => '300.00', 'share' => '-2.99'],
            ['code' => 'TEST-A4C3', 'banked' => '200.00', 'share' => '-2.00'],
        ], 'TEST-A4 short banking');

        $this->assertSame([
            ['TEST-A4C1', 'TEST-A4 Largest Ayanda', 1, '500.00', '520.00', '-4.99', '-5.00', '495.00', 'manual', 'journal'],
            ['TEST-A4C2', 'TEST-A4 Middle Bongani', 1, '300.00', '300.00', '-3.00', '-2.99', '297.01', 'manual', 'journal'],
            ['TEST-A4C3', 'TEST-A4 Smallest Cebo', 2, '200.00', '200.00', '-2.00', '-2.00', '198.00', 'derived', 'journal'],
        ], $this->shape($this->lines()));

        $this->assertLessThan(0, (int) $this->lines()->first()->BatchNo, 'A journalled batch number is negative.');
        $this->assertCount(0, $this->shorts(), 'Journal mode writes nothing to PumpIT — no short either.');
    }

    /**
     * The bags with no cashier recorded keep their share on a line of their
     * own, flagged, and the match is not refused for them (Ryan, 27 Sep 2026,
     * PM decision 01a0e375). The cashier's line is a short due.
     */
    public function test_the_no_cashier_line_keeps_its_share_and_raises_nothing(): void
    {
        $this->employee('TEST-A4C1', 'Nokuthula', 'TEST-A4 Champion');
        $bags = [
            $this->bag('TEST-A4-B1', 600.00, 'TEST-A4C1'),
            $this->bag('TEST-A4-B2', 400.00, null),
        ];
        $bank = $this->bankLine(990.00);

        $this->match([$bank], $bags, [
            ['code' => 'TEST-A4C1', 'banked' => '600.00', 'share' => '-6.00'],
            ['code' => null, 'banked' => '400.00', 'share' => '-4.00'],
        ], 'TEST-A4 bank short', 'live');

        $this->assertSame([
            ['TEST-A4C1', 'TEST-A4 Champion Nokuthula', 1, '600.00', '600.00', '-6.00', '-6.00', '594.00', 'derived', 'raised'],
            [null, null, 1, '400.00', null, '-4.00', '-4.00', '396.00', 'derived', 'no_cashier'],
        ], $this->shape($this->lines()));

        $this->assertSame([['TEST-A4C1', '6.00']], $this->shorts()->map(fn ($s) => [$s->EmployeeCode, number_format((float) $s->AmountShort, 2, '.', '')])->all(),
            'The cashier\'s share is raised; the bags with no cashier raise nothing (PM decision 01a0e375).');
    }

    /** An over is not a short, and neither is a balanced line. */
    public function test_an_over_or_a_balanced_line_raises_nothing(): void
    {
        $this->employee('TEST-A4C1', 'Nokuthula', 'TEST-A4 Champion');
        $over = [$this->bag('TEST-A4-B1', 600.00, 'TEST-A4C1'), $this->bag('TEST-A4-B2', 400.00, null)];

        $this->match([$this->bankLine(1010.00)], $over, [
            ['code' => 'TEST-A4C1', 'banked' => '600.00', 'share' => '6.00'],
            ['code' => null, 'banked' => '400.00', 'share' => '4.00'],
        ], 'TEST-A4 bank over', 'live');

        $this->assertSame(['none', 'no_cashier'], $this->lines()->pluck('ShortStatus')->all());

        $balanced = [$this->bag('TEST-A4-B3', 250.00, 'TEST-A4C1')];

        $this->match([$this->bankLine(250.00)], $balanced, [
            ['code' => 'TEST-A4C1', 'banked' => '250.00', 'share' => '0.00'],
        ], null, 'live');

        $line = $this->lines()->firstWhere('Banked', '250.00');
        $this->assertSame([0.0, 'none'], [(float) $line->Share, $line->ShortStatus]);
        $this->assertCount(0, $this->shorts(), 'Neither an over nor a balanced line raises a short.');
    }

    /**
     * Ryan, 27 Sep 2026 (PM decision 01a0e388): every negative share is raised,
     * however small — the R3 leniency is the deduction schedule's — and a split
     * changed by hand that gives one cashier a short and another an over raises
     * the short alone.
     */
    public function test_every_negative_share_is_raised_however_small_and_an_over_beside_it_is_not(): void
    {
        $this->employee('TEST-A4C1', 'Ayanda', 'TEST-A4 Largest');
        $this->employee('TEST-A4C2', 'Bongani', 'TEST-A4 Middle');
        $bags = [$this->bag('TEST-A4-B1', 500.00, 'TEST-A4C1'), $this->bag('TEST-A4-B2', 300.00, 'TEST-A4C2')];

        $this->match([$this->bankLine(799.99)], $bags, [
            ['code' => 'TEST-A4C1', 'banked' => '500.00', 'share' => '-2.01'],
            ['code' => 'TEST-A4C2', 'banked' => '300.00', 'share' => '2.00'],
        ], 'TEST-A4 split by hand', 'live');

        $this->assertSame(['raised', 'none'], $this->lines()->pluck('ShortStatus')->all());
        $this->assertSame([['TEST-A4C1', '2.01']], $this->shorts()->map(fn ($s) => [$s->EmployeeCode, number_format((float) $s->AmountShort, 2, '.', '')])->all());

        $this->assertSame(0, $this->shorts()->where('EmployeeCode', 'TEST-A4C2')->count(), 'The over raises nothing.');

        $cent = [$this->bag('TEST-A4-B3', 100.00, 'TEST-A4C2')];
        $this->match([$this->bankLine(99.99)], $cent, [
            ['code' => 'TEST-A4C2', 'banked' => '100.00', 'share' => '-0.01'],
        ], 'TEST-A4 a cent', 'live');

        $this->assertSame('0.01', number_format((float) $this->shorts()->firstWhere('EmployeeCode', 'TEST-A4C2')->AmountShort, 2, '.', ''),
            'A cent short is raised: the leniency is applied at deductions, not here.');
    }

    /** A short that cannot be raised fails the match: nothing is stamped, nothing is kept. */
    public function test_a_cashier_not_on_the_employee_list_fails_the_whole_match(): void
    {
        $bags = [$this->bag('TEST-A4-B1', 600.00, 'TEST-A4CX')];   // no BRN_Employee row
        $bank = $this->bankLine(590.00);

        $this->assertRefused('CASH_SHORT_EMPLOYEE', fn () => $this->match([$bank], $bags, [
            ['code' => 'TEST-A4CX', 'banked' => '600.00', 'share' => '-10.00'],
        ], 'TEST-A4 bank short', 'live'));

        $this->assertCount(0, $this->lines());
        $this->assertSame(0, $this->db()->table('agora.ReconBatch')->where('BranchId', self::BRANCH)->count());
        $this->assertSame(1, (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
            ->where('BankStatementLineID', $bank)->value('ReconState'), 'The bank line is still outstanding.');
        $this->assertCount(0, $this->shorts());
    }

    /** A refused split keeps nothing — not a line, not a run, not a batch. */
    public function test_a_refused_split_writes_no_line(): void
    {
        $this->threeCashiers();
        $bank = $this->bankLine(990.01);

        // Adds to -10.00 against a variance of -9.99.
        $this->assertRefused('ALLOCATION_INCOMPLETE', fn () => $this->match([$bank], $this->bags, [
            ['code' => 'TEST-A4C1', 'banked' => '500.00', 'share' => '-5.00'],
            ['code' => 'TEST-A4C2', 'banked' => '300.00', 'share' => '-3.00'],
            ['code' => 'TEST-A4C3', 'banked' => '200.00', 'share' => '-2.00'],
        ], 'TEST-A4 short banking', 'live'));

        // A cashier left out.
        $this->assertRefused('ALLOCATION_INCOMPLETE', fn () => $this->match([$bank], $this->bags, [
            ['code' => 'TEST-A4C1', 'banked' => '500.00', 'share' => '-4.99'],
            ['code' => 'TEST-A4C2', 'banked' => '300.00', 'share' => '-5.00'],
        ], 'TEST-A4 short banking', 'live'));

        $this->assertCount(0, $this->lines());
        $this->assertSame(0, $this->db()->table('agora.ReconRun')->where('BranchId', self::BRANCH)->count());
        $this->assertSame(0, $this->db()->table('agora.ReconBatch')->where('BranchId', self::BRANCH)->count());
    }

    /**
     * Reversal marks the lines, in the same press, and keeps every figure. The
     * short the match raised is still waiting for approval, so the reversal
     * DECLINES it through Cash, with a reason naming the reversal, and its line
     * becomes 'cancelled' keeping the number (PM decision 01a0e388). The bags
     * then match again under a new batch, with a split and a short of their
     * own, beside the reversed one.
     */
    public function test_reversing_marks_the_lines_reversed_and_the_numbers_still_read(): void
    {
        $this->employee('TEST-A4C1', 'Nokuthula', 'TEST-A4 Champion');
        $bags = [$this->bag('TEST-A4-B1', 600.00, 'TEST-A4C1'), $this->bag('TEST-A4-B2', 400.00, null)];
        $bank = $this->bankLine(990.00);
        $split = [
            ['code' => 'TEST-A4C1', 'banked' => '600.00', 'share' => '-6.00'],
            ['code' => null, 'banked' => '400.00', 'share' => '-4.00'],
        ];

        $matched = $this->match([$bank], $bags, $split, 'TEST-A4 bank short', 'live');
        $before = $this->shape($this->lines());

        $reversed = $this->procedures->write('usp_Recon_Reverse', [
            'BranchId' => self::BRANCH, 'BatchId' => null, 'RunId' => (int) $matched->Id,
            'Reason' => 'TEST-A4 wrong bags', 'UserId' => 1,
        ]);

        $this->assertStringContainsString('The split by cashier (2 line(s)) is kept, marked reversed.', $reversed->Message);

        $raised = (int) $this->lines()->first()->StaffShortsNo;
        $this->assertStringContainsString("Staff short {$raised} declined with it.", $reversed->Message);
        $short = $this->shorts()->firstWhere('StaffShortsNo', $raised);
        $this->assertSame([0, 1], [(int) $short->Approved, (int) $short->IsDeclined]);
        $this->assertSame(mb_substr('Recon batch '.$matched->BatchNo.' reversed: TEST-A4 wrong bags', 0, 50), $short->DeclinedReason);

        $lines = $this->lines();
        $this->assertCount(2, $lines, 'Nothing is deleted.');
        $this->assertSame(['cancelled', 'no_cashier'], $lines->pluck('ShortStatus')->all());
        $this->assertSame($raised, (int) $lines->first()->StaffShortsNo, 'The cancelled line keeps the number it raised.');
        foreach ($lines as $line) {
            $this->assertNotNull($line->ReversedAt);
            $this->assertSame(1, (int) $line->ReversedBy);
            $this->assertSame('TEST-A4 wrong bags', $line->ReversalReason);
        }

        // Every figure as confirmed; only the status of the raised short moved.
        $before[0][9] = 'cancelled';
        $this->assertSame($before, $this->shape($lines));

        $this->assertRefused('ALREADY_REVERSED', fn () => $this->procedures->write('usp_Recon_Reverse', [
            'BranchId' => self::BRANCH, 'BatchId' => null, 'RunId' => (int) $matched->Id,
            'Reason' => 'TEST-A4 again', 'UserId' => 1,
        ]));

        // The bags are outstanding again, and match again as a new batch.
        $again = $this->match([$bank], $bags, $split, 'TEST-A4 bank short, second look', 'live');

        $this->assertNotSame((int) $matched->BatchNo, (int) $again->BatchNo);
        $this->assertCount(4, $this->lines());
        $this->assertSame(['raised', 'no_cashier'],
            $this->lines()->where('RunId', $again->Id)->pluck('ShortStatus')->values()->all());
        $this->assertCount(2, $this->shorts(), 'A new short for the new match; the declined one stays, declined.');
    }

    /**
     * THE T040 CALL SITE, the other way. Once the short the match raised has
     * been APPROVED — or posted to a cashup — the reversal is refused until it
     * is dealt with in Cash, and nothing changes: not the batch, not the line,
     * not the stamps, not the short (PM decision 01a0e388).
     */
    public function test_a_match_whose_short_was_approved_or_posted_is_not_reversed_behind_it(): void
    {
        $this->employee('TEST-A4C1', 'Nokuthula', 'TEST-A4 Champion');
        $bags = [$this->bag('TEST-A4-B1', 600.00, 'TEST-A4C1')];
        $bank = $this->bankLine(590.00);

        $matched = $this->match([$bank], $bags, [
            ['code' => 'TEST-A4C1', 'banked' => '600.00', 'share' => '-10.00'],
        ], 'TEST-A4 bank short', 'live');

        $raised = (int) $this->lines()->first()->StaffShortsNo;
        $this->procedures->write('usp_Cash_ApproveStaffShort', [
            'BranchId' => self::BRANCH, 'StaffShortsNo' => $raised, 'WriteMode' => 'live', 'UserId' => 1,
        ]);

        $this->assertRefused('SHORT_RAISED', fn () => $this->procedures->write('usp_Recon_Reverse', [
            'BranchId' => self::BRANCH, 'BatchId' => null, 'RunId' => (int) $matched->Id,
            'Reason' => 'TEST-A4 should not go through', 'UserId' => 1,
        ]), "staff short {$raised} for TEST-A4C1, and it has already been approved. Withdraw it in Cash first");

        $this->assertSame('committed', $this->db()->table('agora.ReconBatch')->where('BranchId', self::BRANCH)->value('State'));
        $this->assertSame(['raised', null], [$this->lines()->first()->ShortStatus, $this->lines()->first()->ReversedAt]);
        $this->assertSame(2, (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
            ->where('BankStatementLineID', $bank)->value('ReconState'), 'The bank line is still stamped.');
        $this->assertSame([1, 0], [(int) $this->shorts()->first()->Approved, (int) $this->shorts()->first()->IsDeclined],
            'The approved short is left as it was.');

        // Posted is the same answer: a cashup already counts it.
        $this->db()->table('PumpIT.dbo.BRN_StaffShorts')->where('SSBranchId', self::BRANCH)->where('StaffShortsNo', $raised)
            ->update(['IsSelectedForDailyBanking' => 1]);
        $this->assertRefused('SHORT_RAISED', fn () => $this->procedures->write('usp_Recon_Reverse', [
            'BranchId' => self::BRANCH, 'BatchId' => null, 'RunId' => (int) $matched->Id,
            'Reason' => 'TEST-A4 should not go through', 'UserId' => 1,
        ]), 'it is on a cashup, which counts it — it cannot be withdrawn');
    }

    /**
     * End to end (PM decision 01a0e5b5): the approved short stops the
     * reversal; withdrawn in Cash, it is declined, and the same reversal then
     * goes through — the line cancelled, keeping the short's number, and
     * nothing declined twice.
     */
    public function test_withdrawing_the_approved_short_in_cash_lets_the_reversal_through(): void
    {
        $this->employee('TEST-A4C1', 'Nokuthula', 'TEST-A4 Champion');
        $bank = $this->bankLine(590.00);
        $matched = $this->match([$bank], [$this->bag('TEST-A4-B1', 600.00, 'TEST-A4C1')], [
            ['code' => 'TEST-A4C1', 'banked' => '600.00', 'share' => '-10.00'],
        ], 'TEST-A4 bank short', 'live');

        $raised = (int) $this->lines()->first()->StaffShortsNo;
        $this->procedures->write('usp_Cash_ApproveStaffShort', [
            'BranchId' => self::BRANCH, 'StaffShortsNo' => $raised, 'WriteMode' => 'live', 'UserId' => 1,
        ]);

        $reverse = fn () => $this->procedures->write('usp_Recon_Reverse', [
            'BranchId' => self::BRANCH, 'BatchId' => null, 'RunId' => (int) $matched->Id, 'Reason' => 'TEST-A4 wrong bags', 'UserId' => 1,
        ]);
        $this->assertRefused('SHORT_RAISED', $reverse, 'Withdraw it in Cash first');

        $withdrawn = $this->procedures->write('usp_Cash_WithdrawStaffShort', [
            'BranchId' => self::BRANCH, 'StaffShortsNo' => $raised, 'Reason' => 'TEST-A4 the match is being reversed',
            'WriteMode' => 'live', 'UserId' => 1,
        ]);
        $this->assertSame('CASH_SHORT_WITHDRAWN', $withdrawn->Code);

        $reversed = $reverse();
        $this->assertSame('REVERSED', $reversed->Code);
        $this->assertStringNotContainsString('declined with it', (string) $reversed->Message, 'Withdrawn already: not declined twice.');

        $line = $this->lines()->first();
        $this->assertSame(['cancelled', $raised], [$line->ShortStatus, (int) $line->StaffShortsNo]);
        $this->assertNotNull($line->ReversedAt);
        $short = $this->shorts()->first();
        $this->assertSame([0, 1, 'TEST-A4 the match is being reversed'], [(int) $short->Approved, (int) $short->IsDeclined, $short->DeclinedReason]);
        $this->assertSame(1, (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
            ->where('BankStatementLineID', $bank)->value('ReconState'), 'The bank line is outstanding again.');
    }

    /**
     * The screens' own path (ReconMatchService, ReconService): a cash-bag match
     * tells the approvers of the shorts it raised once it has committed, and a
     * reversal tells the raiser the short was declined (T040). The procedure
     * cannot mail, so this is the only place it happens without the command.
     */
    public function test_the_match_and_the_reversal_tell_the_people_through_cash(): void
    {
        Mail::fake();
        $clerk = $this->admin();
        $approver = $this->approver();
        $this->employee('TEST-A4C1', 'Nokuthula', 'TEST-A4 Champion');
        $bag = $this->bag('TEST-A4-B1', 600.00, 'TEST-A4C1');

        $this->actingAs($clerk);
        $status = app(ReconMatchService::class)->match('CashBags', self::BRANCH, Carbon::parse(self::FROM), Carbon::parse(self::TO),
            [$this->bankLine(590.00)], [$bag], 'TEST-A4 bank short', null,
            [['code' => 'TEST-A4C1', 'banked' => '600.00', 'share' => '-10.00']]);

        $raised = (int) $this->lines()->first()->StaffShortsNo;
        Mail::assertSent(StaffShortRaisedMail::class, fn (StaffShortRaisedMail $m) => $m->hasTo($approver->EmailAddress)
            && $m->short['no'] === $raised && $m->short['reason'] === 'Cashier short' && $m->short['amount'] === 'R10.00');
        $this->assertNotNull($this->db()->table('agora.StaffShort')->where('BranchId', self::BRANCH)->where('StaffShortsNo', $raised)->value('RaisedMailAt'));

        app(ReconService::class)->reverse(ReconRun::query()->acrossBranches()->findOrFail((int) $status->Id), 'TEST-A4 wrong bags');

        Mail::assertSent(StaffShortDecidedMail::class, fn (StaffShortDecidedMail $m) => $m->hasTo($clerk->EmailAddress)
            && $m->short['no'] === $raised && $m->short['decision'] === 'declined');
    }

    /** A mail that cannot go never fails the match or the reversal: the short stays unannounced for the next run. */
    public function test_a_mail_failure_never_fails_the_match_or_the_reversal(): void
    {
        $this->mock(StaffShortService::class, fn ($mock) => $mock->shouldReceive('sendPending')
            ->andThrow(new RuntimeException('TEST-A4 the mail server is down')));

        $this->actingAs($this->admin());
        $this->employee('TEST-A4C1', 'Nokuthula', 'TEST-A4 Champion');

        $status = app(ReconMatchService::class)->match('CashBags', self::BRANCH, Carbon::parse(self::FROM), Carbon::parse(self::TO),
            [$this->bankLine(590.00)], [$this->bag('TEST-A4-B1', 600.00, 'TEST-A4C1')], 'TEST-A4 bank short', null,
            [['code' => 'TEST-A4C1', 'banked' => '600.00', 'share' => '-10.00']]);

        $this->assertSame('MATCHED_FORCED', $status->Code);
        $raised = (int) $this->lines()->first()->StaffShortsNo;
        $this->assertNull($this->db()->table('agora.StaffShort')->where('BranchId', self::BRANCH)->where('StaffShortsNo', $raised)->value('RaisedMailAt'),
            'Still pending for cash:staff-short-mail.');

        $reversed = app(ReconService::class)->reverse(ReconRun::query()->acrossBranches()->findOrFail((int) $status->Id), 'TEST-A4 undone');
        $this->assertSame('REVERSED', $reversed->Code);
    }

    /** A short an approver already declined leaves the reversal free: nothing is due, and nothing is declined twice. */
    public function test_a_short_already_declined_does_not_stop_the_reversal(): void
    {
        $this->employee('TEST-A4C1', 'Nokuthula', 'TEST-A4 Champion');
        $matched = $this->match([$this->bankLine(590.00)], [$this->bag('TEST-A4-B1', 600.00, 'TEST-A4C1')], [
            ['code' => 'TEST-A4C1', 'banked' => '600.00', 'share' => '-10.00'],
        ], 'TEST-A4 bank short', 'live');

        $raised = (int) $this->lines()->first()->StaffShortsNo;
        $this->procedures->write('usp_Cash_DeclineStaffShort', [
            'BranchId' => self::BRANCH, 'StaffShortsNo' => $raised, 'Reason' => 'TEST-A4 no slips', 'WriteMode' => 'live', 'UserId' => 1,
        ]);

        $reversed = $this->procedures->write('usp_Recon_Reverse', [
            'BranchId' => self::BRANCH, 'BatchId' => null, 'RunId' => (int) $matched->Id, 'Reason' => 'TEST-A4 undone', 'UserId' => 1,
        ]);

        $this->assertStringNotContainsString('declined with it', (string) $reversed->Message);
        $this->assertSame('cancelled', $this->lines()->first()->ShortStatus);
        $this->assertSame('TEST-A4 no slips', $this->shorts()->first()->DeclinedReason, 'The approver\'s decline stands.');
    }

    /** The rule is in the table too: a short is only ever a negative share with a cashier. */
    public function test_the_table_refuses_a_short_the_procedure_would_never_record(): void
    {
        $this->employee('TEST-A4C1', 'Nokuthula', 'TEST-A4 Champion');
        $this->match([$this->bankLine(1010.00)], [$this->bag('TEST-A4-B1', 1000.00, 'TEST-A4C1')], [
            ['code' => 'TEST-A4C1', 'banked' => '1000.00', 'share' => '10.00'],
        ], 'TEST-A4 bank over', 'live');

        $row = fn () => $this->db()->table('agora.ReconAllocation')->where('BranchId', self::BRANCH);

        foreach ([
            'an over as a short due' => ['ShortStatus' => 'pending'],
            'raised without a number' => ['ShortStatus' => 'raised'],
            'an unknown status' => ['ShortStatus' => 'maybe'],
            'accountable that does not add up' => ['Accountable' => 1.00],
        ] as $what => $change) {
            try {
                $row()->update($change);
                $this->fail("The table accepted {$what}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('CK_ReconAllocation_', $e->getMessage(), $what);
            }
        }
    }

    /** CashBags only: the other four areas match exactly as before and keep no split. */
    public function test_the_other_four_areas_keep_no_split(): void
    {
        $db = $this->db();
        $bank = fn (string $type, float $amount) => (int) $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insertGetId([
            'SSBranchId' => self::BRANCH, 'LineDate' => '2026-09-20', 'Description' => "TEST-A4 {$type}",
            'Amount' => $amount, 'Type' => $type, 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ]);

        $db->table('PumpIT.dbo.BRN_DailyBankingABSA')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-09-20', 'BatchNumber' => 414141,
            'MerchantNumber' => 'TEST-A4', 'TransactionAmount' => 100.00, 'ReconBatchNoPumpIT' => 0,
        ]);
        $db->table('PumpIT.dbo.BRN_DailyBankingFNB')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-09-20', 'BatchNo' => 'TEST-A4F',
            'MerchantNo' => 'TEST-A4', 'Amount' => 200.00, 'ReconBatchNoPumpIT' => 0,
        ]);
        $db->table('PumpIT.dbo.BRN_DailyBankingDeposita')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-09-20', 'SlipNo' => 'TEST-A4D',
            'DepositaAmount' => 300.00, 'ReconBatchNoPumpIT' => 0,
        ]);
        $atm = (int) $db->table('PumpIT.dbo.BRN_DailyBankingSmartATM')->insertGetId([
            'SSBranchId' => self::BRANCH, 'TerminalId' => 'TEST-A4T', 'TraceNo' => 'TEST-A4', 'UniqueNo' => 1,
            'DepositDateTime' => '2026-09-20 00:00:00', 'Deposited' => 400.00, 'ReconBatchNoPumpIT' => 0,
        ]);

        foreach ([
            'ABSA' => [$bank('ABSA', 100.00), ['id' => null, 'key' => '414141', 'dt' => '2026-09-20', 'amt' => 100.00]],
            'FNB' => [$bank('FNB', 200.00), ['id' => null, 'key' => 'TEST-A4F', 'dt' => '2026-09-20', 'amt' => 200.00]],
            'CashMachine' => [$bank('CashMachine', 300.00), ['id' => null, 'key' => 'TEST-A4D', 'dt' => '2026-09-20', 'amt' => 300.00]],
            'SmartATM' => [$bank('SmartATM', 400.00), ['id' => $atm, 'key' => 'TEST-A4T', 'dt' => '2026-09-20', 'amt' => 400.00]],
        ] as $area => [$line, $deposit]) {
            $this->assertSame('MATCHED', $this->write($area, [$line], [$deposit], null)->Code, $area);
        }

        $this->assertSame(4, $db->table('agora.ReconBatch')->where('BranchId', self::BRANCH)->count());
        $this->assertCount(0, $this->lines(), 'No area but CashBags keeps a split.');
    }

    /**
     * DiscardRuns can never orphan a line. The reversed cash-bag run is
     * refused by id and skipped by a sweep; and a run that says 'previewed'
     * but has a split hanging off it — nothing writes one today, but the rule
     * is "anything processed" — is protected by the split alone.
     */
    public function test_a_discard_can_never_orphan_a_split(): void
    {
        $this->employee('TEST-A4C1', 'Nokuthula', 'TEST-A4 Champion');
        $matched = $this->match([$this->bankLine(590.00)], [$this->bag('TEST-A4-B1', 600.00, 'TEST-A4C1')], [
            ['code' => 'TEST-A4C1', 'banked' => '600.00', 'share' => '-10.00'],
        ], 'TEST-A4 bank short', 'live');

        $this->procedures->write('usp_Recon_Reverse', [
            'BranchId' => self::BRANCH, 'BatchId' => null, 'RunId' => (int) $matched->Id,
            'Reason' => 'TEST-A4 undone', 'UserId' => 1,
        ]);

        $discard = fn (?int $runId, string $action = 'discard') => $this->procedures->write('usp_Recon_DiscardRuns', [
            'BranchId' => self::BRANCH, 'ReconArea' => null, 'RunId' => $runId, 'UserId' => 1, 'Action' => $action,
        ]);

        $this->assertRefused('RUN_COMMITTED', fn () => $discard((int) $matched->Id));

        // A run with nothing but a split against it.
        $bare = (int) $this->db()->table('agora.ReconRun')->insertGetId([
            'BranchId' => self::BRANCH, 'GroupRef' => (string) Str::uuid(), 'ReconArea' => 'CashBags',
            'FromDate' => self::FROM, 'ToDate' => self::TO, 'Status' => 'previewed', 'StampMode' => 'journal',
            'ProcedureName' => 'TEST-A4', 'ParamsJson' => '[]', 'Note' => 'TEST-A4 a split and nothing else',
            'CreatedAt' => now(), 'CreatedBy' => 1,
        ]);
        $this->db()->table('agora.ReconAllocation')->insert([
            'BranchId' => self::BRANCH, 'RunId' => $bare, 'BatchId' => 0, 'BatchNo' => 0,
            'CashierCode' => 'TEST-A4C1', 'Bags' => 1, 'Banked' => 1.00, 'FirstBagDate' => '2026-09-20',
            'LastBagDate' => '2026-09-20', 'DerivedShare' => 0, 'Share' => 0, 'Accountable' => 1.00,
            'Basis' => 'derived', 'ConfirmedAt' => now(), 'ShortStatus' => 'none',
        ]);

        $this->assertRefused('RUN_COMMITTED', fn () => $discard($bare));
        $this->assertRefused('RUN_PROCESSED', fn () => $discard($bare, 'close'));

        // A sweep of the branch takes neither.
        $this->assertSame(0, (int) $discard(null)->Id);

        $this->assertSame(2, $this->db()->table('agora.ReconRun')->where('BranchId', self::BRANCH)->count());
        $this->assertSame(0, $this->db()->table('agora.ReconAllocation')->where('BranchId', self::BRANCH)
            ->whereNotIn('RunId', $this->db()->table('agora.ReconRun')->where('BranchId', self::BRANCH)->select('Id'))
            ->count(), 'No line points at a run that is gone.');
    }

    /**
     * Trace: the split comes back as the eighth set, by any bag of the match,
     * by the batch number, and by the cashier — and the screen draws it.
     */
    public function test_the_trace_shows_the_split(): void
    {
        $this->threeCashiers();
        $matched = $this->match([$this->bankLine(990.01)], $this->bags, [
            ['code' => 'TEST-A4C1', 'banked' => '500.00', 'share' => '-4.99'],
            ['code' => 'TEST-A4C2', 'banked' => '300.00', 'share' => '-3.00'],
            ['code' => 'TEST-A4C3', 'banked' => '200.00', 'share' => '-2.00'],
        ], 'TEST-A4 short banking', 'live');

        $trace = fn (string $term) => $this->procedures->callSets('usp_Recon_Trace', [
            'Term' => $term, 'BranchIds' => (string) self::BRANCH,
            'DateFrom' => self::FROM, 'DateTo' => self::TO, 'AllowedBranchIds' => null, 'MaxRows' => 200,
        ]);

        // A bag that is not the run line's own reference, the batch, a cashier.
        $this->assertCount(3, $trace('TEST-A4-B4')[7]);
        $this->assertCount(3, $trace((string) $matched->BatchNo)[7]);
        $this->assertSame(['TEST-A4C2'], $trace('TEST-A4C2')[7]->pluck('CashierCode')->all());
        // Sets 1–7 kept their positions: the batch is still set 3.
        $this->assertSame((int) $matched->BatchNo, (int) $trace((string) $matched->BatchNo)[2]->first()->BatchNo);

        $this->actingAs($this->admin())
            ->get('/app/recon/trace?q=TEST-A4C1&branch_id='.self::BRANCH.'&from='.self::FROM.'&to='.self::TO)
            ->assertOk()
            ->assertSee('Split by cashier')
            ->assertSee('TEST-A4 Largest Ayanda')
            ->assertSee('short raised #'.$this->lines()->first()->StaffShortsNo);
    }

    // ---- helpers -----------------------------------------------------------

    /** @var array<int, array<string, mixed>> */
    private array $bags = [];

    /** Three cashiers: R500 (declared R520), R300, and R120 + R80 over two days. */
    private function threeCashiers(): void
    {
        $this->employee('TEST-A4C1', 'Ayanda', 'TEST-A4 Largest');
        $this->employee('TEST-A4C2', 'Bongani', 'TEST-A4 Middle');
        $this->employee('TEST-A4C3', 'Cebo', 'TEST-A4 Smallest');

        $this->bags = [
            $this->bag('TEST-A4-B1', 500.00, 'TEST-A4C1', dropSafe: 520.00),
            $this->bag('TEST-A4-B2', 300.00, 'TEST-A4C2'),
            $this->bag('TEST-A4-B3', 120.00, 'TEST-A4C3', date: '2026-09-19'),
            $this->bag('TEST-A4-B4', 80.00, 'TEST-A4C3'),
        ];
    }

    /**
     * The staff shorts raised at the test's branch, as PumpIT holds them.
     *
     * @return Collection<int, stdClass>
     */
    private function shorts(): Collection
    {
        return $this->db()->table('PumpIT.dbo.BRN_StaffShorts')->where('SSBranchId', self::BRANCH)
            ->where('EmployeeCode', 'like', 'TEST-A4%')->orderBy('StaffShortsNo')->get();
    }

    /** @return Collection<int, stdClass> */
    private function lines(): Collection
    {
        return $this->db()->table('agora.ReconAllocation')->where('BranchId', self::BRANCH)
            ->orderBy('BatchId')->orderByDesc('Banked')->orderBy('Id')->get();
    }

    /**
     * @param  Collection<int, stdClass>  $lines
     * @return array<int, array<int, mixed>>
     */
    private function shape(Collection $lines): array
    {
        $money = fn (mixed $v): ?string => $v === null ? null : number_format((float) $v, 2, '.', '');

        return $lines->map(fn (stdClass $l): array => [
            $l->CashierCode, $l->CashierName, (int) $l->Bags, $money($l->Banked), $money($l->DropSafe),
            $money($l->DerivedShare), $money($l->Share), $money($l->Accountable), $l->Basis, $l->ShortStatus,
        ])->values()->all();
    }

    /**
     * @param  array<int, int>  $bankIds
     * @param  array<int, array<string, mixed>>  $deposits
     * @param  array<int, array<string, mixed>>|null  $allocation
     */
    private function match(array $bankIds, array $deposits, ?array $allocation, ?string $reason = null, string $mode = 'journal'): object
    {
        return $this->write('CashBags', $bankIds, $deposits, $allocation, $reason, $mode);
    }

    /**
     * @param  array<int, int>  $bankIds
     * @param  array<int, array<string, mixed>>  $deposits
     * @param  array<int, array<string, mixed>>|null  $allocation
     */
    private function write(string $area, array $bankIds, array $deposits, ?array $allocation, ?string $reason = null, string $mode = 'journal'): object
    {
        return $this->procedures->write('usp_Recon_ManualMatch', [
            'BranchId' => self::BRANCH,
            'ReconArea' => $area,
            'FromDate' => self::FROM,
            'ToDate' => self::TO.' 23:59:59',
            'BankLineIds' => implode(',', $bankIds),
            'MopsJson' => json_encode($deposits),
            'Reason' => $reason,
            'StampMode' => $mode,
            'UserId' => 1,
            'AllocationJson' => $allocation === null ? null : json_encode($allocation),
        ]);
    }

    private function assertRefused(string $code, callable $attempt, ?string $says = null): void
    {
        try {
            $attempt();
            $this->fail("Expected {$code}; it went through.");
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), $e->getMessage());
            if ($says !== null) {
                $this->assertStringContainsString($says, $e->getMessage());
            }
        }
    }

    /** @return array{id: int, key: string, dt: string, amt: float} */
    private function bag(string $bagNo, float $amount, ?string $cashier, ?float $dropSafe = null, string $date = '2026-09-20'): array
    {
        $id = (int) $this->db()->table('PumpIT.dbo.BRN_DailyBankingCashBags')->insertGetId([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => $date,
            'CashBagNo' => $bagNo, 'CashBagAmount' => $amount, 'ReconBatchNoPumpIT' => 0,
        ]);

        if ($cashier !== null) {
            $this->db()->table('PumpIT.dbo.BRN_DropSafe')->insert([
                'SSBranchId' => self::BRANCH, 'BagNo' => $bagNo, 'CollectionId' => 0,
                'CashierCode' => $cashier, 'Amount' => $dropSafe ?? $amount,
                'DropDate' => $date, 'CreateDateTime' => $date.' 08:00',
            ]);
        }

        return ['id' => $id, 'key' => $bagNo, 'dt' => $date, 'amt' => $amount];
    }

    private function bankLine(float $amount): int
    {
        return (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insertGetId([
            'SSBranchId' => self::BRANCH, 'LineDate' => '2026-09-21', 'Description' => 'TEST-A4 CASH DEPOSIT',
            'Amount' => $amount, 'Type' => 'CashDeposit', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ]);
    }

    private function employee(string $code, string $firstName, string $surname): void
    {
        $this->db()->table('PumpIT.dbo.BRN_Employee')->insert([
            'SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'FirstName' => $firstName,
            'Surname' => $surname, 'IsActive' => 1, 'IsPumpAttendant' => 0, 'PostNo' => 0,
        ]);
    }

    /** @var array<int, User> */
    private array $made = [];

    /** A person who approves staff shorts at every site, by a grant in the cash module. */
    private function approver(): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-a4-approver-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-A4 approver',
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);
        $this->made[] = $user;

        $permission = Permission::query()->acrossBranches()->where('Code', 'cash.staffshort.approve')->first();
        if ($permission === null) {
            $this->markTestSkipped('Run: php artisan seed:master --only=StaffShortPermissionSeeder');
        }

        UserPermission::query()->acrossBranches()->create([
            'BranchId' => (int) $user->BranchId, 'UserId' => (int) $user->Id, 'PermissionId' => (int) $permission->Id,
        ]);
        app(PermissionService::class)->forget($user);

        return $user;
    }

    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    private function cleanUp(): void
    {
        $db = $this->db();

        foreach ($this->made as $user) {
            UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
            User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            app(PermissionService::class)->forget((int) $user->Id);
        }
        $this->made = [];

        foreach (['ReconAllocation', 'ReconStamp', 'ReconMatch', 'ReconBatch', 'ReconRunLine', 'ReconRun'] as $table) {
            $db->table("agora.{$table}")->where('BranchId', self::BRANCH)->delete();
        }

        // The shorts T040 raised from these matches, and Agora's record of them.
        $db->table('agora.StaffShortEvent')->where('BranchId', self::BRANCH)->delete();
        $db->table('agora.StaffShort')->where('BranchId', self::BRANCH)->delete();
        $db->table('PumpIT.dbo.BRN_StaffShorts')->where('SSBranchId', self::BRANCH)
            ->where('EmployeeCode', 'like', 'TEST-A4%')->delete();

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->where('SSBranchId', self::BRANCH)
            ->where('Description', 'like', 'TEST-A4%')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingCashBags')->where('SSBranchId', self::BRANCH)
            ->where('CashBagNo', 'like', 'TEST-A4-%')->delete();
        $db->table('PumpIT.dbo.BRN_DropSafe')->where('SSBranchId', self::BRANCH)
            ->where('BagNo', 'like', 'TEST-A4-%')->delete();
        $db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)
            ->where('EmployeeCode', 'like', 'TEST-A4%')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingABSA')->where('SSBranchId', self::BRANCH)
            ->where('MerchantNumber', 'TEST-A4')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingFNB')->where('SSBranchId', self::BRANCH)
            ->where('MerchantNo', 'TEST-A4')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingDeposita')->where('SSBranchId', self::BRANCH)
            ->where('SlipNo', 'TEST-A4D')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingSmartATM')->where('SSBranchId', self::BRANCH)
            ->where('TerminalId', 'TEST-A4T')->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
