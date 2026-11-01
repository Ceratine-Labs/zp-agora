<?php

namespace Tests\Unit\Reports;

use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Modules\Reports\Services\ReportsService;
use Tests\TestCase;

class MyQueueSummariesTest extends TestCase
{
    public function test_full_scope_summaries_are_returned_independently_of_the_current_page(): void
    {
        $summaries = $this->rows([
            (object) ['QueueKey' => 'open-cashups', 'OpenItems' => '820', 'Value' => '1200050.2300', 'SiteQueues' => '25'],
            (object) ['QueueKey' => 'staff-shorts', 'OpenItems' => '9', 'Value' => '125.0000', 'SiteQueues' => '3'],
        ]);

        foreach ([1, 2] as $page) {
            $rows = $this->rows([(object) [
                'BranchId' => $page, 'QueueKey' => 'open-cashups', 'OpenItems' => 2, 'Value' => '10.0000',
            ]]);
            $result = $this->runWith([$rows, $this->rows([(object) ['TotalRows' => 80]]), $summaries], $page);

            $this->assertSame($rows, $result['rows']);
            $this->assertSame(80, $result['total']);
            $this->assertSame($page, $result['params']['Page']);
            $this->assertTrue($result['summariesAvailable']);
            $this->assertSame(['open-cashups', 'staff-shorts'], $result['summaries']->keys()->all());
            $this->assertSame($summaries[0], $result['summaries']->get('open-cashups'));
            $this->assertSame($summaries[1], $result['summaries']->get('staff-shorts'));
            $this->assertSame('820', $result['summaries']->get('open-cashups')->OpenItems);
            $this->assertSame('1200050.2300', $result['summaries']->get('open-cashups')->Value);
        }
    }

    public function test_unknown_values_zero_values_and_large_counts_keep_their_sql_meaning(): void
    {
        $summaries = $this->rows([
            (object) ['QueueKey' => 'day-close', 'OpenItems' => '12', 'Value' => null, 'SiteQueues' => '2'],
            (object) ['QueueKey' => 'staff-shorts', 'OpenItems' => '9223372036854775807', 'Value' => '0.0000', 'SiteQueues' => '4'],
        ]);

        $result = $this->runWith([$this->rows(), $this->rows([(object) ['TotalRows' => 6]]), $summaries]);

        $this->assertTrue($result['summariesAvailable']);
        $this->assertNull($result['summaries']->get('day-close')->Value);
        $this->assertSame('0.0000', $result['summaries']->get('staff-shorts')->Value);
        $this->assertSame('9223372036854775807', $result['summaries']->get('staff-shorts')->OpenItems);
        $this->assertSame('4', $result['summaries']->get('staff-shorts')->SiteQueues);
        $this->assertFalse($result['summaries']->has('drop-safe'), 'Only SQL supplies summary rows; missing types are not invented.');
    }

    public function test_a_present_empty_third_set_confirms_an_empty_scope(): void
    {
        $result = $this->runWith([$this->rows(), $this->rows([(object) ['TotalRows' => 0]]), $this->rows()]);

        $this->assertTrue($result['summariesAvailable']);
        $this->assertTrue($result['summaries']->isEmpty());
        $this->assertSame(0, $result['total']);
    }

    public function test_a_missing_third_set_means_unknown_summaries_and_never_uses_the_page_as_totals(): void
    {
        $rows = $this->rows([(object) ['QueueKey' => 'open-cashups', 'OpenItems' => 10, 'Value' => '50.0000']]);
        $result = $this->runWith([$rows, $this->rows([(object) ['TotalRows' => 80]])]);

        $this->assertFalse($result['summariesAvailable']);
        $this->assertTrue($result['summaries']->isEmpty());
        $this->assertSame($rows, $result['rows']);
        $this->assertSame(80, $result['total']);
    }

    /**
     * @param  array<int, object>  $rows
     * @return Collection<int, object>
     */
    private function rows(array $rows = []): Collection
    {
        return collect($rows);
    }

    /**
     * @param  array<int, Collection<int, object>>  $sets
     * @return array{rows: Collection<int, object>, total: int, params: array<string, mixed>, summaries: Collection<string, object>, summariesAvailable: bool}
     */
    private function runWith(array $sets, int $page = 1): array
    {
        $procedures = $this->createMock(ProcedureService::class);
        $procedures->expects($this->once())->method('callSets')
            ->with('usp_Reports_GridMyQueue', $this->callback(fn (array $params): bool => $params['Page'] === $page))
            ->willReturn($sets);

        return (new ReportsService($procedures))->run('my-queue', ['page' => $page]);
    }
}
