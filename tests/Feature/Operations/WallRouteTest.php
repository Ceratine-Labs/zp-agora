<?php

namespace Tests\Feature\Operations;

use Modules\Core\Models\User;
use Tests\TestCase;

class WallRouteTest extends TestCase
{
    public function test_both_wall_routes_require_sign_in(): void
    {
        $this->get(route('app.operations.wall'))->assertRedirect(route('login'));
        $this->getJson(route('app.operations.snapshot'))->assertUnauthorized();
    }

    public function test_an_account_without_wall_permission_cannot_read_the_snapshot(): void
    {
        $this->skipUnlessLocalStub('This check uses local permission lookup only.');
        $user = new User;
        $user->Id = -990025;
        $user->BranchId = 2;
        $user->Workspace = 'ho';
        $this->actingAs($user)->getJson(route('app.operations.snapshot'))->assertForbidden();
    }
}
