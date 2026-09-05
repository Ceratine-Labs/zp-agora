<?php

namespace Tests\Feature\Grid;

use App\Grid\GridColumnState;
use App\Grid\GridRegistry;
use Modules\Core\Models\User;
use Modules\Core\Models\UserGridColumn;
use Tests\TestCase;

/**
 * The persistence round trip: a layout saved through the endpoint, read back
 * by the service, and forgotten again.
 *
 * This is the one grid test that WRITES. It writes a single row to
 * `agora.UserGridColumn` under a GridKey prefixed TEST-, so a leftover is
 * obvious in a listing, and tearDown deletes it whether the test passed or not.
 * Nothing in any of the customer's three databases is touched.
 */
class GridStateTest extends TestCase
{
    private const KEY = 'TEST-app.dev.grids:branches';

    private const REAL_KEY = 'app.dev.grids:branches';

    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', config('agora.admin.email'))->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    protected function tearDown(): void
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', config('agora.admin.email'))->first();

        if ($user) {
            UserGridColumn::forget((int) $user->Id, self::KEY);
            UserGridColumn::forget((int) $user->Id, self::REAL_KEY);
        }

        parent::tearDown();
    }

    public function test_a_layout_survives_being_written_and_read_back(): void
    {
        $user = $this->admin();

        UserGridColumn::put((int) $user->Id, self::KEY, [
            'column_order' => ['Name', 'BranchId'],
            'hidden' => ['SortOrder'],
            'widths' => ['Name' => 240],
            'text_size' => 'compact',
        ]);

        $state = UserGridColumn::layout((int) $user->Id, self::KEY);

        $this->assertSame(['Name', 'BranchId'], $state['column_order']);
        $this->assertSame(['SortOrder'], $state['hidden']);
        $this->assertSame(['Name' => 240], $state['widths']);
        $this->assertSame('compact', $state['text_size']);
    }

    public function test_the_row_carries_the_group_branch_never_null(): void
    {
        $user = $this->admin();

        UserGridColumn::put((int) $user->Id, self::KEY, ['text_size' => 'large']);

        $row = UserGridColumn::query()
            ->acrossBranches()
            ->where('UserId', $user->Id)
            ->where('GridKey', self::KEY)
            ->first();

        $this->assertNotNull($row);
        // Never NULL — that is how legacy rows became unreportable, and the
        // natural key (BranchId, UserId, GridKey) includes it.
        $this->assertSame((int) config('agora.group_branch_id'), (int) $row->BranchId);
    }

    public function test_an_empty_state_deletes_the_row_rather_than_storing_an_empty_object(): void
    {
        $user = $this->admin();

        UserGridColumn::put((int) $user->Id, self::KEY, ['text_size' => 'large']);
        UserGridColumn::put((int) $user->Id, self::KEY, []);

        $this->assertSame([], UserGridColumn::layout((int) $user->Id, self::KEY));
        $this->assertSame(0, UserGridColumn::query()
            ->acrossBranches()
            ->where('UserId', $user->Id)
            ->where('GridKey', self::KEY)
            ->count());
    }

    public function test_an_unreadable_layout_gives_the_default_grid_rather_than_an_exception(): void
    {
        $user = $this->admin();

        UserGridColumn::put((int) $user->Id, self::KEY, ['text_size' => 'large']);

        UserGridColumn::query()
            ->acrossBranches()
            ->where('UserId', $user->Id)
            ->where('GridKey', self::KEY)
            ->update(['ColumnsJson' => 'not json at all']);

        // A layout is a convenience. A corrupt one must give the person the
        // default grid, never a 500 on a page they were only trying to read.
        $this->assertSame([], UserGridColumn::layout((int) $user->Id, self::KEY));
    }

    /* ------------------------------------------------------------ endpoint */

    public function test_the_endpoint_stores_only_the_whitelisted_keys(): void
    {
        $response = $this->actingAs($this->admin())->postJson(
            route('app.grids.columns.store', ['grid' => self::REAL_KEY]),
            [
                'column_order' => ['Name', 'BranchId'],
                'hidden' => ['SortOrder'],
                'widths' => ['Name' => 5000, 'BranchId' => 120],
                'text_size' => 'large',
                'evil' => 'payload',
            ],
        );

        $response->assertOk()->assertJson(['saved' => true]);

        $state = UserGridColumn::layout((int) $this->admin()->Id, self::REAL_KEY);

        $this->assertArrayNotHasKey('evil', $state);
        $this->assertSame(['BranchId' => 120], $state['widths'], '5000px is out of bounds and does not survive');
        $this->assertSame('large', $state['text_size']);
    }

    public function test_the_endpoint_refuses_a_grid_key_nothing_claims(): void
    {
        // A layout stored under a key no grid answers to is a row nothing will
        // ever read again — and it would let anyone fill the table with keys of
        // their own choosing.
        $this->actingAs($this->admin())
            ->postJson(route('app.grids.columns.store', ['grid' => 'app.not.a.grid']), ['text_size' => 'large'])
            ->assertNotFound();
    }

    public function test_deleting_the_layout_puts_the_catalogue_back(): void
    {
        $user = $this->admin();

        UserGridColumn::put((int) $user->Id, self::REAL_KEY, ['hidden' => ['Name']]);

        $this->actingAs($user)
            ->deleteJson(route('app.grids.columns.destroy', ['grid' => self::REAL_KEY]))
            ->assertOk();

        $this->assertSame([], UserGridColumn::layout((int) $user->Id, self::REAL_KEY));
    }

    public function test_an_anonymous_caller_cannot_save_a_layout(): void
    {
        $this->postJson(
            route('app.grids.columns.store', ['grid' => self::REAL_KEY]),
            ['text_size' => 'large'],
        )->assertStatus(401);
    }

    /* ------------------------------------------------- state on the screen */

    public function test_a_saved_layout_changes_what_the_screen_renders(): void
    {
        $user = $this->admin();

        // Ship-visible column hidden, ship-hidden column shown: both halves of
        // the round trip, in one assertion pair.
        UserGridColumn::put((int) $user->Id, self::REAL_KEY, [
            'column_order' => ['BrandId', 'Name', 'BranchId', 'IsTrading', 'IsActive', 'SortOrder', 'RegionId', 'CreatedAt'],
            'hidden' => ['IsTrading', 'RegionId', 'CreatedAt'],
        ]);

        $definition = app(GridRegistry::class)->findOrFail(self::REAL_KEY);
        $columns = GridColumnState::apply(UserGridColumn::layout((int) $user->Id, self::REAL_KEY), $definition);
        $visible = array_values(array_map(fn ($c) => $c->key(), array_filter($columns, fn ($c) => $c->visible)));

        $this->assertSame('BrandId', $columns[0]->key(), 'the saved order leads');
        $this->assertContains('BrandId', $visible, 'a column shipped off can be turned on');
        $this->assertNotContains('IsTrading', $visible, 'and one shipped on can be turned off');
    }
}
