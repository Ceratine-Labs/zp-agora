<?php

namespace Tests\Feature\StockRecon;

use App\Exceptions\AgoraProcException;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Core\Models\User;
use Modules\StockRecon\Http\Controllers\StockReconGroupController;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Services\StockReconGroupService;
use Modules\StockRecon\Services\StockReconService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GroupPreviewTest extends TestCase
{
    private bool $transaction = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        DB::connection('agora')->beginTransaction();
        $this->transaction = true;
        $user = new User;
        $user->forceFill(['Id' => 919991, 'BranchId' => 2, 'Name' => 'TEST-stock group']);
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        if ($this->transaction) {
            DB::connection('agora')->rollBack();
        }
        parent::tearDown();
    }

    public function test_partial_failure_resume_and_explicit_retry_keep_success_and_failure_history(): void
    {
        $previews = Mockery::mock(StockReconService::class);
        $this->assertInstanceOf(StockReconService::class, $previews);
        $attempts = [];
        $previews->shouldReceive('preview')->andReturnUsing(function ($branch, $area, $from, $to, $options, $ref, $note) use (&$attempts) {
            $attempts[$branch] = ($attempts[$branch] ?? 0) + 1;
            $run = $this->makeRun($branch, $ref, $from, $to);
            if ($branch === 998 && $attempts[$branch] === 1) {
                throw new AgoraProcException('NO_DATA', 'TEST source not ready', 'TEST-preview');
            }

            return $run;
        });
        $service = new StockReconGroupService($previews, new ProcedureService);
        $group = $service->start([999, 998], Carbon::parse('2030-01-01'), Carbon::parse('2030-01-07'), [], 'TEST-group');
        $ok = $service->runBranch($group, 999);
        $this->assertSame($ok->Id, $service->runBranch($group->fresh(), 999)->Id);
        $failed = $service->runBranch($group, 998);
        $this->assertSame('failed', $failed->Status);
        $this->assertSame($failed->Id, $service->runBranch($group, 998)->Id);
        $this->assertSame(2, StockReconRun::query()->acrossBranches()->where('GroupRef', $group->GroupRef)->count(), 'The partial preview was rolled back.');
        $retry = $service->runBranch($group, 998, true);
        $this->assertSame('previewed', $retry->Status);
        $this->assertSame($retry->Id, $service->runBranch($group, 998, true)->Id);
        $this->assertSame([999 => 1, 998 => 2], $attempts);
        $this->assertSame(3, StockReconRun::query()->acrossBranches()->where('GroupRef', $group->GroupRef)->count());
        $this->assertSame('failed', $failed->fresh()->Status, 'Retry retains the failed attempt.');
        $results = $service->results($group);
        $this->assertCount(2, $results);
        $this->assertEquals($retry->Id, $results->firstWhere('BranchId', 998)->RunId);
        $this->assertCount(1, $service->recent([999, 998]));
        $this->assertCount(0, $service->recent([999]));
    }

    public function test_group_selection_is_immutable_and_forbidden_sites_never_reach_preview(): void
    {
        $previews = Mockery::mock(StockReconService::class);
        $this->assertInstanceOf(StockReconService::class, $previews);
        $previews->shouldNotReceive('preview');
        $service = new StockReconGroupService($previews, new ProcedureService);
        $group = $service->start([999], Carbon::parse('2030-01-01'), Carbon::parse('2030-01-07'), [], 'TEST-group');
        $controller = new StockReconGroupController($service);
        foreach ([
            [(new BranchContext)->setAllowed([998]), 999, 403],
            [(new BranchContext)->setAllowed([999, 998]), 998, 404],
            [(new BranchContext)->setWorkspace('branch')->set(999), 999, 403],
        ] as [$context, $branch, $status]) {
            try {
                $controller->runBranch($group->GroupRef, $branch, Request::create('/'), $context);
                $this->fail('Expected access refusal.');
            } catch (HttpException $e) {
                $this->assertSame($status, $e->getStatusCode());
            }
        }
        $other = new User;
        $other->forceFill(['Id' => 919992, 'BranchId' => 2]);
        $this->actingAs($other);
        $this->assertCount(0, $service->results($group));
        $this->assertCount(0, $service->recent([]));
        $this->expectException(ModelNotFoundException::class);
        $controller->show($group->GroupRef, new BranchContext);
    }

    public function test_group_page_renders_persisted_pending_sites_and_review_links(): void
    {
        $previews = Mockery::mock(StockReconService::class);
        $this->assertInstanceOf(StockReconService::class, $previews);
        $service = new StockReconGroupService($previews, new ProcedureService);
        $group = $service->start([999, 998], Carbon::parse('2030-01-01'), Carbon::parse('2030-01-07'), [], 'TEST-group');
        $run = $this->makeRun(999, $group->GroupRef, $group->FromDate, $group->ToDate);
        $results = $service->results($group);
        $this->assertCount(1, $results->where('Status', 'pending'));
        $this->assertEquals($run->Id, $results->firstWhere('BranchId', 999)->RunId);
        $this->assertCount(1, $service->recent([]), 'An unrestricted HO grant follows BranchContext semantics.');
        $this->assertSame([999, 998], $group->fresh()->BranchIdsJson);
        $routes = app('router')->getRoutes();
        foreach (['groups.start', 'groups.branch'] as $route) {
            $middleware = $routes->getByName('app.stockrecon.'.$route)->gatherMiddleware();
            $this->assertContains('can:stockrecon.runs.create', $middleware);
            $this->assertContains('can:stockrecon.runs.view', $middleware);
        }
        $html = view('stockrecon::group', compact('group', 'results'))->render();
        $this->assertStringContainsString('TEST-group', $html);
        $this->assertStringContainsString(route('app.stockrecon.run', $run->Id), $html);
        $this->assertStringContainsString('Waiting to preview.', $html);
    }

    public function test_another_request_holding_the_group_lock_cannot_create_a_duplicate_preview(): void
    {
        $preview = Mockery::mock(StockReconService::class);
        $this->assertInstanceOf(StockReconService::class, $preview);
        $preview->shouldNotReceive('preview');
        $service = new StockReconGroupService($preview, new ProcedureService);
        $group = $service->start([999], Carbon::parse('2030-01-01'), Carbon::parse('2030-01-07'), [], 'TEST-lock');
        config(['database.connections.stock_group_lock_test' => config('database.connections.agora')]);
        $other = DB::connection('stock_group_lock_test');
        $resource = 'StockReconGroup:'.strtoupper($group->GroupRef);
        $other->statement("EXEC sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Session', @LockTimeout=0;", [$resource]);
        try {
            $service->runBranch($group, 999);
            $this->fail('Expected the concurrent preview to be refused.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame(0, StockReconRun::query()->acrossBranches()->where('GroupRef', $group->GroupRef)->count());
        } finally {
            $other->statement("EXEC sys.sp_releaseapplock @Resource=?, @LockOwner='Session';", [$resource]);
            DB::purge('stock_group_lock_test');
        }
    }

    public function test_a_read_only_user_is_not_offered_a_creation_form(): void
    {
        $html = view('stockrecon::group-form', [
            'sites' => collect(), 'recent' => collect(), 'from' => '2026-10-12', 'today' => '2026-10-25',
        ])->render();
        $this->assertStringNotContainsString('Preview selected sites</button>', $html);
        $this->assertStringNotContainsString('name="branches[]"', $html);
    }

    private function makeRun(int $branch, string $ref, Carbon $from, Carbon $to): StockReconRun
    {
        return StockReconRun::create([
            'BranchId' => $branch, 'GroupRef' => $ref,
            'FromDate' => $from->toDateString(), 'ToDate' => $to->toDateString(),
            'Status' => 'previewed', 'ProcedureName' => 'TEST-preview',
            'Note' => 'TEST-group-run', 'CreatedBy' => auth()->id(),
        ]);
    }
}
