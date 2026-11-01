<?php

namespace Tests\Feature\StockRecon;

use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Mockery;
use Modules\Core\View\Composers\ShellComposer;
use Modules\StockRecon\Http\Controllers\StockReconHelperController;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Services\StockReconHelper;
use Modules\StockRecon\Services\StockReconService;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** In-memory snapshots and strict service mocks: no database connection or writes. */
class HelperAccessTest extends TestCase
{
    public function test_the_route_is_read_only_and_requires_run_view_access(): void
    {
        $route = Route::getRoutes()->getByName('app.stockrecon.helper');
        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('can:stockrecon.runs.view', $route->gatherMiddleware());
    }

    public function test_a_revoked_branch_grant_is_refused_before_any_snapshot_or_procedure_read(): void
    {
        $controller = Mockery::mock(StockReconHelperController::class, [new StockReconHelper, Mockery::mock(StockReconService::class)])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $controller->shouldNotReceive('snapshot');
        $this->assertNotFound(fn () => $controller->show($this->snapshotRun(), Request::create('/'), (new BranchContext)->setAllowed([18])));
    }

    public function test_a_pinned_workspace_cannot_use_a_granted_other_site_or_an_unset_pin(): void
    {
        foreach ([18, null] as $pin) {
            $controller = Mockery::mock(StockReconHelperController::class, [new StockReconHelper, Mockery::mock(StockReconService::class)])
                ->makePartial()->shouldAllowMockingProtectedMethods();
            $controller->shouldNotReceive('snapshot');
            $context = (new BranchContext)->setAllowed([17, 18])->setWorkspace('branch')->set($pin);
            $this->assertNotFound(fn () => $controller->show($this->snapshotRun(), Request::create('/'), $context));
        }
    }

    public function test_another_runs_line_is_refused_without_calling_chain(): void
    {
        $run = $this->snapshotRun();
        $recon = Mockery::mock(StockReconService::class);
        $recon->shouldNotReceive('chain');
        $controller = $this->controller($run, $recon);
        $this->assertNotFound(fn () => $controller->show($run, Request::create('/?line=999'), $this->context()));
    }

    public function test_invalid_question_and_array_line_are_refused_without_procedure_calls(): void
    {
        foreach ([['topic' => 'commit'], ['line' => ['10']]] as $query) {
            $run = $this->snapshotRun();
            $controller = $this->controller($run, Mockery::mock(StockReconService::class));
            try {
                $controller->show($run, Request::create('/', 'GET', $query), $this->context());
                $this->fail('Invalid helper input was accepted.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_a_selected_chain_uses_only_the_read_service_and_preserves_the_snapshot(): void
    {
        $run = $this->snapshotRun();
        $line = $run->lines->first();
        $original = $run->toArray();
        $recon = Mockery::mock(StockReconService::class);
        $recon->shouldReceive('chain')->once()->with($run, $line)->andReturn([
            'item' => (object) ['ChainBlocked' => true], 'shifts' => collect([(object) ['Id' => 10]]),
        ]);
        // Strict mocks refuse any unexpected select, preview, commit, reset,
        // reverse or duplicate-release method; none are stubbed as permitted.
        $view = $this->controller($run, $recon)->show($run, Request::create('/?line=10&topic=held'), $this->context());
        $this->assertSame('stockrecon::helper', $view->name());
        $this->assertSame($line, $view->getData()['line']);
        $this->assertSame('held', $view->getData()['topic']);
        $this->assertCount(1, $view->getData()['shifts']);
        $this->assertSame($original, $run->toArray());
    }

    public function test_overview_needs_no_chain_procedure_and_rechecks_the_reread_branch(): void
    {
        $run = $this->snapshotRun();
        $view = $this->controller($run, Mockery::mock(StockReconService::class))->show($run, Request::create('/'), $this->context());
        $this->assertNull($view->getData()['line']);
        $this->assertCount(1, $view->getData()['choices']);

        $changed = $this->snapshotRun()->forceFill(['BranchId' => 18]);
        $this->assertNotFound(fn () => $this->controller($changed, Mockery::mock(StockReconService::class))
            ->show($run, Request::create('/'), $this->context()));
    }

    public function test_chain_choices_are_bounded_and_keep_the_total_visible(): void
    {
        $run = $this->snapshotRun();
        $template = $run->lines->first();
        $run->setRelation('lines', new Collection(collect(range(1, 65))->map(
            fn ($id) => (new StockReconRunLine)->forceFill(['Id' => $id, 'StockItemNo' => 'ITEM-'.$id] + $template->getAttributes()),
        )));
        $view = $this->controller($run, Mockery::mock(StockReconService::class))->show($run, Request::create('/?page=3'), $this->context());
        $this->assertSame(65, $view->getData()['choiceCount']);
        $this->assertSame(3, $view->getData()['choicePages']);
        $this->assertSame([61, 62, 63, 64, 65], $view->getData()['choices']->pluck('Id')->all());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_the_full_helper_renders_saved_bases_and_escaped_evidence_without_write_controls(bool $reset): void
    {
        $this->withoutVite();
        $shell = $this->createMock(ShellComposer::class);
        $shell->expects($this->once())->method('compose')->willReturnCallback(function (View $view): void {
            $view->with([
                'shellSections' => collect(), 'shellWorkspace' => 'ho', 'shellWorkspaces' => [],
                'shellBranchId' => null, 'shellBranches' => collect(), 'shellBranchesGranted' => false,
            ]);
        });
        $this->app->instance(ShellComposer::class, $shell);
        $run = $this->snapshotRun()->forceFill([
            'FromDate' => '2026-10-19', 'ToDate' => '2026-10-25', 'CreatedAt' => '2026-10-26 08:00:00',
            'StampMode' => 'live', 'HeldBackRows' => 0,
            'Status' => $reset ? 'committed' : 'previewed', 'CommitKind' => $reset ? 'reset' : null,
        ]);
        $line = $run->lines->first()->forceFill([
            'QtyOpenOrig' => '10', 'QtyCloseOrig' => '5', 'ItemDescription' => '<script>bad()</script>',
            'CommitState' => $reset ? 'committed' : 'pending',
        ]);
        $recon = Mockery::mock(StockReconService::class);
        $recon->shouldReceive('chain')->once()->with($run, $line)->andReturn([
            'item' => (object) ['ChainBlocked' => true],
            'shifts' => collect([(object) [
                'Id' => 10, 'TransactionDate' => '2026-10-19', 'ShiftNo' => 1,
                'QtyOpenNow' => '12', 'QtyCloseNow' => '7', 'QtyOpenNew' => '14', 'QtyCloseNew' => '5',
                'QtyIssued' => '0', 'QtyIssuedAmended' => '0', 'QtyPOS' => '3', 'QtyVar' => '-2', 'QtyVarNew' => '-2',
                'Outcome' => '<script>source()</script>', 'ExceptionCode' => 'F1', 'AuditCode' => null,
            ]]),
        ]);
        $html = $this->controller($run, $recon)->show($run, Request::create('/?line=10'), $this->context())->render();
        foreach (['Saved evidence', 'Original open', 'Live open at preview', 'Proposed open', $reset ? 'Reset commit state:' : 'Commit state:', 'Read the related lesson'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        if ($reset) {
            $this->assertStringContainsString('these proposals were not the reset writes', $html);
            $this->assertStringContainsString('Reset commit state: committed', $html);
            $this->assertStringContainsString('Find or prepare a fresh preview', $html);
            $this->assertStringContainsString('/app/reports/audit-trail', $html);
            $this->assertStringContainsString('>14</td>', $html, 'The old proposed value must stay visible with the reset caveat.');
        }
        $this->assertStringContainsString('&lt;script&gt;source()&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>source()', $html);
        $this->assertStringNotContainsString('<script>bad()', $html);
        $this->assertStringNotContainsString('/runs/7/commit', $html);
        $this->assertStringNotContainsString('/runs/7/reset', $html);
        $this->assertStringNotContainsString('/runs/7/reverse', $html);
        $this->assertStringNotContainsString('@foreach', $html);
    }

    private function controller(StockReconRun $snapshot, StockReconService $recon): StockReconHelperController
    {
        $controller = Mockery::mock(StockReconHelperController::class, [new StockReconHelper, $recon])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('snapshot')->once()->andReturn($snapshot);

        return $controller;
    }

    private function snapshotRun(): StockReconRun
    {
        $run = (new StockReconRun)->forceFill(['Id' => 7, 'BranchId' => 17, 'Status' => 'previewed']);
        $run->setRelation('lines', new Collection([(new StockReconRunLine)->forceFill([
            'Id' => 10, 'RunId' => 7, 'BranchId' => 17, 'AreaNo' => 1, 'StockItemNo' => 'TEST',
            'ChainBlocked' => true, 'Outcome' => 'Held back', 'CommitState' => 'pending',
        ])]));

        return $run;
    }

    private function context(): BranchContext
    {
        return (new BranchContext)->setAllowed([17]);
    }

    private function assertNotFound(callable $action): void
    {
        try {
            $action();
            $this->fail('Out-of-scope evidence was accepted.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
