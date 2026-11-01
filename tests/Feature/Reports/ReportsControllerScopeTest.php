<?php

namespace Tests\Feature\Reports;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Reports\Http\Controllers\ReportsController;
use Modules\Reports\Services\ReportScope;
use Modules\Reports\Services\ReportsService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/** Exercise the real controller/service path without opening a database. */
class ReportsControllerScopeTest extends TestCase
{
    private Connection $connection;

    /** @var array<int, array{procedure: string, params: array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = DB::connection(config('agora.connections.app'));
        $refuse = fn () => throw new \LogicException('This test must not open a database connection.');
        $this->connection->setPdo($refuse);
        $this->connection->setReadPdo($refuse);
    }

    public function test_an_empty_selection_keeps_the_callers_grants_through_the_procedure_boundary(): void
    {
        foreach ([[], ['branches' => []], ['branches' => ''], ['branches' => ' , '], ['branches' => [null, ' ']]] as $query) {
            $this->report((new BranchContext)->setAllowed([]), $query);
            $this->assertSame(null, $this->lastParameters()['BranchIds']);

            $this->report((new BranchContext)->setAllowed([13, 18]), $query);
            $this->assertSame('13,18', $this->lastParameters()['BranchIds']);
        }
    }

    public function test_a_forbidden_selection_is_not_widened_to_every_site(): void
    {
        $this->report((new BranchContext)->setAllowed([13]), ['branches' => [18, 24], 'page' => 2]);

        $this->assertSame('-1', $this->lastParameters()['BranchIds']);
        $this->assertSame(2, $this->lastParameters()['Page']);
    }

    public function test_a_navigation_url_preserves_rejected_sites_and_the_applied_scope_across_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 22:30:00', 'UTC'));

        try {
            $context = (new BranchContext)->setAllowed([13]);
            $this->report($context, [
                'branches' => [18], 'from' => '', 'to' => '', 'q' => '  needle  ', 'sort' => 'unknown',
            ]);

            // A sortable header or pager changes one control using effective
            // parameters, rather than replaying the original untrusted query.
            $query = array_filter(array_merge(ReportScope::query($this->lastParameters()), [
                'sort' => 'BranchName', 'dir' => 'desc', 'page' => 2,
            ]), fn ($value) => $value !== null && $value !== '');
            $url = route('app.reports.show', ['report' => 'day-close'] + $query);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $nextQuery);

            // The next business date must not silently slide the range when
            // the person follows a saved link or moves to another page.
            Carbon::setTestNow(Carbon::parse('2026-11-01 22:30:00', 'UTC'));
            $this->report($context, $nextQuery);
            $params = $this->lastParameters();

            $this->assertSame('-1', $params['BranchIds']);
            $this->assertSame('2026-10-26', $params['DateFrom']);
            $this->assertSame('2026-11-01', $params['DateTo']);
            $this->assertSame('needle', $params['Search']);
            $this->assertSame('BranchName', $params['SortColumn']);
            $this->assertSame(0, $params['SortAsc']);
            $this->assertSame(2, $params['Page']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_invalid_input_is_not_cast_to_a_branch_or_treated_as_an_empty_selection(): void
    {
        foreach ([[], [13]] as $allowed) {
            foreach (['garbage', '13junk', '0', '-1', '2147483648', [['13']], ['13.5'], false] as $value) {
                $this->report((new BranchContext)->setAllowed($allowed), ['branches' => $value]);
                $this->assertSame('-1', $this->lastParameters()['BranchIds']);
            }
        }
    }

    public function test_valid_array_and_csv_selections_are_narrowed_and_deduplicated(): void
    {
        foreach ([['18', '24', '13', '18', ['13'], '13junk'], '18,24,13,18,13junk'] as $value) {
            $this->report((new BranchContext)->setAllowed([13, 18]), ['branches' => $value]);
            $this->assertSame('18,13', $this->lastParameters()['BranchIds']);
        }

        $this->assertSame([18, 13], ReportsService::branchIds(['18', '13junk', ['13'], '13', '18']));
    }

    public function test_a_branch_report_ignores_selection_and_fails_closed_without_a_valid_pin(): void
    {
        $context = (new BranchContext)->setWorkspace('branch')->setAllowed([13, 18])->set(13);
        $this->report($context, ['branches' => [18]]);
        $this->assertSame('13', $this->lastParameters()['BranchIds']);

        foreach ([null, 0, -1] as $pin) {
            $context->set($pin);
            $queries = $this->report($context, ['branches' => [18]]);
            $this->assertSame('-1', $this->lastParameters()['BranchIds']);
            $this->assertSame([true, -1], $queries[0]['bindings']);
        }
    }

    public function test_the_picker_uses_exact_grants_without_the_reference_data_group_exception(): void
    {
        $queries = $this->report((new BranchContext)->setAllowed([13, 18]));

        $this->assertCount(1, $queries);
        $this->assertSame([true, 13, 18], $queries[0]['bindings']);
        $this->assertStringContainsString('[BranchId] in (13, 18)', $queries[0]['query']);
        $this->assertStringContainsString('[IsActive] = 1', $queries[0]['query']);

        $queries = $this->report((new BranchContext)->setAllowed([]));
        $this->assertSame([true], $queries[0]['bindings']);
        $this->assertStringNotContainsString('[BranchId] in', $queries[0]['query']);
    }

    public function test_audit_detail_uses_grants_not_a_requested_report_filter(): void
    {
        $context = (new BranchContext)->setAllowed([13, 18]);

        foreach ([false, true] as $ajax) {
            $request = Request::create('/app/reports/audit-trail/StockReconAmendment/7', 'GET', ['branches' => [24]]);
            if ($ajax) {
                $request->headers->set('X-Requested-With', 'XMLHttpRequest');
            }

            $view = $this->controller(true)->auditChange('StockReconAmendment', 7, $request, $context);

            $this->assertSame('13,18', $this->lastParameters()['AllowedBranchIds']);
            $this->assertSame($ajax ? 'reports::audit.change' : 'reports::audit.page', $view->name());
        }
    }

    public function test_audit_detail_without_a_valid_branch_pin_passes_a_nonmatching_scope(): void
    {
        $context = (new BranchContext)->setWorkspace('branch')->setAllowed([13]);

        foreach ([null, 0, -1] as $pin) {
            $context->set($pin);

            try {
                $this->controller()->auditChange('StockReconAmendment', 7, Request::create('/audit'), $context);
                $this->fail('A missing scoped change must be refused.');
            } catch (NotFoundHttpException) {
                $this->assertSame('-1', $this->lastParameters()['AllowedBranchIds']);
            }
        }
    }

    public function test_audit_detail_preserves_unrestricted_and_pinned_contexts(): void
    {
        $request = Request::create('/audit', 'GET', ['branches' => [24]]);
        $this->controller(true)->auditChange('StockReconAmendment', 7, $request, (new BranchContext)->setAllowed([]));
        $this->assertNull($this->lastParameters()['AllowedBranchIds']);

        $context = (new BranchContext)->setWorkspace('branch')->setAllowed([13, 18])->set(13);
        $this->controller(true)->auditChange('StockReconAmendment', 7, $request, $context);
        $this->assertSame('13', $this->lastParameters()['AllowedBranchIds']);
    }

    public function test_audit_detail_with_no_allowed_result_is_a_404_for_page_and_ajax(): void
    {
        foreach ([false, true] as $ajax) {
            $request = Request::create('/audit');
            if ($ajax) {
                $request->headers->set('X-Requested-With', 'XMLHttpRequest');
            }

            try {
                $this->controller()->auditChange('StockReconAmendment', 7, $request, (new BranchContext)->setAllowed([13]));
                $this->fail('A change outside the allowed scope must be refused.');
            } catch (NotFoundHttpException) {
                $this->assertSame('13', $this->lastParameters()['AllowedBranchIds']);
            }
        }
    }

    private function controller(bool $auditFound = false): ReportsController
    {
        $procedures = $this->createMock(ProcedureService::class);
        $procedures->expects($this->once())->method('callSets')
            ->willReturnCallback(function (string $procedure, array $params) use ($auditFound): array {
                $this->calls[] = ['procedure' => $procedure, 'params' => $params];

                return [collect($auditFound ? [(object) ['BranchId' => 13]] : []), collect([(object) ['TotalRows' => 0]])];
            });

        return new ReportsController(new ReportsService($procedures));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array{query: string, bindings: array<int, mixed>, time: float}>
     */
    private function report(BranchContext $context, array $query = []): array
    {
        $this->app->instance(BranchContext::class, $context);
        $controller = $this->controller();

        return $this->connection->pretend(function () use ($controller, $context, $query): void {
            $view = $controller->show('day-close', Request::create('/app/reports/day-close', 'GET', $query), $context);
            $this->assertInstanceOf(View::class, $view);
        });
    }

    /** @return array<string, mixed> */
    private function lastParameters(): array
    {
        return $this->calls[array_key_last($this->calls)]['params'];
    }
}
