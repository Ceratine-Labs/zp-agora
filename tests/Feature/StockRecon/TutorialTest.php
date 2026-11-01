<?php

namespace Tests\Feature\StockRecon;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Modules\Core\View\Composers\ShellComposer;
use Modules\StockRecon\Http\Controllers\StockReconTutorialController;
use Modules\StockRecon\Services\StockReconTutorialContent;
use Tests\TestCase;

class TutorialTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(config('database.connections')) as $name) {
            $connection = DB::connection($name);
            $refuse = fn () => throw new \LogicException('The tutorial must render without a database.');
            $connection->setPdo($refuse);
            $connection->setReadPdo($refuse);
        }
    }

    public function test_the_learning_route_requires_the_existing_run_view_permission(): void
    {
        $route = Route::getRoutes()->getByName('app.stockrecon.tutorial');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('can:stockrecon.runs.view', $route->gatherMiddleware());
        $this->get('/app/stock-recon/tutorial')->assertRedirect(route('login'));
    }

    public function test_a_lesson_link_selects_only_a_known_chapter(): void
    {
        foreach ([['lesson' => 'residual'], ['lesson' => 'missing-issues']] as $query) {
            $view = $this->tutorial($query);
            $this->assertSame($query['lesson'], $view->getData()['lesson']);
        }

        foreach ([[], ['lesson' => 'unknown'], ['lesson' => '<script>'], ['lesson' => ['residual']]] as $query) {
            $this->assertSame('purpose', $this->tutorial($query)->getData()['lesson']);
        }
    }

    public function test_the_complete_tutorial_renders_without_customer_data(): void
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

        $html = $this->tutorial(['lesson' => 'balancing'])->render();
        $this->assertStringContainsString('Stock recon, explained', $html);
        foreach ((new StockReconTutorialContent)->all()['lessons'] as $lesson) {
            $this->assertStringContainsString(e($lesson['title']), $html);
        }
        $this->assertStringContainsString('training', strtolower($html));
        $this->assertStringNotContainsString('$content[', $html);
        $this->assertStringNotContainsString('@foreach', $html);

        preg_match_all('/\bid="([^"]+)"/', $html, $matches);
        $this->assertSame($matches[1], array_values(array_unique($matches[1])), 'Repeated examples must keep unique accessible control targets.');
        preg_match_all('/\baria-controls="([^"]+)"/', $html, $controls);
        foreach ($controls[1] as $id) {
            $this->assertContains($id, $matches[1]);
        }
    }

    /** @param array<string, mixed> $query */
    private function tutorial(array $query): \Illuminate\Contracts\View\View
    {
        return (new StockReconTutorialController)(Request::create('/app/stock-recon/tutorial', 'GET', $query), new StockReconTutorialContent);
    }
}
