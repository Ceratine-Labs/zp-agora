<?php

namespace Tests\Feature\Reports;

use App\Support\BranchContext;
use Illuminate\Http\Request;
use Modules\Reports\Http\Controllers\WasteAuthorizationController;
use Modules\Reports\Services\WasteAuthorizationService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WasteAuthorizationScopeTest extends TestCase
{
    public function test_a_branch_user_cannot_authorize_another_sites_waste(): void
    {
        $request = Request::create('/', 'POST', [
            'branch_id' => 8, 'date' => '2030-01-02', 'shift' => 1, 'area' => 1,
            'item' => 'A', 'captured_good' => 1, 'decision' => 'authorize', 'authorized_good' => 1,
        ]);
        $context = (new BranchContext)->setWorkspace('branch')->set(7)->setAllowed([7]);
        try {
            (new WasteAuthorizationController)($request, $context, app(WasteAuthorizationService::class));
            $this->fail('Another site must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
