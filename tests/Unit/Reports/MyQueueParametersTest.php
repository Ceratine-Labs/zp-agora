<?php

namespace Tests\Unit\Reports;

use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Modules\Reports\Services\ReportNotFound;
use Modules\Reports\Services\ReportsService;
use Tests\TestCase;

/** The screen and procedure receive the same scope, without opening a database. */
class MyQueueParametersTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_means_south_african_today_and_keeps_the_ninety_day_lookback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 22:30:00', 'UTC'));

        $params = $this->runParameters('my-queue');

        $this->assertSame('2026-11-01', $params['DateTo']);
        $this->assertSame('2026-08-03', $params['DateFrom']);
        $this->assertSame('AgeDays', $params['SortColumn']);
        $this->assertSame(0, $params['SortAsc']);
    }

    public function test_an_explicit_to_date_moves_the_default_lookback_with_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00', 'UTC'));

        $params = $this->runParameters('my-queue', ['to' => '2026-10-01']);

        $this->assertSame('2026-10-01', $params['DateTo']);
        $this->assertSame('2026-07-03', $params['DateFrom']);
    }

    public function test_an_explicit_from_date_is_kept_with_today_or_an_explicit_to_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 22:30:00', 'UTC'));

        foreach ([[null, '2026-11-01'], ['2026-10-01', '2026-10-01']] as [$to, $expectedTo]) {
            $params = $this->runParameters('my-queue', ['from' => '2026-01-01', 'to' => $to]);

            $this->assertSame('2026-01-01', $params['DateFrom']);
            $this->assertSame($expectedTo, $params['DateTo']);
        }
    }

    public function test_blank_or_invalid_queue_inputs_use_the_same_visible_defaults(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00', 'UTC'));

        foreach ([['from' => '', 'to' => '', 'sort' => '', 'dir' => 'asc'], ['from' => 'invalid', 'to' => 'invalid', 'sort' => 'Unknown', 'dir' => 'asc']] as $input) {
            $params = $this->runParameters('my-queue', $input);

            $this->assertSame('2026-08-03', $params['DateFrom']);
            $this->assertSame('2026-11-01', $params['DateTo']);
            $this->assertSame('AgeDays', $params['SortColumn']);
            $this->assertSame(0, $params['SortAsc']);
        }
    }

    public function test_a_valid_explicit_sort_keeps_its_requested_direction(): void
    {
        foreach ([['sort' => 'OpenItems', 'dir' => 'desc', 'expected' => 0], ['sort' => 'AgeDays', 'dir' => 'asc', 'expected' => 1], ['sort' => 'BranchName', 'expected' => 1]] as $input) {
            $params = $this->runParameters('my-queue', $input);

            $this->assertSame($input['sort'], $params['SortColumn']);
            $this->assertSame($input['expected'], $params['SortAsc']);
        }
    }

    public function test_other_reports_keep_their_own_date_window_and_natural_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 22:30:00', 'UTC'));

        foreach ([[], ['from' => '', 'to' => 'invalid', 'sort' => 'AgeDays']] as $input) {
            $params = $this->runParameters('day-close', $input);

            $this->assertSame('2026-10-26', $params['DateFrom']);
            $this->assertSame('2026-11-01', $params['DateTo']);
            $this->assertNull($params['SortColumn']);
            $this->assertSame(1, $params['SortAsc']);
        }

        $params = $this->runParameters('day-close', ['from' => '2026-01-01', 'to' => '2026-02-01', 'sort' => 'BranchName', 'dir' => 'desc']);

        $this->assertSame('2026-01-01', $params['DateFrom']);
        $this->assertSame('2026-02-01', $params['DateTo']);
        $this->assertSame('BranchName', $params['SortColumn']);
        $this->assertSame(0, $params['SortAsc']);
    }

    public function test_effective_scope_is_available_without_running_a_refused_report(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 22:30:00', 'UTC'));
        $procedures = $this->createMock(ProcedureService::class);
        $procedures->expects($this->never())->method('callSets');

        $params = (new ReportsService($procedures))->parametersFor('my-queue', [
            'branch_ids' => [-1], 'search' => '  older  ', 'page' => 2,
        ]);

        $this->assertSame([
            'BranchIds' => '-1',
            'DateFrom' => '2026-08-03',
            'DateTo' => '2026-11-01',
            'Search' => 'older',
            'SortColumn' => 'AgeDays',
            'SortAsc' => 0,
            'Page' => 2,
            'PageSize' => (int) config('reports.page_size'),
        ], $params);
    }

    public function test_unknown_report_parameters_are_refused_without_running_a_procedure(): void
    {
        $procedures = $this->createMock(ProcedureService::class);
        $procedures->expects($this->never())->method('callSets');

        $this->expectException(ReportNotFound::class);

        (new ReportsService($procedures))->parametersFor('unknown-report');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function runParameters(string $key, array $input = []): array
    {
        $calledWith = null;
        $procedures = $this->createMock(ProcedureService::class);
        $procedures->expects($this->once())->method('callSets')
            ->with(config("reports.reports.{$key}.procedure"), $this->anything())
            ->willReturnCallback(function (string $procedure, array $params) use (&$calledWith): array {
                $calledWith = $params;

                return [collect(), collect([(object) ['TotalRows' => 0]])];
            });

        $result = (new ReportsService($procedures))->run($key, $input);
        $this->assertSame($calledWith, $result['params']);

        return $result['params'];
    }
}
