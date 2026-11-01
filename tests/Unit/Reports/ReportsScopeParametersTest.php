<?php

namespace Tests\Unit\Reports;

use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Modules\Reports\Services\ReportsService;
use Tests\TestCase;

/** Defaults are visible request scope; report rules and refusals remain in SQL. */
class ReportsScopeParametersTest extends TestCase
{
    /** From dates for SAST 1 Nov 2026 and an explicit To of 29 Feb 2024. */
    private const WINDOWS = [
        'my-queue' => ['2026-08-03', '2023-12-01'],
        'day-close' => ['2026-10-26', '2024-02-23'],
        'overnight-loads' => ['2026-11-01', '2024-02-29'],
        'unallocated-zreads' => ['2026-08-03', '2023-12-01'],
        'open-cashups' => ['2026-08-03', '2023-12-01'],
        'purchase-approvals' => ['2026-08-03', '2023-12-01'],
        'staff-shorts' => ['2026-08-03', '2023-12-01'],
        'drop-safe' => ['2026-10-02', '2024-01-30'],
        'waste' => ['2026-10-02', '2024-01-30'],
        'pump-readings' => ['2026-10-26', '2024-02-23'],
        'fuel-input' => ['2026-10-26', '2024-02-23'],
        'daily-banking' => ['2026-10-26', '2024-02-23'],
        'stock-count' => ['2026-11-01', '2024-02-29'],
        'utility-meters' => ['2026-11-01', '2024-02-01'],
        'import-pos' => ['2026-11-01', '2024-02-29'],
        'audit-trail' => ['2026-10-25', '2024-02-22'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-31 22:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_report_exposes_its_actual_default_window_at_south_african_midnight(): void
    {
        $service = $this->serviceWithoutExecution();
        $this->assertSame(array_keys(self::WINDOWS), array_keys(config('reports.reports')));

        foreach (self::WINDOWS as $key => [$from]) {
            $params = $service->parametersFor($key);

            $this->assertSame($from, $params['DateFrom'], $key);
            $this->assertSame('2026-11-01', $params['DateTo'], $key);
        }
    }

    public function test_an_explicit_to_date_anchors_each_default_window_including_month_start(): void
    {
        $service = $this->serviceWithoutExecution();

        foreach (self::WINDOWS as $key => [, $from]) {
            $params = $service->parametersFor($key, ['to' => '2024-02-29']);

            $this->assertSame($from, $params['DateFrom'], $key);
            $this->assertSame('2024-02-29', $params['DateTo'], $key);
        }
    }

    public function test_explicit_ranges_are_not_clamped_or_replaced_before_sql_can_refuse_them(): void
    {
        $service = $this->serviceWithoutExecution();

        foreach (array_keys(self::WINDOWS) as $key) {
            foreach ([['2020-01-01', '2026-11-01'], ['2026-11-02', '2026-11-01']] as [$from, $to]) {
                $params = $service->parametersFor($key, ['from' => $from, 'to' => $to]);

                $this->assertSame($from, $params['DateFrom'], $key);
                $this->assertSame($to, $params['DateTo'], $key);
            }

            $params = $service->parametersFor($key, ['from' => '2020-01-01']);
            $this->assertSame('2020-01-01', $params['DateFrom'], $key);
            $this->assertSame('2026-11-01', $params['DateTo'], $key);
        }
    }

    public function test_blank_or_invalid_dates_use_the_same_effective_defaults(): void
    {
        $service = $this->serviceWithoutExecution();

        foreach (self::WINDOWS as $key => [$from]) {
            foreach ([['from' => '', 'to' => ''], ['from' => 'invalid', 'to' => 'invalid']] as $input) {
                $params = $service->parametersFor($key, $input);

                $this->assertSame($from, $params['DateFrom'], $key);
                $this->assertSame('2026-11-01', $params['DateTo'], $key);
            }
        }
    }

    public function test_natural_order_is_preserved_except_for_the_queue_and_valid_sort_choices_win(): void
    {
        $service = $this->serviceWithoutExecution();

        foreach (array_keys(self::WINDOWS) as $key) {
            foreach ([[], ['sort' => 'Unknown']] as $input) {
                $params = $service->parametersFor($key, $input);
                $this->assertSame($key === 'my-queue' ? 'AgeDays' : null, $params['SortColumn'], $key);
                $this->assertSame($key === 'my-queue' ? 0 : 1, $params['SortAsc'], $key);
            }

            $params = $service->parametersFor($key, ['sort' => 'BranchName', 'dir' => 'desc']);
            $this->assertSame('BranchName', $params['SortColumn'], $key);
            $this->assertSame(0, $params['SortAsc'], $key);
        }
    }

    public function test_scope_metadata_matches_the_configured_procedures_date_defaults(): void
    {
        foreach (config('reports.reports') as $key => $report) {
            $scope = $report['scope'];
            $this->assertSame('range', $scope['date_mode'], $key);
            $this->assertNotEmpty($scope['help'], $key);
            $module = $key === 'utility-meters' ? 'Utilities' : 'Reports';
            $source = file_get_contents(base_path('Modules/'.$module.'/Database/Procedures/'.$report['procedure'].'.sql'));
            $sql = preg_replace('/\s+/', ' ', preg_replace('~/\*.*?\*/|--[^\r\n]*~s', '', $source));

            if ($scope['month_start'] ?? false) {
                $this->assertSame('usp_Utilities_GridReadings', $report['procedure']);
                $this->assertStringContainsString('DECLARE @Today date = CONVERT(date, GETDATE());', $sql);
                $this->assertStringContainsString('SET @DateTo = ISNULL(@DateTo, @Today);', $sql);
                $defaultFrom = 'DATEFROMPARTS(YEAR(@DateTo), MONTH(@DateTo), 1)';
            } else {
                $this->assertStringContainsString('SET @DateTo = ISNULL(@DateTo, CONVERT(date, GETDATE()));', $sql, $key);
                $days = $scope['lookback_days'];
                $defaultFrom = $days === 0 ? '@DateTo' : 'DATEADD(day, -'.$days.', @DateTo)';
            }

            $this->assertStringContainsString('SET @DateFrom = ISNULL(@DateFrom, '.$defaultFrom.');', $sql, $key);
            foreach ($report['columns'] as $column) {
                if (isset($column['sort'])) {
                    $this->assertMatchesRegularExpression("/WHEN '".preg_quote($column['sort'], '/')."' THEN/i", $sql, $key);
                }
            }
            if (in_array($key, ['day-close', 'overnight-loads', 'stock-count'], true)) {
                $this->assertStringContainsString('IF DATEDIFF(day, @DateFrom, @DateTo) > 92', $sql, $key);
                $this->assertStringContainsString('AGORA:RANGE_TOO_WIDE:', $sql, $key);
            }
        }
    }

    public function test_each_report_passes_the_visible_scope_and_fail_closed_branch_filter_to_its_procedure(): void
    {
        foreach (array_keys(self::WINDOWS) as $key) {
            $input = ['branch_ids' => [-1], 'search' => '  finding  ', 'page' => 3];
            $expected = $this->serviceWithoutExecution()->parametersFor($key, $input);
            $procedures = $this->createMock(ProcedureService::class);
            $procedures->expects($this->once())->method('callSets')
                ->with(config("reports.reports.{$key}.procedure"), $expected)
                ->willReturn([collect(), collect([(object) ['TotalRows' => 0]])]);

            $result = (new ReportsService($procedures))->run($key, $input);

            $this->assertSame($expected, $result['params'], $key);
            $this->assertSame('-1', $result['params']['BranchIds'], $key);
            $this->assertSame('finding', $result['params']['Search'], $key);
            $this->assertSame(3, $result['params']['Page'], $key);
        }
    }

    private function serviceWithoutExecution(): ReportsService
    {
        $procedures = $this->createMock(ProcedureService::class);
        $procedures->expects($this->never())->method('callSets');

        return new ReportsService($procedures);
    }
}
