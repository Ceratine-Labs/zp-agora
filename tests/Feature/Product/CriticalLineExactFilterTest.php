<?php

namespace Tests\Feature\Product;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Grids\CriticalLineGrid;
use Tests\TestCase;

/**
 * Local-only POS fixtures, rolled back after each test. The wall's exact
 * drill-down must exclude substring matches and the same code at other keys.
 */
class CriticalLineExactFilterTest extends TestCase
{
    private const BRANCH = 981;

    private const OTHER_BRANCH = 982;

    private const CODE = '6001253010185';

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub('These exact-filter fixtures write POS and critical-list rows.');
        $this->db()->beginTransaction();
        $this->transactionStarted = true;

        foreach ([self::BRANCH, self::OTHER_BRANCH] as $branch) {
            $this->db()->table('agora.Branch')->insert([
                'BranchId' => $branch,
                'Name' => 'TEST-CRITICAL-EXACT-'.$branch,
                'IsTrading' => 1,
                'IsActive' => 1,
            ]);
        }

        $this->critical(self::BRANCH, 'ARCH', self::CODE);
        $this->critical(self::BRANCH, 'ARCH', 'X'.self::CODE);
        $this->critical(self::BRANCH, 'ARCH', self::CODE.'X');
        $this->critical(self::BRANCH, 'WINBRANCH', self::CODE);
        $this->critical(self::OTHER_BRANCH, 'ARCH', self::CODE);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            $this->db()->rollBack();
        }

        parent::tearDown();
    }

    public function test_exact_url_filter_matches_only_the_code_at_the_selected_site_and_pos_system(): void
    {
        $filters = $this->filters(['q' => self::CODE, 'eq' => '1']);
        $this->assertSame('eq', $filters[0]['op']);

        $sets = $this->grid($filters);
        $this->assertCount(1, $sets[0]);
        $this->assertSame(1, (int) $sets[1]->first()->TotalRows);
        $row = $sets[0]->first();
        $this->assertSame(self::BRANCH, (int) $row->BranchId);
        $this->assertSame('ARCH', $row->PosSystem);
        $this->assertSame(self::CODE, $row->PosCode);

        $nextPage = $this->grid($filters, 2);
        $this->assertCount(0, $nextPage[0]);
        $this->assertSame(1, (int) $nextPage[1]->first()->TotalRows);
    }

    public function test_default_contains_filter_retains_prefix_and_suffix_matches_and_page_totals(): void
    {
        $filters = $this->filters(['q' => self::CODE]);
        $this->assertSame('contains', $filters[0]['op']);
        $codes = [];

        foreach ([1, 2, 3] as $page) {
            $sets = $this->grid($filters, $page);
            $this->assertCount(1, $sets[0]);
            $this->assertSame(3, (int) $sets[1]->first()->TotalRows);
            $row = $sets[0]->first();
            $this->assertSame(self::BRANCH, (int) $row->BranchId);
            $this->assertSame('ARCH', $row->PosSystem);
            $codes[] = $row->PosCode;
        }

        $this->assertEqualsCanonicalizing([self::CODE, 'X'.self::CODE, self::CODE.'X'], $codes);
        $pastEnd = $this->grid($filters, 4);
        $this->assertCount(0, $pastEnd[0]);
        $this->assertSame(3, (int) $pastEnd[1]->first()->TotalRows);

        unset($filters[0]['op']);
        $withoutOperator = $this->grid($filters);
        $this->assertSame(3, (int) $withoutOperator[1]->first()->TotalRows);
    }

    public function test_missing_exact_code_has_no_rows_and_a_zero_total(): void
    {
        $sets = $this->grid($this->filters(['q' => 'NO-SUCH-CODE', 'eq' => '1']));

        $this->assertCount(0, $sets[0]);
        $this->assertSame(0, (int) $sets[1]->first()->TotalRows);
    }

    /**
     * @param  array<string, string>  $code
     * @return list<array<string, mixed>>
     */
    private function filters(array $code): array
    {
        $definitions = (new CriticalLineGrid)->filters();

        return [
            $definitions['PosCode']->normalise($code),
            $definitions['PosSystem']->normalise(['in' => ['ARCH']]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     * @return array<int, Collection<int, object>>
     */
    private function grid(array $filters, int $page = 1): array
    {
        return app(ProcedureService::class)->callSets('usp_Product_GridCriticalLines', [
            'BranchIds' => (string) self::BRANCH,
            'FiltersJson' => json_encode($filters, JSON_THROW_ON_ERROR),
            'SortColumn' => 'PosCode',
            'SortAsc' => 1,
            'Page' => $page,
            'PageSize' => 1,
        ]);
    }

    private function critical(int $branch, string $system, string $code): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockMasterCritical')->insert([
            'SSBranchId' => $branch, 'Code' => $code, 'Location' => $system,
            'Desc' => 'TEST-EXACT-'.$code, 'CAT' => 'TEST', 'IsActive' => 1,
        ]);
        $this->db()->table('PumpIT.dbo.DBF_STDB')->insert([
            'SSBranchId' => $branch, 'CODE' => $code, 'LOCATION' => $system,
            'DESC' => 'TEST-EXACT-'.$code, 'CAT' => 'TEST',
            'QTY' => 0, 'PACKSIZE' => 1, 'STDCOST' => 10, 'STDSELL' => 15,
        ]);
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
