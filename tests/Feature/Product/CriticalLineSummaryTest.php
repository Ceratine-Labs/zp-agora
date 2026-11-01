<?php

namespace Tests\Feature\Product;

use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Http\Request;
use Mockery;
use Modules\Product\Grids\CriticalLineGrid;
use Modules\Product\Http\Controllers\CriticalLineController;
use Modules\Product\Services\CriticalLineService;
use Tests\TestCase;

class CriticalLineSummaryTest extends TestCase
{
    public function test_array_and_csv_site_drilldowns_use_the_same_filtered_summary(): void
    {
        $procedures = Mockery::mock(ProcedureService::class);
        $this->assertInstanceOf(ProcedureService::class, $procedures);
        $calls = [];
        $procedures->shouldReceive('callSets')->with('agora.usp_Product_GridCriticalLines', Mockery::type('array'))
            ->times(4)->andReturnUsing(function (string $name, array $parameters) use (&$calls): array {
                $calls[] = $parameters;

                return [collect(), collect([(object) ['TotalRows' => 3]])];
            });
        $this->app->instance(ProcedureService::class, $procedures);
        $registry = app(GridRegistry::class);
        $grids = new GridService($registry, (new BranchContext)->setAllowed([8]));
        $controller = new CriticalLineController($registry, $grids, new CriticalLineService($procedures));
        foreach ([[8], '8'] as $branches) {
            $request = Request::create('/app/master/critical', 'GET', [
                'branches' => $branches, 'page' => 2, 'size' => 25, 'q' => 'Milk',
                'from' => '2026-10-01', 'to' => '2026-10-19',
                'f' => ['PosSystem' => ['in' => ['ARCH']], 'PosCode' => ['q' => '6001253010185', 'eq' => '1']],
            ]);
            $data = $controller->index($request)->getData();
            $this->assertSame(3, $data['outOfStock']);
            $this->assertSame([8], $data['grid']->gridQuery->branchIds);
        }
        foreach ([[$calls[0], $calls[1]], [$calls[2], $calls[3]]] as [$grid, $summary]) {
            foreach (['BranchIds', 'DateFrom', 'DateTo', 'Search'] as $key) {
                $this->assertSame($grid[$key], $summary[$key]);
            }
            $this->assertSame('8', $summary['BranchIds']);
            $this->assertSame(1, $summary['Page']);
            $this->assertSame(1, $summary['PageSize']);
            $filters = json_decode($grid['FiltersJson'], true);
            $filters[] = ['column' => 'Status', 'type' => 'set', 'in' => ['Out of stock']];
            $this->assertSame($filters, json_decode($summary['FiltersJson'], true));
        }
        $this->assertSame($calls[1], $calls[3]);
    }

    public function test_status_filters_intersect_the_summary_without_changing_the_grid_query(): void
    {
        $procedures = Mockery::mock(ProcedureService::class);
        $this->assertInstanceOf(ProcedureService::class, $procedures);
        $procedures->shouldReceive('callSets')->once()->andReturnUsing(function (string $name, array $parameters): array {
            $this->assertSame([['column' => 'Status', 'type' => 'set', 'in' => ['Out of stock']]],
                json_decode($parameters['FiltersJson'], true));

            return [collect(), collect([(object) ['TotalRows' => 2]])];
        });
        $service = new CriticalLineService($procedures);
        $grids = new GridService(app(GridRegistry::class), new BranchContext);
        foreach ([['In stock'], ['Off the list'], ['No POS record'], ['Out of stock', 'Low']] as $statuses) {
            $query = $grids->query(new CriticalLineGrid, Request::create('/', 'GET', ['f' => ['Status' => ['in' => $statuses]]]));
            $this->assertSame(in_array('Out of stock', $statuses, true) ? 2 : 0, $service->outOfStockCount($query));
            $this->assertSame($statuses, $query->filters[0]['in']);
        }
    }
}
