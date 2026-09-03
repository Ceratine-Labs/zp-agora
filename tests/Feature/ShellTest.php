<?php

namespace Tests\Feature;

use App\Support\BranchContext;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\User;
use Modules\Core\Services\MenuService;
use Tests\TestCase;

/**
 * The application boots, refuses an anonymous caller, and serves a signed-in
 * one a shell whose navigation came out of the database.
 *
 * Read-only throughout — it signs in as an existing user rather than creating
 * one, because the connection is the customer's production database and a test
 * that seeds users leaves rows behind on a failure.
 */
class ShellTest extends TestCase
{
    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@ceratine-labs.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    public function test_the_sign_in_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('AG')
            ->assertSee('Email address');
    }

    public function test_an_anonymous_caller_is_sent_to_sign_in(): void
    {
        $this->get('/app')->assertRedirect('/login');
    }

    public function test_the_root_url_leads_into_the_application(): void
    {
        // Guards against the scaffolded welcome route creeping back into
        // routes/web.php, which is loaded before any module and would win.
        $this->get('/')->assertRedirect('/app');
    }

    public function test_a_signed_in_user_gets_the_shell_with_a_database_driven_menu(): void
    {
        $response = $this->actingAs($this->admin())->get('/app');

        $response->assertOk()
            ->assertSee('Today')
            ->assertSee('Trade')
            ->assertSee('Control')
            ->assertSee('Setup');

        // Section buttons are rendered from MenuSection rows, not from markup.
        $response->assertSee('data-menu="today"', false);
        $response->assertSee('data-panel="trade"', false);
    }

    public function test_wrong_credentials_are_refused_without_saying_which_half_was_wrong(): void
    {
        $this->post('/login', [
            'email' => 'ryan@ceratine-labs.co.za',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_menu_tree_nests_beyond_two_levels(): void
    {
        // The customer asked for children and sub-children; this is the assertion
        // that the schema and the renderer actually carry a third level rather
        // than flattening it.
        $trade = app(MenuService::class)->tree('ho')->firstWhere('Code', 'trade');
        $reports = $trade->items->firstWhere('Label', 'Reports');

        $this->assertNotNull($reports, 'Trade should carry a Reports column.');
        $this->assertTrue($reports->childItems->isNotEmpty(), 'Reports should have children.');

        $category = $reports->childItems->firstWhere('Label', 'Exco');
        $this->assertNotNull($category);
        $this->assertTrue(
            $category->childItems->isNotEmpty(),
            'A report category should carry its own children — three levels below the section.'
        );
    }

    public function test_every_menu_item_belongs_to_the_group_branch(): void
    {
        // Menu rows are not site-specific, and a NULL branch is what made
        // legacy rows unreportable — so they carry the group entity's id.
        $stray = MenuItem::query()
            ->acrossBranches()
            ->where('BranchId', '!=', app(BranchContext::class)->groupId())
            ->count();

        $this->assertSame(0, $stray);
    }
}
