<?php

namespace Tests\Feature\Recon;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * The split by cashier is carried where a reconciliation is read back
 * (task 01a084fd-096f, 27 Sep 2026): the run page, usp_Recon_DrillMops and
 * the run's CSV extracts. A confirmed split that only exists at match time is
 * not an accountability record.
 *
 * Also the sign of the forced-match message: it states the variance as the
 * split does, bank less bags, with "short" or "over" in words.
 *
 * Writes into the local PumpIT stub only (skipUnlessLocalStub), at branch 994,
 * which no other test uses, and removes the TEST-A5 rows it made and the
 * ledger rows at that branch — the staff shorts a live match raises (T040)
 * included.
 */
class ReconAllocationDisplayTest extends TestCase
{
    private const BRANCH = 994;

    private const FROM = '2026-09-15';

    private const TO = '2026-09-25';

    /** The seven columns every area's drill has always returned. */
    private const DRILL_COLUMNS = ['SourceRef', 'SourceDate', 'Amount', 'Detail', 'SourceId', 'SourceKey', 'ReconBatchNoPumpIT'];

    private const CASH_BAG_COLUMNS = ['CashierCode', 'CashierName', 'DropSafeAmount', 'AllocationBatchNo',
        'AllocationShare', 'AllocationAccountable', 'AllocationShortStatus', 'AllocationStaffShortsNo'];

    private ProcedureService $procedures;

    /** @var array<int, array{id: int, key: string, dt: string, amt: float}> */
    private array $bags = [];

    private int $bank = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
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
     * Four lines — three cashiers and the bag with none — two of them changed
     * by hand, the bank R10.99 short: the page shows each line with the
     * adjusted chip, the basis and the short in words, a totals row, and says
     * the lines tie to the batch.
     */
    public function test_the_run_page_shows_the_split_with_totals_that_tie_to_the_batch(): void
    {
        $status = $this->matchFiveBags();

        $sets = $this->procedures->callSets('usp_Recon_GetAllocation', ['BranchId' => self::BRANCH, 'RunId' => (int) $status->Id]);
        $this->assertCount(4, $sets[0]);
        $batch = $sets[1]->sole();
        $this->assertSame(
            [5, 5, '1100.00', '1100.00', '-10.99', '-10.99', '1089.01', '1089.01', '10.49', 1],
            [(int) $batch->Bags, (int) $batch->MopsRowCount, $this->m($batch->Banked), $this->m($batch->MopsTotal),
                $this->m($batch->Share), $this->m($batch->Variance), $this->m($batch->Accountable), $this->m($batch->BankTotal),
                $this->m($batch->ShortDue), (int) $batch->Ties],
            'bags, banked, share and accountable each tie to the batch; the shorts due are the three cashiers\' negative shares',
        );

        $html = (string) $this->actingAs($this->admin())
            ->get(route('app.recon.run', (int) $status->Id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Who the cash is accounted to', $html);
        $this->assertStringContainsString('agora.usp_Recon_GetAllocation', $html);
        $this->assertStringContainsString('Ties to batch '.$status->BatchNo, $html);
        $this->assertStringContainsString('a variance of -R10.99, bank less bags', $html);
        $this->assertMatchesRegularExpression('#>Bank less deposits</th>#', $html);
        $this->assertStringNotContainsString('>Difference</th>', $html);
        $this->assertStringContainsString('No cashier recorded', $html);
        $this->assertMatchesRegularExpression('#title="Declared R520.00 at the drop safe; banked as R500.00">\s*adjusted\s*</span>#', $html);

        // Each line, in the order the split is drawn: name, bags, banked,
        // share, accountable, basis, short — each raised by the match (T040).
        $this->assertSeeInOrderText($html, [
            'TEST-A5 Largest Ayanda', 'R500.00', '-R4.99', 'R495.01', 'derived', 'short raised #'.$this->raised('TEST-A5C1'),
            'TEST-A5 Middle Bongani', 'R300.00', '-R3.00', 'R297.00', 'derived', 'short raised #'.$this->raised('TEST-A5C2'),
            'TEST-A5 Smallest Cebo', 'R200.00', '-R2.50', 'R197.50', 'changed by hand', 'derived -R2.00', 'short raised #'.$this->raised('TEST-A5C3'),
            'No cashier recorded', 'R100.00', '-R0.50', 'R99.50', 'changed by hand', 'derived -R1.00', 'no cashier — none raised',
            // The totals row.
            '4 lines', '5', 'R1 100.00', '-R10.99', 'R1 089.01', 'R10.49 short',
        ]);

        // T040 raises every short the match is due, in the same press.
        $this->assertStringNotContainsString('short due, not yet raised', $html);
    }

    /** A reversed match keeps its split on the page, every line marked, with the reason. */
    public function test_a_reversed_match_keeps_its_split_on_the_run_page_marked_reversed(): void
    {
        $status = $this->matchFiveBags();

        $this->procedures->write('usp_Recon_Reverse', [
            'BranchId' => self::BRANCH, 'BatchId' => null, 'RunId' => (int) $status->Id,
            'Reason' => 'TEST-A5 wrong bags ticked', 'UserId' => 1,
        ]);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('app.recon.run', (int) $status->Id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('the split is kept, marked', $html);
        $this->assertStringContainsString('TEST-A5 wrong bags ticked', $html);
        $this->assertSame(4, preg_match_all('#<span class="chip neutral tone-neutral">\s*reversed\s*</span>#', $html),
            'Every line carries the reversed chip.');
        // As chips in the split; the extract drawer's copy box says it too.
        $this->assertSame(3, preg_match_all('#<span class="dot" aria-hidden="true"></span>\s*cancelled by the reversal#', $html),
            'The three shorts due are cancelled.');
        $this->assertStringContainsString('no cashier — none raised', $html);
        $this->assertStringContainsString('TEST-A5 Largest Ayanda', $html);
        $this->assertStringContainsString('-R4.99', $html, 'The confirmed figures still read.');
    }

    /**
     * A run with no split — every other area, and an auto run — draws no card.
     */
    public function test_a_run_without_a_split_shows_no_split(): void
    {
        $status = $this->fnbForced(190.00);

        $this->actingAs($this->admin())
            ->get(route('app.recon.run', (int) $status->Id))
            ->assertOk()
            ->assertDontSee('Who the cash is accounted to');
    }

    /**
     * The drill: each bag carries its cashier and its cashier's line of the
     * split, one row per bag — five bags in, five rows out, before the match
     * and after it. The line is the cashier's, so both of Cebo's bags carry
     * -2.50. Reversed, the bags are outstanding again and no batch holds them,
     * so the split line goes and the cashier stays.
     */
    public function test_the_drill_carries_each_bags_cashier_and_split_line_one_row_per_bag(): void
    {
        $this->fiveBags();

        $before = $this->drill(0);
        $this->assertCount(5, $before);
        $this->assertSame(self::DRILL_COLUMNS, array_slice(array_keys((array) $before->first()), 0, 7));
        $this->assertSame(self::CASH_BAG_COLUMNS, array_slice(array_keys((array) $before->first()), 7));
        $this->assertSame([null], $before->pluck('AllocationShortStatus')->unique()->values()->all(), 'Nothing holds the bags yet.');

        $status = $this->match('live');
        $after = $this->drill(1)->keyBy(fn (object $r) => (string) $r->SourceRef);

        $this->assertCount(5, $after, 'One row per bag, exactly as before.');
        $this->assertSame(5, $after->pluck('SourceId')->unique()->count());
        $this->assertSame(
            [
                'TEST-A5-B1' => ['TEST-A5C1', 'TEST-A5 Largest Ayanda', '520.00', (int) $status->BatchNo, '-4.99', '495.01', 'raised'],
                'TEST-A5-B2' => ['TEST-A5C2', 'TEST-A5 Middle Bongani', '300.00', (int) $status->BatchNo, '-3.00', '297.00', 'raised'],
                'TEST-A5-B3' => ['TEST-A5C3', 'TEST-A5 Smallest Cebo', '120.00', (int) $status->BatchNo, '-2.50', '197.50', 'raised'],
                'TEST-A5-B4' => ['TEST-A5C3', 'TEST-A5 Smallest Cebo', '80.00', (int) $status->BatchNo, '-2.50', '197.50', 'raised'],
                'TEST-A5-B5' => [null, null, null, (int) $status->BatchNo, '-0.50', '99.50', 'no_cashier'],
            ],
            $after->sortKeys()->map(fn (object $r) => [
                $r->CashierCode, $r->CashierName, $this->m($r->DropSafeAmount), (int) $r->AllocationBatchNo,
                $this->m($r->AllocationShare), $this->m($r->AllocationAccountable), $r->AllocationShortStatus,
            ])->all(),
        );

        $this->procedures->write('usp_Recon_Reverse', [
            'BranchId' => self::BRANCH, 'BatchId' => null, 'RunId' => (int) $status->Id,
            'Reason' => 'TEST-A5 wrong bags ticked', 'UserId' => 1,
        ]);

        $reversed = $this->drill(0);
        $this->assertCount(5, $reversed, 'Outstanding again, all five.');
        $this->assertSame([null], $reversed->pluck('AllocationBatchNo')->unique()->values()->all(),
            'A reversed batch no longer holds the bags.');
        $this->assertSame('TEST-A5C1', $reversed->firstWhere('SourceRef', 'TEST-A5-B1')->CashierCode);
    }

    /**
     * The row a clerk expands on the run page: a stamped cash-bag proposal is
     * read back out of agora.ReconMatch, and each bag names its cashier and
     * the split line it was accounted on, as the drill does.
     */
    public function test_the_expanded_proposal_names_each_bags_cashier_and_split_line(): void
    {
        $status = $this->matchFiveBags();
        $line = $this->db()->table('agora.ReconRunLine')->where('BranchId', self::BRANCH)->sole();

        $html = (string) $this->actingAs($this->admin())
            ->get(route('app.recon.line', ['run' => (int) $status->Id, 'line' => (int) $line->Id]))
            ->assertOk()
            ->getContent();
        $text = (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));

        $this->assertStringContainsString('agora.ReconMatch', $text);
        $this->assertSame(2, substr_count($text, 'TEST-A5 Smallest Cebo · TEST-A5C3 · batch '.$status->BatchNo
            .' split: their share -R2.50, short raised #'.$this->raised('TEST-A5C3')), "Both of Cebo's bags.");
        $this->assertStringContainsString('no cashier recorded · batch '.$status->BatchNo
            .' split: their share -R0.50, no cashier — none raised', $text);
        $this->assertStringContainsString('area=CashBags', $html, "The proposal's own deposits extract carries the area.");
    }

    /**
     * The shape is decided by the area: ABSA, FNB, CashMachine and SmartATM
     * return the seven columns they always did, column for column, and only
     * CashBags adds its eight. Read off the result set's metadata, so it holds
     * with no rows at all.
     */
    public function test_the_other_areas_drill_returns_the_columns_it_always_did(): void
    {
        foreach (['ABSA', 'FNB', 'CashMachine', 'SmartATM'] as $area) {
            $this->assertSame(self::DRILL_COLUMNS, $this->drillColumns($area), $area);
        }

        $this->assertSame(array_merge(self::DRILL_COLUMNS, self::CASH_BAG_COLUMNS), $this->drillColumns('CashBags'));

        // How usp_Recon_Commit asks: the seven columns it captures, no lookups.
        $this->assertSame(self::DRILL_COLUMNS, $this->drillColumns('CashBags', withCashier: 0));
        $this->assertStringContainsString('@WithCashier = 0',
            (string) file_get_contents(base_path('Modules/Recon/Database/Procedures/usp_Recon_Commit.sql')));
    }

    /**
     * The deposits extract of a stamped cash-bag run carries each bag's
     * cashier and its cashier's line; the proposals extract carries the split
     * in words and the shorts due. The run page's links say the area, and an
     * extract asked for without it keeps the columns it always had.
     */
    public function test_the_extracts_carry_the_cashier_and_the_split(): void
    {
        $status = $this->matchFiveBags();
        $run = (int) $status->Id;
        $admin = $this->admin();

        $page = (string) $this->actingAs($admin)->get(route('app.recon.run', $run))->assertOk()->getContent();
        $this->assertStringContainsString('app.recon.run:mops/extract?run='.$run.'&amp;area=CashBags&amp;format=csv', $page);

        $mops = $this->csv($this->actingAs($admin)
            ->get(route('app.grids.extract', ['grid' => 'app.recon.run:mops', 'run' => $run, 'area' => 'CashBags', 'format' => 'csv'])));

        $this->assertSame(['Reference', 'Proposal outcome', 'State', 'Batch', 'Deposit reference', 'Date', 'Detail', 'Amount',
            'Cashier', 'Cashier code', 'Drop safe', 'Cashier line: batch', 'Cashier line: share of the variance',
            'Cashier line: accountable', 'Cashier line: staff short'], $mops[0]);
        $this->assertCount(6, $mops, 'A header and one row per bag.');

        $byBag = collect(array_slice($mops, 1))->keyBy(fn (array $r) => $r[4]);
        $cebo = 'short raised #'.$this->raised('TEST-A5C3');
        $this->assertSame(['TEST-A5 Smallest Cebo', 'TEST-A5C3', '120', (string) $status->BatchNo, '-2.5', '197.5', $cebo],
            array_slice($byBag['TEST-A5-B3'], 8));
        $this->assertSame(['TEST-A5 Smallest Cebo', 'TEST-A5C3', '80', (string) $status->BatchNo, '-2.5', '197.5', $cebo],
            array_slice($byBag['TEST-A5-B4'], 8), "The line is the cashier's, on each of their bags.");
        $this->assertSame(['', '', '', (string) $status->BatchNo, '-0.5', '99.5', 'no cashier — none raised'],
            array_slice($byBag['TEST-A5-B5'], 8));

        $proposals = $this->csv($this->actingAs($admin)
            ->get(route('app.grids.extract', ['grid' => 'app.recon.run', 'run' => $run, 'area' => 'CashBags', 'format' => 'csv'])));

        $head = $proposals[0];
        $row = array_combine($head, $proposals[1]);
        $this->assertSame(
            'TEST-A5 Largest Ayanda (TEST-A5C1): share -4.99, short raised #'.$this->raised('TEST-A5C1').'; '
            .'TEST-A5 Middle Bongani (TEST-A5C2): share -3.00, short raised #'.$this->raised('TEST-A5C2').'; '
            .'TEST-A5 Smallest Cebo (TEST-A5C3): share -2.50 (changed by hand), short raised #'.$this->raised('TEST-A5C3').'; '
            .'No cashier recorded: share -0.50 (changed by hand), no cashier — none raised',
            $row['Accounted to'],
        );
        $this->assertSame('10.49', $row['Staff short due']);
        // Bank less deposits, as the split states it (Ryan, 27 Sep 2026);
        // ReconRunLine.DiffAmount itself stays deposits less bank.
        $this->assertSame('-10.99', $row['Bank less deposits']);
        $this->assertNotContains('Difference', $head);
        $this->assertSame('10.99', $this->m($this->db()->table('agora.ReconRunLine')->where('RunId', $run)->value('DiffAmount')));

        // Sortable by the turned figure, which is a computed column.
        $this->actingAs($admin)
            ->get(route('app.grids.extract', ['grid' => 'app.recon.run', 'run' => $run, 'sort' => 'BankLessDeposits', 'dir' => 'desc', 'format' => 'csv']))
            ->assertOk();

        // Without the area, the deposits file is the shape it always was.
        $plain = $this->csv($this->actingAs($admin)
            ->get(route('app.grids.extract', ['grid' => 'app.recon.run:mops', 'run' => $run, 'format' => 'csv'])));
        $this->assertNotContains('Cashier', $plain[0]);
        $this->assertCount(6, $plain);
    }

    /**
     * The forced-match message states the variance as the split does — bank
     * less bags, negative when the bank is short — and says which way in words.
     * It said "a variance of 10.99" above a split adding up to -10.99.
     */
    public function test_the_forced_match_message_states_the_variance_bank_less_bags(): void
    {
        $short = $this->matchFiveBags();
        $this->assertStringContainsString(
            'forced with a variance of -R10.99, bank less bags: the bank is R10.99 short of the bags.',
            $short->Message,
        );
        $this->assertSame('-10.99', $this->m($short->Variance), 'The status row says the same as the sentence.');
        $this->assertStringContainsString('Split between 3 cashiers and the bags with no cashier recorded, as changed by hand.',
            $short->Message, 'The bags with no cashier are a line, not a cashier.');
        $this->assertStringEndsWith('raised for approval under Cash.', $short->Message, 'And the shorts the match raised (T040).');

        $this->cleanUp();

        $this->fiveBags(bank: 1110.00);
        $over = $this->match('live', [
            ['code' => 'TEST-A5C1', 'banked' => '500.00', 'share' => '4.55'],
            ['code' => 'TEST-A5C2', 'banked' => '300.00', 'share' => '2.73'],
            ['code' => 'TEST-A5C3', 'banked' => '200.00', 'share' => '1.81'],
            ['code' => null, 'banked' => '100.00', 'share' => '0.91'],
        ]);
        $this->assertStringContainsString(
            'forced with a variance of R10.00, bank less bags: the bank is R10.00 over the bags.',
            $over->Message,
        );

        $fnb = $this->fnbForced(190.00);
        $this->assertStringContainsString(
            'forced with a variance of -R10.00, bank less deposits: the bank is R10.00 short of the deposits.',
            $fnb->Message,
        );
    }

    // ---- helpers -----------------------------------------------------------

    private function matchFiveBags(): object
    {
        $this->fiveBags();

        return $this->match('live');
    }

    /**
     * Three cashiers and a bag with none: R500 (declared R520 at the safe),
     * R300, R120 + R80, and R100 with no drop-safe row. R1 100 of bags. The
     * bank line names every bag, so the 'contains' drill finds all five.
     */
    private function fiveBags(float $bank = 1089.01): void
    {
        $this->employee('TEST-A5C1', 'Ayanda', 'TEST-A5 Largest');
        $this->employee('TEST-A5C2', 'Bongani', 'TEST-A5 Middle');
        $this->employee('TEST-A5C3', 'Cebo', 'TEST-A5 Smallest');

        $this->bags = [
            $this->bag('TEST-A5-B1', 500.00, 'TEST-A5C1', dropSafe: 520.00),
            $this->bag('TEST-A5-B2', 300.00, 'TEST-A5C2'),
            $this->bag('TEST-A5-B3', 120.00, 'TEST-A5C3', date: '2026-09-19'),
            $this->bag('TEST-A5-B4', 80.00, 'TEST-A5C3'),
            $this->bag('TEST-A5-B5', 100.00, null),
        ];

        $this->bank = (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insertGetId([
            'SSBranchId' => self::BRANCH, 'LineDate' => '2026-09-21',
            'Description' => 'TEST-A5 TEST-A5-B1 TEST-A5-B2 TEST-A5-B3 TEST-A5-B4 TEST-A5-B5',
            'Amount' => $bank, 'Type' => 'CashDeposit', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ]);
    }

    /**
     * The split for R10.99 short: the derived shares are -4.99 / -3.00 /
     * -2.00 / -1.00; the last two are moved by hand to -2.50 / -0.50.
     *
     * @param  array<int, array<string, mixed>>|null  $split
     */
    private function match(string $mode, ?array $split = null): object
    {
        return $this->procedures->write('usp_Recon_ManualMatch', [
            'BranchId' => self::BRANCH,
            'ReconArea' => 'CashBags',
            'FromDate' => self::FROM,
            'ToDate' => self::TO.' 23:59:59',
            'BankLineIds' => (string) $this->bank,
            'MopsJson' => json_encode($this->bags),
            'Reason' => 'TEST-A5 bank variance',
            'StampMode' => $mode,
            'UserId' => 1,
            'AllocationJson' => json_encode($split ?? [
                ['code' => 'TEST-A5C1', 'banked' => '500.00', 'share' => '-4.99'],
                ['code' => 'TEST-A5C2', 'banked' => '300.00', 'share' => '-3.00'],
                ['code' => 'TEST-A5C3', 'banked' => '200.00', 'share' => '-2.50'],
                ['code' => null, 'banked' => '100.00', 'share' => '-0.50'],
            ]),
        ]);
    }

    /** An FNB deposit of R200 against a bank line of $bank, forced. */
    private function fnbForced(float $bank): object
    {
        $db = $this->db();
        $db->table('PumpIT.dbo.BRN_DailyBankingFNB')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-09-20', 'BatchNo' => 'TEST-A5F',
            'MerchantNo' => 'TEST-A5', 'Amount' => 200.00, 'ReconBatchNoPumpIT' => 0,
        ]);
        $line = (int) $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insertGetId([
            'SSBranchId' => self::BRANCH, 'LineDate' => '2026-09-20', 'Description' => 'TEST-A5 FNB',
            'Amount' => $bank, 'Type' => 'FNB', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ]);

        return $this->procedures->write('usp_Recon_ManualMatch', [
            'BranchId' => self::BRANCH,
            'ReconArea' => 'FNB',
            'FromDate' => self::FROM,
            'ToDate' => self::TO.' 23:59:59',
            'BankLineIds' => (string) $line,
            'MopsJson' => json_encode([['id' => null, 'key' => 'TEST-A5F', 'dt' => '2026-09-20', 'amt' => 200.00]]),
            'Reason' => 'TEST-A5 FNB short',
            'StampMode' => 'journal',
            'UserId' => 1,
        ]);
    }

    /**
     * The CashBags drill over the bank line that names every bag, as the
     * screen asks it ($includeReconciled = 1) or as the commit does (0).
     *
     * @return Collection<int, object>
     */
    private function drill(int $includeReconciled): Collection
    {
        return $this->procedures->call('usp_Recon_DrillMops', [
            'ReconArea' => 'CashBags',
            'BranchId' => self::BRANCH,
            'FromDate' => self::FROM,
            'ToDate' => self::TO.' 23:59:59',
            'BankLineId' => $this->bank,
            'MatchMode' => 'contains',
            'IncludeReconciled' => $includeReconciled,
        ]);
    }

    /**
     * The drill's column names for one area, off the result set's metadata —
     * there are no rows at this branch for four of the five.
     *
     * @return array<int, string>
     */
    private function drillColumns(string $area, int $withCashier = 1): array
    {
        $statement = $this->db()->getPdo()->prepare(
            'EXEC agora.usp_Recon_DrillMops @ReconArea = ?, @BranchId = ?, @FromDate = ?, @ToDate = ?, @KeyRef = ?, @WithCashier = ?'
        );
        $statement->execute([$area, self::BRANCH, self::FROM, self::TO.' 23:59:59', 'TEST-A5-NONE', $withCashier]);

        $names = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $names[] = (string) $statement->getColumnMeta($i)['name'];
        }
        $statement->closeCursor();

        return $names;
    }

    /** @return array<int, array<int, string>> */
    private function csv(TestResponse $response): array
    {
        $response->assertOk();
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent());
        $rows = array_map(fn (string $line) => str_getcsv($line, ',', '"', '\\'), array_filter(explode("\n", trim((string) $body))));

        return array_values($rows);
    }

    /** @param  array<int, string>  $needles */
    private function assertSeeInOrderText(string $html, array $needles): void
    {
        $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
        $at = 0;

        foreach ($needles as $needle) {
            $found = strpos((string) $text, $needle, $at);
            $this->assertNotFalse($found, "'{$needle}' not found after offset {$at}.");
            $at = (int) $found + strlen($needle);
        }
    }

    /** The staff short a live match raised for one cashier's line (T040). */
    private function raised(string $cashier): int
    {
        return (int) $this->db()->table('agora.ReconAllocation')->where('BranchId', self::BRANCH)
            ->where('CashierCode', $cashier)->whereNull('ReversedAt')->orderByDesc('Id')->value('StaffShortsNo');
    }

    private function m(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
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

    private function employee(string $code, string $firstName, string $surname): void
    {
        $this->db()->table('PumpIT.dbo.BRN_Employee')->insert([
            'SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'FirstName' => $firstName,
            'Surname' => $surname, 'IsActive' => 1, 'IsPumpAttendant' => 0, 'PostNo' => 0,
        ]);
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

        foreach (['ReconAllocation', 'ReconStamp', 'ReconMatch', 'ReconBatch', 'ReconRunLine', 'ReconRun'] as $table) {
            $db->table("agora.{$table}")->where('BranchId', self::BRANCH)->delete();
        }

        // The staff shorts a live match raised (T040), and Agora's record of them.
        $db->table('agora.StaffShortEvent')->where('BranchId', self::BRANCH)->delete();
        $db->table('agora.StaffShort')->where('BranchId', self::BRANCH)->delete();
        $db->table('PumpIT.dbo.BRN_StaffShorts')->where('SSBranchId', self::BRANCH)
            ->where('EmployeeCode', 'like', 'TEST-A5%')->delete();

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->where('SSBranchId', self::BRANCH)
            ->where('Description', 'like', 'TEST-A5%')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingCashBags')->where('SSBranchId', self::BRANCH)
            ->where('CashBagNo', 'like', 'TEST-A5-%')->delete();
        $db->table('PumpIT.dbo.BRN_DropSafe')->where('SSBranchId', self::BRANCH)
            ->where('BagNo', 'like', 'TEST-A5-%')->delete();
        $db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)
            ->where('EmployeeCode', 'like', 'TEST-A5%')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingFNB')->where('SSBranchId', self::BRANCH)
            ->where('MerchantNo', 'TEST-A5')->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
