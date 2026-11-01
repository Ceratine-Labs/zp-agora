<?php

namespace Tests\Feature\Operations;

use App\Support\ProcedureService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Modules\Operations\Services\OperationsWallService;
use Tests\TestCase;

class WallServiceTest extends TestCase
{
    public function test_empty_scope_and_no_source_access_never_call_a_procedure(): void
    {
        $proc = Mockery::mock(ProcedureService::class);
        $this->assertInstanceOf(ProcedureService::class, $proc);
        $proc->shouldNotReceive('call');
        $wall = new OperationsWallService($proc);
        $this->assertSame([], $wall->snapshot([], ['fuel'])['screens']);
        $this->assertSame([], $wall->snapshot([3], [])['screens']);
    }

    public function test_cached_data_is_scoped_and_permissions_are_rechecked_when_presenting(): void
    {
        $store = Cache::store('array');
        Cache::shouldReceive('store')->with('file')->andReturn($store);
        $row = fn (string $source, mixed $value): object => (object) [
            'SourceKey' => $source, 'MetricKey' => 'turnover', 'BranchId' => 3,
            'BranchName' => 'TEST-A', 'Title' => '<script>Example</script>', 'Value' => $value,
            'SecondaryValue' => null, 'Unit' => 'rand', 'Severity' => 'neutral',
            'Detail' => 'No baseline yet', 'SourceUpdatedAt' => null, 'ItemCode' => null, 'Area' => null, 'SourceCount' => 0,
        ];
        $proc = Mockery::mock(ProcedureService::class);
        $this->assertInstanceOf(ProcedureService::class, $proc);
        $proc->shouldReceive('call')->with('agora.usp_Operations_Snapshot', Mockery::on(fn (array $args): bool => $args['BranchIds'] === '3' && $args['AllowedBranchIds'] === '3'))
            ->once()->andReturn(collect([$row('shop', null), $row('qsr', 120)]));
        $proc->shouldReceive('call')->with('agora.usp_Operations_TradingChecks', Mockery::type('array'))->once()->andReturn(collect([$row('budget-shop', '0.000')]));
        $wall = new OperationsWallService($proc);
        $first = $wall->snapshot([3], ['shop', 'qsr', 'budget-shop']);
        $this->assertCount(2, $first['screens']);
        $this->assertSame('unavailable', $first['screens'][0]['status']);
        $this->assertSame('—', $first['screens'][0]['items'][0]['value']);
        $this->assertFalse($first['screens'][0]['items'][0]['isZero']);
        $this->assertTrue($first['screens'][0]['items'][1]['isZero']);
        $this->assertFalse($first['screens'][1]['items'][0]['isZero']);
        $this->assertSame('live', $first['screens'][1]['status']);
        $second = $wall->snapshot([3], ['shop']);
        $this->assertSame(['shop'], array_column($second['screens'], 'id'));
        $this->assertSame($first['generatedAt'], $second['generatedAt']);
        $this->assertStringContainsString('branches%5B0%5D=3', $second['screens'][0]['items'][0]['href']);
    }
}
