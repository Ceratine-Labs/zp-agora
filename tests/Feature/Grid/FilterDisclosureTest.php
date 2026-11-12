<?php

namespace Tests\Feature\Grid;

use App\Grid\GridDefinition;
use App\Grid\GridQuery;
use App\Grid\GridResult;
use App\Grid\Sources\GridSource;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;
use Modules\Reports\Services\ReportsService;
use Tests\TestCase;

/** Render the real filter components without opening a database. */
class FilterDisclosureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection(config('agora.connections.app'));
        $refuse = fn () => throw new \LogicException('Filter rendering must not query a database.');
        $connection->setPdo($refuse);
        $connection->setReadPdo($refuse);
        View::share('errors', new ViewErrorBag);

        $permissions = new class extends PermissionService
        {
            public function patternsFor(User $user): array
            {
                return ['reports.*.view'];
            }
        };
        $this->app->instance(PermissionService::class, $permissions);
    }

    public function test_grid_filters_start_closed_and_isolate_users_and_grids(): void
    {
        $keys = [];
        foreach ([[99991, 'app.test:first'], [99991, 'app.test:second'], [99992, 'app.test:first']] as [$userId, $key]) {
            $this->signIn($userId);
            $definition = new class($key) extends GridDefinition
            {
                public function __construct(private string $gridKey) {}

                public function key(): string
                {
                    return $this->gridKey;
                }

                public function title(): string
                {
                    return 'TEST-filter';
                }

                public function columns(): array
                {
                    return [];
                }

                public function source(): GridSource
                {
                    throw new \LogicException('Rendering must not fetch grid rows.');
                }
            };
            $grid = new GridResult(
                definition: $definition, columns: [], rows: collect(),
                gridQuery: new GridQuery(search: 'needle', page: 1, pageSize: 50),
                total: 0, totals: [], totalsAreGrand: false, textSize: 'normal',
                state: [], baseUrl: '/app/test', query: ['other_page' => 3], ms: 0,
            );

            $html = view('grid._scope', ['grid' => $grid, 'branches' => null, 'formId' => 'TEST-scope'])->render();
            $keys[] = $this->closedKey($html, 'grid-scope:'.$userId.':'.$key);
            $this->assertStringContainsString('value="needle"', $html);
            $this->assertStringContainsString('name="other_page" value="3"', $html);
            $this->assertStringContainsString('method="GET"', $html);
        }

        $this->assertCount(3, array_unique($keys));
    }

    public function test_report_filters_start_closed_preserve_scope_and_isolate_users_and_reports(): void
    {
        $service = app(ReportsService::class);
        $keys = [];
        foreach ([[99991, 'day-close'], [99991, 'my-queue'], [99992, 'day-close']] as [$userId, $key]) {
            $this->signIn($userId);
            $report = $service->definition($key);
            $params = $service->parametersFor($key, ['from' => '2026-10-01', 'to' => '2026-10-02', 'search' => 'needle']);
            $html = Blade::render('<x-reports::scope :report="$report" :params="$params" :context="$context" :branches="$branches" />', [
                'report' => $report, 'params' => $params,
                'context' => (new BranchContext)->setWorkspace('branch')->set(999),
                'branches' => collect(),
            ]);

            $keys[] = $this->closedKey($html, 'report-scope:'.$userId.':'.$key);
            $this->assertStringContainsString('value="2026-10-01"', $html);
            $this->assertStringContainsString('value="2026-10-02"', $html);
            $this->assertStringContainsString('value="needle"', $html);
        }

        $this->assertCount(3, array_unique($keys));
    }

    private function signIn(int $id): void
    {
        $user = new User;
        $user->setRawAttributes(['Id' => $id]);
        $this->actingAs($user);
    }

    private function closedKey(string $html, string $expected): string
    {
        $this->assertSame(1, preg_match('/<details\b[^>]*data-remember="([^"]+)"[^>]*>/', $html, $match));
        $this->assertSame($expected, $match[1]);
        $this->assertDoesNotMatchRegularExpression('/\sopen(?:\s|=|>)/', $match[0]);

        return $match[1];
    }
}
