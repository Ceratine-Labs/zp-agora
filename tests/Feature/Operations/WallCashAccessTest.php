<?php

namespace Tests\Feature\Operations;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Modules\Cash\Support\CashupScope;
use Modules\Core\Models\User;
use Modules\Core\Services\MenuAccessService;
use Modules\Operations\Services\OperationsWallService;
use Modules\Operations\Services\WallAccess;
use Tests\TestCase;

class WallCashAccessTest extends TestCase
{
    public function test_cash_source_requires_the_working_screen_permission_and_menu_access(): void
    {
        foreach ([[true, true, true], [false, true, false], [true, false, false]] as [$permission, $menu, $expected]) {
            $user = Mockery::mock(User::class);
            $this->assertInstanceOf(User::class, $user);
            $user->shouldReceive('can')->with('cash.cashup.view')->once()->andReturn($permission);
            $user->shouldReceive('can')->with('master.stock.view')->once()->andReturnFalse();
            $menus = Mockery::mock(MenuAccessService::class);
            $this->assertInstanceOf(MenuAccessService::class, $menus);
            if ($permission) {
                $menus->shouldReceive('canReachRoute')->with($user, 'app.cash.banking')->once()->andReturn($menu);
            } else {
                $menus->shouldNotReceive('canReachRoute');
            }
            $access = Mockery::mock(WallAccess::class, [$menus])->makePartial();
            $this->assertInstanceOf(WallAccess::class, $access);
            $access->shouldReceive('report')->andReturnFalse();
            $access->shouldReceive('stock')->andReturnFalse();
            $this->assertSame($expected ? ['cash'] : [], $access->sources($user));
        }
    }

    public function test_cash_links_open_each_selected_site_and_the_south_african_trading_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19T23:30:00Z'));
        try {
            $store = Cache::store('array');
            Cache::shouldReceive('store')->with('file')->andReturn($store);
            $rows = collect([3, 5])->map(fn (int $branch): object => (object) [
                'SourceKey' => 'cash', 'MetricKey' => 'cashups-open', 'BranchId' => $branch,
                'BranchName' => "TEST {$branch}", 'Title' => 'Cashups awaiting close',
                'Value' => 1, 'SecondaryValue' => 3, 'Unit' => 'count', 'Severity' => 'warn',
                'Detail' => 'Captured cashups', 'SourceUpdatedAt' => null,
                'ItemCode' => null, 'Area' => null, 'SourceCount' => 3,
            ]);
            $proc = Mockery::mock(ProcedureService::class);
            $this->assertInstanceOf(ProcedureService::class, $proc);
            $proc->shouldReceive('call')->with('agora.usp_Operations_Snapshot', Mockery::on(
                fn (array $args): bool => $args['BranchIds'] === '3,5' && $args['TradeDate'] === '2026-10-19'
            ))->once()->andReturn($rows);
            $proc->shouldReceive('call')->with('agora.usp_Operations_TradingChecks', Mockery::type('array'))
                ->once()->andReturn(collect());
            $result = (new OperationsWallService($proc))->snapshot([3, 5], ['cash']);
            $this->assertSame('2026-10-19', $result['businessDate']);
            $this->assertSame('cashups', $result['screens'][0]['id']);
            foreach ($result['screens'][0]['items'] as $index => $item) {
                $branch = [3, 5][$index];
                $this->assertSame(route('app.cash.banking', ['branch_id' => $branch, 'date' => '2026-10-19']), $item['href']);
                $request = Request::create($item['href']);
                $ho = CashupScope::fromRequest($request, (new BranchContext)->setAllowed([3, 5]));
                $this->assertSame($branch, $ho->branchId);
                $this->assertSame('2026-10-19', $ho->date);
                $this->assertTrue($ho->isChosen());
                $this->assertFalse($ho->isOne(), 'A site summary opens all cashups for that day.');
                $pinned = CashupScope::fromRequest($request, (new BranchContext)->setWorkspace('branch')->set($branch)->setAllowed([$branch]));
                $this->assertSame($branch, $pinned->branchId);
                $this->assertSame('2026-10-19', $pinned->date);
            }
        } finally {
            Carbon::setTestNow();
        }
    }
}
