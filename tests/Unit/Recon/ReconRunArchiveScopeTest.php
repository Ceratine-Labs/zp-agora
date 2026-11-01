<?php

namespace Tests\Unit\Recon;

use App\Grid\GridQuery;
use App\Grid\GridResult;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Recon\Grids\ReconRunGrid;
use Modules\Recon\Http\Requests\ArchiveRunsRequest;
use Modules\Recon\Services\ReconRunArchiveService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReconRunArchiveScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(config('database.connections')) as $name) {
            DB::connection($name)->beforeExecuting(fn () => throw new \RuntimeException('Database access forbidden in scope tests'));
        }
    }

    private function request(int $actor = 7, bool $allowed = true): Request
    {
        $user = $this->createPartialMock(User::class, ['can']);
        $user->forceFill(['Id' => $actor]);
        $user->method('can')->willReturn($allowed);
        $request = Request::create('/app/recon/auto/ABSA/runs', 'GET', [
            'area' => 'ABSA', 'scope' => 'all', 'validity' => 'invalid', 'page' => 3,
            'q' => 'August', 'from' => '2026-08-01', 'to' => '2026-08-31', 'ws' => 'ho',
            'f' => ['RunBy' => ['q' => 'Clerk']],
        ]);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function grid(Request $request): GridResult
    {
        return new GridResult(
            definition: new ReconRunGrid,
            columns: [], rows: collect(),
            gridQuery: new GridQuery(branchIds: [3, 5], from: '2026-08-01', to: '2026-08-31', search: 'August',
                page: 3, pageSize: 50, filters: [['column' => 'RunBy', 'type' => 'text', 'op' => 'contains', 'value' => 'Clerk']]),
            total: 125, totals: [], totalsAreGrand: false, textSize: 'normal', state: [],
            baseUrl: $request->url(), query: $request->query(), ms: 0,
        );
    }

    public function test_all_filtered_archive_preserves_exact_scope_without_a_page_ceiling_and_uses_fresh_grants(): void
    {
        $request = $this->request();
        $context = (new BranchContext)->setAllowed([3, 5])->setWorkspace('ho');
        $procedures = $this->createMock(ProcedureService::class);
        $service = new ReconRunArchiveService($procedures);
        $token = $service->scope($this->grid($request), $request, $context);
        $context->setAllowed([5]);
        $request->query->replace(['area' => 'FNB', 'scope' => 'all', 'branches' => '99']);
        $procedures->expects($this->once())->method('write')->with('usp_Recon_ArchiveInvalidRuns', $this->callback(function ($parameters) {
            $this->assertSame('3,5', $parameters['BranchIds']);
            $this->assertSame('5', $parameters['AllowedBranchIds']);
            $this->assertSame('ABSA', $parameters['ReconArea']);
            $this->assertSame('2026-08-01', $parameters['DateFrom']);
            $this->assertSame('2026-08-31', $parameters['DateTo']);
            $this->assertSame('August', $parameters['Search']);
            $this->assertSame([['column' => 'RunBy', 'type' => 'text', 'op' => 'contains', 'value' => 'Clerk']], json_decode($parameters['FiltersJson'], true));
            $this->assertSame(0, $parameters['MineOnly']);
            $this->assertSame(7, $parameters['UserId']);
            $this->assertNull($parameters['RunIdsJson']);
            $this->assertArrayNotHasKey('Page', $parameters);
            $this->assertArrayNotHasKey('PageSize', $parameters);

            return true;
        }))->willReturn((object) ['Id' => 70, 'Archived' => 70, 'Skipped' => 2]);
        $result = $service->archive($token, 'all', [], $request, $context);
        $this->assertSame(70, $result['archived']);
        $this->assertSame(2, $result['skipped']);
        $this->assertStringContainsString('/auto/ABSA/runs?', $result['redirect']);
        $this->assertStringContainsString('validity=invalid', $result['redirect']);
        $this->assertStringNotContainsString('page=', $result['redirect']);
    }

    public function test_selected_ids_do_not_expand_mine_or_pinned_branch_scope(): void
    {
        $request = $this->request();
        $request->query->set('scope', 'mine');
        $context = (new BranchContext)->setWorkspace('branch')->set(3)->setAllowed([3]);
        $grid = $this->grid($request);
        $grid->gridQuery->branchIds = [3];
        $procedures = $this->createMock(ProcedureService::class);
        $service = new ReconRunArchiveService($procedures);
        $token = $service->scope($grid, $request, $context);
        $procedures->expects($this->once())->method('write')->with('usp_Recon_ArchiveInvalidRuns', $this->callback(function ($parameters) {
            $this->assertSame('3', $parameters['BranchIds']);
            $this->assertSame('3', $parameters['AllowedBranchIds']);
            $this->assertSame(1, $parameters['MineOnly']);
            $this->assertSame('[260,262]', $parameters['RunIdsJson']);

            return true;
        }))->willReturn((object) ['Id' => 1, 'Skipped' => 1]);
        $this->assertSame(1, $service->archive($token, 'selected', ['260', 262], $request, $context)['archived']);
    }

    public function test_invalid_stale_or_different_actor_scopes_never_call_the_writer(): void
    {
        $request = $this->request();
        $context = (new BranchContext)->setWorkspace('ho');
        $procedures = $this->createMock(ProcedureService::class);
        $procedures->expects($this->never())->method('write');
        $service = new ReconRunArchiveService($procedures);
        $token = $service->scope($this->grid($request), $request, $context);
        $scope = json_decode(Crypt::decryptString($token), true);
        $badScopes = [
            'not-an-encrypted-scope',
            Crypt::encryptString(json_encode(array_replace($scope, ['actor' => 99]))),
            Crypt::encryptString(json_encode(array_replace($scope, ['workspace' => 'branch']))),
            Crypt::encryptString(json_encode(array_replace($scope, ['expires' => now()->subSecond()->timestamp]))),
            Crypt::encryptString(json_encode(array_replace($scope, ['parameters' => []]))),
        ];
        foreach ($badScopes as $bad) {
            try {
                $service->archive($bad, 'all', [], $request, $context);
                $this->fail('Invalid scope reached writer');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('scope_token', $exception->errors());
            }
        }
    }

    public function test_changed_branch_or_removed_permission_refuses_a_previous_scope(): void
    {
        $request = $this->request();
        $context = (new BranchContext)->setWorkspace('branch')->set(3);
        $procedures = $this->createMock(ProcedureService::class);
        $procedures->expects($this->never())->method('write');
        $service = new ReconRunArchiveService($procedures);
        $token = $service->scope($this->grid($request), $request, $context);
        $context->set(5);
        try {
            $service->archive($token, 'all', [], $request, $context);
            $this->fail('Changed branch accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scope_token', $exception->errors());
        }
        $this->expectException(HttpException::class);
        $service->archive($token, 'all', [], $this->request(7, false), $context);
    }

    public function test_selection_validation_and_route_permissions_fail_closed(): void
    {
        $rules = (new ArchiveRunsRequest)->rules();
        foreach ([
            ['mode' => 'selected'],
            ['mode' => 'selected', 'items' => []],
            ['mode' => 'selected', 'items' => [0]],
            ['mode' => 'selected', 'items' => [260, 260]],
            ['mode' => 'selected', 'items' => range(1, 201)],
            ['mode' => 'all', 'items' => [260]],
            ['mode' => 'unknown'],
        ] as $data) {
            $this->assertTrue(Validator::make($data + ['scope_token' => 'token'], $rules)->fails());
        }
        $this->assertTrue(Validator::make(['scope_token' => 'token', 'mode' => 'all'], $rules)->passes());
        $middleware = Route::getRoutes()->getByName('app.recon.archive')->gatherMiddleware();
        $this->assertContains('can:recon.runs.view', $middleware);
        $this->assertContains('can:recon.runs.close', $middleware);
    }
}
