<?php

namespace Tests\Unit\Recon;

use App\Grid\GridColumnState;
use App\Grid\GridQuery;
use App\Grid\GridResult;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Modules\Core\Models\User;
use Modules\Recon\Grids\ReconRunGrid;
use RuntimeException;
use Tests\TestCase;

class ReconRunPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(config('database.connections')) as $connection) {
            DB::connection($connection)->beforeExecuting(function (): void {
                throw new RuntimeException('Run presentation must not query the database.');
            });
        }
        view()->share('errors', new ViewErrorBag);
        app(BranchContext::class)->setAllowed([13])->setWorkspace('ho');
    }

    public function test_invalid_selection_is_permission_gated_and_normal_lists_do_not_select(): void
    {
        $grid = new ReconRunGrid;
        $this->request(['validity' => 'invalid']);
        $this->assertTrue($grid->selectable());
        $this->assertFalse(ReconRunGrid::wantsOpenOnly(), 'Failed or other rows must not leak in through a conflicting Open toggle.');
        $this->assertSame(260, $grid->rowKey((object) ['Id' => 260]));
        $this->request(['validity' => 'invalid'], false);
        $this->assertFalse($grid->selectable());
        $this->request(['show' => 'all']);
        $this->assertFalse($grid->selectable());
    }

    public function test_switches_preserve_search_dates_sites_and_header_filters_but_reset_page(): void
    {
        $query = ['scope' => 'all', 'show' => 'all', 'validity' => 'invalid', 'ws' => 'ho', 'area' => 'ABSA',
            'branches' => [13], 'from' => '2026-10-01', 'to' => '2026-10-31', 'q' => '<search>',
            'f' => ['Id' => ['op' => 'gte', 'value' => '200']], 'sort' => 'Id', 'dir' => 'asc', 'page' => 3];
        $this->request($query);
        $html = view('recon::partials.run-switches', ['grid' => $this->grid($query)])->render();
        preg_match_all('/href="([^"]+)"/', $html, $matches);
        $this->assertCount(5, $matches[1]);
        foreach ($matches[1] as $href) {
            parse_str(parse_url(html_entity_decode($href), PHP_URL_QUERY), $actual);
            foreach (['area', 'ws', 'branches', 'from', 'to', 'q', 'f', 'sort', 'dir'] as $key) {
                $this->assertEquals($query[$key], $actual[$key], $key);
            }
            $this->assertArrayNotHasKey('page', $actual);
        }
    }

    public function test_archive_forms_separate_current_page_from_all_filtered_pages_and_escape_cells(): void
    {
        $query = ['validity' => 'invalid', 'show' => 'all', 'ws' => 'ho'];
        $this->request($query);
        $grid = $this->grid($query);
        $html = view('recon::partials.run-grid', ['grid' => $grid, 'branches' => null, 'archiveScope' => 'signed-fixture'])->render();
        $this->assertStringContainsString('name="mode" value="all"', $html);
        $this->assertStringContainsString('name="mode" value="selected"', $html);
        $this->assertStringContainsString('Archive all 251 invalid runs in this filtered view — all pages', $html);
        $this->assertStringContainsString('Only the selected runs on this page', $html);
        $this->assertStringContainsString('data-bulk-form="app.recon.runs"', $html);
        $this->assertSame(2, substr_count($html, 'name="scope_token" value="signed-fixture"'));
        $this->assertStringContainsString('ws=ho', $html);
        $this->assertStringContainsString('data-tool-art="bank-absa"', $html);
        $this->assertStringContainsString('2 superseded · 0 remaining', $html);
        $this->assertStringContainsString('&lt;script&gt;example&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>example</script>', $html);

        $this->request($query, false);
        $denied = view('recon::partials.run-grid', ['grid' => $grid, 'branches' => null, 'archiveScope' => 'signed-fixture'])->render();
        $this->assertStringNotContainsString('name="mode"', $denied);
        $this->assertStringNotContainsString('data-check-all', $denied);
    }

    /** @param array<string, mixed> $query */
    private function request(array $query, bool $canArchive = true): void
    {
        $user = new class($canArchive) extends User
        {
            public function __construct(private bool $archiveAllowed = false)
            {
                parent::__construct();
            }

            public function can($abilities, $arguments = []): bool
            {
                return $abilities === 'recon.runs.close' && $this->archiveAllowed;
            }
        };
        $request = Request::create('/app/recon', 'GET', $query);
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $user);
    }

    /** @param array<string, mixed> $query */
    private function grid(array $query): GridResult
    {
        $definition = new ReconRunGrid;

        return new GridResult(
            definition: $definition,
            columns: GridColumnState::apply([], $definition),
            rows: collect([(object) ['Id' => 260, 'Note' => '<script>example</script>', 'ReconArea' => 'ABSA',
                'Status' => 'previewed', 'Validity' => 'invalid', 'InvalidRows' => 2, 'RemainingRows' => 0]]),
            gridQuery: new GridQuery(branchIds: [13], pageSize: 50),
            total: 251, totals: [], totalsAreGrand: false, textSize: 'normal', state: [],
            baseUrl: url('/app/recon'), query: $query, ms: 1.0,
        );
    }
}
