<?php

namespace Tests\Feature\Operations;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Http\Request;
use Modules\Operations\Services\WallScope;
use Tests\TestCase;

class WallScopeTest extends TestCase
{
    public function test_forbidden_and_malformed_selections_never_expand_to_all_sites(): void
    {
        $context = (new BranchContext)->setAllowed([3, 5]);
        $scope = new WallScope(new ProcedureService, $context);
        $sites = [['id' => 3, 'name' => 'A', 'division' => ''], ['id' => 5, 'name' => 'B', 'division' => '']];
        $this->assertSame([3, 5], $scope->selected(Request::create('/'), $sites));
        $this->assertSame([], $scope->selected(Request::create('/', 'GET', ['branches' => [999]]), $sites));
        $this->assertSame([], $scope->selected(Request::create('/', 'GET', ['branches' => [['5'], 'bad']]), $sites));
        $this->assertSame([5], $scope->selected(Request::create('/', 'GET', ['branches' => [5, 999, 5]]), $sites));
        $this->assertSame([], $scope->selected(Request::create('/', 'GET', ['branches' => '']), $sites));
    }

    public function test_branch_workspace_ignores_a_forged_selection_and_pins_the_site(): void
    {
        $context = (new BranchContext)->setWorkspace('branch')->set(3)->setAllowed([3]);
        $scope = new WallScope(new ProcedureService, $context);
        $this->assertSame([3], $scope->selected(Request::create('/', 'GET', ['branches' => [5]]), [
            ['id' => 3, 'name' => 'A', 'division' => ''],
        ]));
    }
}
