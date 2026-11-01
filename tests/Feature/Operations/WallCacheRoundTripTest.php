<?php

namespace Tests\Feature\Operations;

use App\Support\Badges\Badge;
use App\Support\ProcedureService;
use Illuminate\Cache\FileStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Modules\Operations\Services\OperationsWallService;
use stdClass;
use Tests\TestCase;

class WallCacheRoundTripTest extends TestCase
{
    public function test_restricted_file_cache_restores_scalar_rows_and_rechecks_source_access(): void
    {
        $allowed = config('cache.serializable_classes');
        $this->assertIsArray($allowed);
        $this->assertContains(Badge::class, $allowed);
        $this->assertNotContains(stdClass::class, $allowed);

        Carbon::setTestNow(Carbon::parse('2033-02-01T09:00:00Z'));
        $branch = random_int(900000, 999999);
        $key = 'operations.wall.v2.2033-01-31.'.hash('sha256', (string) $branch);
        try {
            $firstStore = Cache::store('file');
            $this->assertInstanceOf(FileStore::class, $firstStore->getStore());
            $firstStore->forget($key);
            $row = fn (string $source, mixed $value): object => (object) [
                'SourceKey' => $source, 'MetricKey' => 'turnover', 'BranchId' => $branch,
                'BranchName' => 'TEST File Cache', 'Title' => 'Recorded turnover',
                'Value' => $value, 'SecondaryValue' => null, 'Unit' => 'rand',
                'Severity' => 'neutral', 'Detail' => 'No baseline yet',
                'SourceUpdatedAt' => null, 'ItemCode' => null, 'Area' => null, 'SourceCount' => 1,
            ];
            $writer = Mockery::mock(ProcedureService::class);
            $this->assertInstanceOf(ProcedureService::class, $writer);
            $writer->shouldReceive('call')->with('agora.usp_Operations_Snapshot', Mockery::type('array'))
                ->once()->andReturn(collect([$row('shop', null), $row('qsr', '120.4500')]));
            $writer->shouldReceive('call')->with('agora.usp_Operations_TradingChecks', Mockery::type('array'))
                ->once()->andReturn(collect());
            $first = (new OperationsWallService($writer))->snapshot([$branch], ['shop', 'qsr']);
            $this->assertSame(['shop', 'qsr'], array_column($first['screens'], 'id'));
            $this->assertSame('unavailable', $first['screens'][0]['status']);
            $this->assertSame('live', $first['screens'][1]['status']);

            // A new driver and a new service force a real disk deserialize.
            // The configured Badge-only allowlist remains in force throughout.
            Cache::purge('file');
            $secondStore = Cache::store('file');
            $this->assertNotSame($firstStore, $secondStore);
            $this->assertInstanceOf(FileStore::class, $secondStore->getStore());
            $back = $secondStore->get($key);
            $this->assertIsArray($back);
            $this->assertCount(2, $back['rows']);
            foreach ($back['rows'] as $record) {
                $this->assertIsArray($record);
                foreach ($record as $value) {
                    $this->assertTrue($value === null || is_scalar($value));
                }
            }
            $this->assertNull($back['rows'][0]['Value']);
            $this->assertSame('120.4500', $back['rows'][1]['Value']);
            $reader = Mockery::mock(ProcedureService::class);
            $this->assertInstanceOf(ProcedureService::class, $reader);
            $reader->shouldNotReceive('call');
            $second = (new OperationsWallService($reader))->snapshot([$branch], ['shop']);
            $this->assertSame($first['generatedAt'], $second['generatedAt']);
            $this->assertSame(['shop'], array_column($second['screens'], 'id'));
            $this->assertSame($first['screens'][0], $second['screens'][0]);
            $this->assertSame($allowed, config('cache.serializable_classes'));
        } finally {
            Cache::store('file')->forget($key);
            Cache::store('file')->forget($key.'.lock');
            Carbon::setTestNow();
        }
    }
}
