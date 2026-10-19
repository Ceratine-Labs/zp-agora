<?php

namespace Tests\Feature\StockRecon;

use App\Grid\GridResult;
use App\Grid\GridService;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\StockRecon\Grids\StockReconVarianceGrid;
use Tests\TestCase;

/**
 * Original vs amended variance (ZRP, 19 Oct 2026): every count line's
 * variance as counted beside its variance after balancing, over any period,
 * with PumpIT's own Stock Recon Balancing arithmetic.
 *
 * The fixture is four lines in January 2030, at a site no real line uses, so
 * nothing else falls in the range and every figure below can be worked by
 * hand:
 *
 *   item   shift  original o/i/c/pos  amended o/i/c/pos  waste  original var  amended var
 *   TVAR1  1 Jan 1  10/0/8/0            10/0/10/0          —      -2 (short)    0
 *   TVAR1  1 Jan 3   8/0/10/0           10/0/10/0          —      +2 (over)     0
 *   TVAR2  2 Jan 1   5/0/3/0             5/0/3/0           1      -2            -1 (waste explains 1)
 *   TVAR2  2 Jan 3   3/0/4/0             3/0/4/0           2      +1 (in allow) 0  (waste never makes an over)
 *
 * TVAR1 sells at R10 with no allowance; TVAR2 at R4 with an allowance of 5.
 * Checked against the live screen on 19 Oct 2026 as well: Ngwenya, Bakery,
 * 5–7 Oct gave -13 / -R211.98 / -R940.73 / R728.75 as counted and -5 / -R76.60
 * / -R76.60 / R0.00 amended — the figures in ZRP's own screenshot of PumpIT.
 */
class VarianceTest extends TestCase
{
    private const BRANCH = 998;

    private const AREA = 1;

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->cleanUp();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();

            foreach ($this->made as $user) {
                $this->db()->table(config('agora.schema').'.UserPermission')->where('UserId', $user->Id)->delete();
                $this->db()->table(config('agora.schema').'.UserGridColumn')->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }

        parent::tearDown();
    }

    public function test_the_totals_are_pumpits_both_ways_and_the_difference_is_what_balancing_moved(): void
    {
        $grid = $this->grid();

        $this->assertNull($grid->refusal);
        $this->assertSame(4, $grid->total);

        $t = $grid->totals;
        $this->assertTrue($grid->totalsAreGrand, 'Every line that matched, not the page.');
        $this->assertEqualsWithDelta(4, $t['Lines'], 0.001);
        $this->assertEqualsWithDelta(2, $t['LinesAmended'], 0.001, 'Waste is not an amendment: only TVAR1 was balanced.');

        $this->assertEqualsWithDelta(-1, $t['QtyVarOriginal'], 0.0005);
        $this->assertEqualsWithDelta(-4, $t['ValueVarOriginal'], 0.005);
        $this->assertEqualsWithDelta(-28, $t['ValueShortOriginal'], 0.005, 'The shorts as counted: -20 and -8.');
        $this->assertEqualsWithDelta(24, $t['ValueOverOriginal'], 0.005, 'The overs as counted: 20 and 4.');
        $this->assertEqualsWithDelta(0, $t['QtyComputerOriginal'], 0.0005);

        $this->assertEqualsWithDelta(-1, $t['QtyVar'], 0.0005);
        $this->assertEqualsWithDelta(-4, $t['ValueVar'], 0.005);
        $this->assertEqualsWithDelta(-4, $t['ValueShort'], 0.005, 'All that is left short: TVAR2 after its waste.');
        $this->assertEqualsWithDelta(0, $t['ValueOver'], 0.005);
        $this->assertEqualsWithDelta(0, $t['ValueVarMoved'], 0.005,
            'Balancing moved a short onto an over and made nothing smaller; waste took R8 off the shorts and R4 off the overs.');
    }

    public function test_each_line_carries_both_sides_and_pumpits_colours(): void
    {
        $rows = $this->grid()->rows->keyBy(fn ($r) => trim((string) $r->StockItemNo).'/'.substr((string) $r->TransactionDate, 0, 10).'/'.$r->ShiftNo);

        $short = $rows['TVAR1/2030-01-01/1'];
        $this->assertEqualsWithDelta(-2, (float) $short->QtyVarOriginal, 0.0005);
        $this->assertEqualsWithDelta(-20, (float) $short->ValueVarOriginal, 0.005);
        $this->assertSame('crit', $short->OriginalTone, 'Short as counted: red.');
        $this->assertEqualsWithDelta(0, (float) $short->QtyVar, 0.0005);
        $this->assertNull($short->AmendedTone, 'Balanced: no colour.');
        $this->assertEqualsWithDelta(20, (float) $short->ValueVarMoved, 0.005);
        $this->assertSame('Amended', $short->Balancing);

        $over = $rows['TVAR1/2030-01-01/3'];
        $this->assertSame('warn', $over->OriginalTone, 'Over with no allowance set: yellow, as PumpIT shows it.');

        $waste = $rows['TVAR2/2030-01-02/1'];
        $this->assertEqualsWithDelta(-2, (float) $waste->QtyVarB4Waste, 0.0005);
        $this->assertEqualsWithDelta(1, (float) $waste->QtyGoodWaste, 0.0005);
        $this->assertEqualsWithDelta(-1, (float) $waste->QtyVar, 0.0005, 'Good waste explains part of a short.');
        $this->assertSame('crit', $waste->AmendedTone);
        $this->assertSame('As counted', $waste->Balancing, 'Waste moves the variance, not a count.');

        $within = $rows['TVAR2/2030-01-02/3'];
        $this->assertSame('good', $within->OriginalTone, 'Over by no more than the allowance.');
        $this->assertEqualsWithDelta(0, (float) $within->QtyVar, 0.0005, 'Waste never makes an over: the line goes to nil.');
    }

    public function test_a_filter_narrows_the_lines_and_the_totals_together(): void
    {
        $grid = $this->grid(['f' => ['Balancing' => ['in' => ['Amended']]]]);

        $this->assertSame(2, $grid->total);
        $this->assertEqualsWithDelta(2, $grid->totals['Lines'], 0.001);
        $this->assertEqualsWithDelta(0, $grid->totals['ValueVarOriginal'], 0.005, 'TVAR1 only: -20 and +20.');
        $this->assertTrue($grid->rows->every(fn ($r) => $r->Balancing === 'Amended'));
    }

    public function test_the_period_is_bounded_by_lines_not_days(): void
    {
        $wide = $this->grid(['from' => '2023-01-01', 'to' => '2030-12-31']);
        $this->assertNotNull($wide->refusal);
        $this->assertStringContainsString('count lines in that period', (string) $wide->refusal);

        $backwards = $this->grid(['from' => '2030-01-02', 'to' => '2030-01-01']);
        $this->assertSame('The From date is after the To date.', $backwards->refusal);
    }

    public function test_the_screen_renders_both_blocks_and_the_strip(): void
    {
        $this->actingAs($this->person(['stockrecon.variance.view']));

        $page = $this->get(route('app.stockrecon.variance', ['from' => '2030-01-01', 'to' => '2030-01-02']))->assertOk();

        $page->assertSeeInOrder(['Value variance', 'Value short', 'Value over', 'Quantity variance', 'POS quantity', 'Lines']);
        $page->assertSee('As counted -R28.00', false);
        $page->assertSeeInOrder(['>Balancing<', '>Moved by balancing<', '>Original open<', '>Original variance<',
            '>Original value<', '>Amended open<', '>Amended variance<', '>Amended value<'], false);
        $page->assertSee('usp_StockRecon_GridVariance');
        $page->assertSee('tone-value tone-crit', false);
    }

    public function test_it_needs_its_own_grant(): void
    {
        $this->actingAs($this->person([]))
            ->get(route('app.stockrecon.variance', ['from' => '2030-01-01', 'to' => '2030-01-02']))
            ->assertForbidden();
    }

    /** @param array<string, mixed> $query */
    private function grid(array $query = []): GridResult
    {
        $request = Request::create('/', 'GET', $query + ['from' => '2030-01-01', 'to' => '2030-01-02']);

        return app(GridService::class)->build(app(StockReconVarianceGrid::class), $request);
    }

    /** @param array<int, string> $codes */
    private function person(array $codes): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-variance-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-variance',
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        $schema = config('agora.schema');

        foreach ($codes as $code) {
            $permission = $this->db()->table("{$schema}.Permission")->where('Code', $code)->value('Id');
            $this->db()->table("{$schema}.UserPermission")->insert([
                'BranchId' => (int) config('agora.group_branch_id', 2),
                'UserId' => $user->Id, 'PermissionId' => $permission, 'CreatedAt' => now(),
            ]);
        }

        $this->made[] = $user;

        return $user;
    }

    private function seedFixture(): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA,
            'AreaDescription' => 'TEST-Bakery', 'AreaGroup' => 'Food Items',
            'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 1,
            'ShowReport' => 1, 'IsCaptureWaste' => 1,
        ]);

        foreach ([['TVAR1', 'TEST-Chilli russian', 10, 0], ['TVAR2', 'TEST-Sliced cheese', 4, 5]] as [$no, $description, $price, $allowance]) {
            $db->table('PumpIT.dbo.STK_StockMaster')->insert([
                'SSBranchId' => self::BRANCH, 'StockItemNo' => $no,
                'StockItemDescription' => $description, 'AreaNo' => self::AREA,
                'Location' => 'TEST', 'UOMCode' => 'EA',
                'SellingPrice' => $price, 'POSCode' => 'TEST'.$no,
                'QtyVarAllowance' => $allowance, 'isMonitoredItem' => 0,
            ]);
        }

        // item, date, shift, price, original [o, i, c, pos], amended [o, i, c, pos]
        $lines = [
            ['TVAR1', '2030-01-01', 1, 10, [10, 0, 8, 0], [10, 0, 10, 0]],
            ['TVAR1', '2030-01-01', 3, 10, [8, 0, 10, 0], [10, 0, 10, 0]],
            ['TVAR2', '2030-01-02', 1, 4, [5, 0, 3, 0], [5, 0, 3, 0]],
            ['TVAR2', '2030-01-02', 3, 4, [3, 0, 4, 0], [3, 0, 4, 0]],
        ];

        foreach ($lines as [$item, $date, $shift, $price, $original, $amended]) {
            $db->table('PumpIT.dbo.STK_StockReconLine')->insert([
                'SSBranchId' => self::BRANCH, 'TransactionDate' => $date, 'ShiftNo' => $shift,
                'AreaNo' => self::AREA, 'StockItemNo' => $item, 'SellPrice' => $price,
                'QtyOpen_Original' => $original[0], 'QtyIssued_Original' => $original[1],
                'QtyClose_Original' => $original[2], 'QtyComputer_Original' => $original[3],
                'QtyOpen' => $amended[0], 'QtyIssued' => $amended[1],
                'QtyClose' => $amended[2], 'QtyComputer' => $amended[3],
            ]);
        }

        foreach ([[1, 1], [3, 2]] as [$shift, $waste]) {
            $db->table('PumpIT.dbo.STK_StockWasteLine')->insert([
                'SSBranchId' => self::BRANCH, 'TransactionDate' => '2030-01-02', 'ShiftNo' => $shift,
                'AreaNo' => self::AREA, 'StockItemNo' => 'TVAR2', 'QtyGoodWaste' => $waste, 'QtyBadWaste' => 0,
            ]);
        }
    }

    private function cleanUp(): void
    {
        foreach (['STK_StockReconLine', 'STK_StockWasteLine', 'STK_StockMaster', 'STK_Area'] as $table) {
            $this->db()->table("PumpIT.dbo.{$table}")->where('SSBranchId', self::BRANCH)->delete();
        }
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
