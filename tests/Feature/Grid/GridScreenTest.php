<?php

namespace Tests\Feature\Grid;

use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * The grid, rendered.
 *
 * Read-only: it renders the development gallery, which runs a real procedure
 * and a real query builder and writes nothing anywhere. What it is checking is
 * the parts of feature-rules §3 that are only visible in the markup — the
 * procedure name on the grid, the "showing N of M" line, the sort links, the
 * filter row, the mobile cards, and the extract URL carrying the view state.
 */
class GridScreenTest extends TestCase
{
    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', config('agora.admin.email'))->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    public function test_the_gallery_renders_both_grids(): void
    {
        $this->actingAs($this->admin())
            ->get(route('app.dev.grids'))
            ->assertOk()
            ->assertSee('Day close status')
            ->assertSee('Branches');
    }

    public function test_a_grid_says_which_procedure_produced_it(): void
    {
        // feature-rules §3.4. The customer reads this and goes straight to the
        // object they are allowed to change.
        $this->actingAs($this->admin())
            ->get(route('app.dev.grids'))
            ->assertOk()
            ->assertSee('agora.usp_Reports_GridDayClose');
    }

    public function test_a_grid_says_how_much_of_the_answer_it_is_showing(): void
    {
        // "Showing 50 of 12 480" — the user must know when they are looking at
        // part of the answer (feature-rules, proposed §C).
        $this->actingAs($this->admin())
            ->get(route('app.dev.grids'))
            ->assertOk()
            ->assertSee('Showing')
            ->assertSee('extract to see them all', false);
    }

    public function test_a_sortable_header_is_a_link_and_an_unsortable_one_is_not(): void
    {
        $html = $this->actingAs($this->admin())->get(route('app.dev.grids'))->getContent();

        // The day-close grid sorts on BranchName; the branches grid's DayEnds
        // column has no sort value because the procedure has no key for it.
        $this->assertStringContainsString('dayclose_sort=BranchName', (string) $html);
        $this->assertStringNotContainsString('dayclose_sort=DayEnds', (string) $html);
    }

    public function test_two_grids_on_one_screen_do_not_share_their_parameters(): void
    {
        // The whole reason a GridKey is qualified. Sorting one must not page
        // the other, and a shared `?page=` would only ever be found on the
        // screens that matter most: a summary above a detail.
        $html = (string) $this->actingAs($this->admin())->get(route('app.dev.grids'))->getContent();

        $this->assertStringContainsString('dayclose_sort=', $html);
        $this->assertStringContainsString('branches_sort=', $html);
    }

    public function test_the_extract_url_carries_the_visible_columns_and_the_view_state(): void
    {
        $html = (string) $this->actingAs($this->admin())
            ->get(route('app.dev.grids').'?dayclose_sort=BranchName&dayclose_dir=desc')
            ->getContent();

        $this->assertStringContainsString('format=xlsx', $html);
        $this->assertStringContainsString('columns=', $html);
        // The sort travels into the extract, or the file is not what the person
        // is looking at (feature-rules §3.2).
        $this->assertStringContainsString('dayclose_sort=BranchName', $html);
    }

    public function test_the_header_filter_row_appears_only_where_the_source_can_answer_it(): void
    {
        $html = (string) $this->actingAs($this->admin())->get(route('app.dev.grids'))->getContent();

        // The branches grid is over Eloquent, which can answer a filter.
        $this->assertStringContainsString('name="branches_f[Name][q]"', $html);
        // The day-close procedure declares no @FiltersJson, so it declares no
        // header filters — rather than the grid filtering in PHP, which would
        // put the matching semantics in two places.
        $this->assertStringNotContainsString('name="dayclose_f[', $html);
    }

    public function test_the_grid_renders_a_card_list_for_a_narrow_viewport(): void
    {
        // plan §3.9. The cards are rendered alongside the table and the
        // stylesheet shows one or the other — so the assertion is that the
        // markup is there, and the Playwright mobile project checks which one
        // is actually visible at 375px.
        $this->actingAs($this->admin())
            ->get(route('app.dev.grids'))
            ->assertOk()
            ->assertSee('dg-cards', false)
            ->assertSee('dg-card-face', false);
    }

    public function test_a_page_beyond_the_end_returns_an_empty_grid_rather_than_an_error(): void
    {
        // A grid is a read. A URL somebody edited by hand, or a link that has
        // gone stale because rows were cleared, should show them something.
        $this->actingAs($this->admin())
            ->get(route('app.dev.grids').'?dayclose_page=99999&branches_page=99999')
            ->assertOk()
            ->assertSee('Nothing matched');
    }

    public function test_a_malformed_view_state_degrades_instead_of_failing(): void
    {
        $this->actingAs($this->admin())
            ->get(route('app.dev.grids').'?dayclose_sort=DROP+TABLE&dayclose_size=99999&dayclose_from=not-a-date&dayclose_page=-4')
            ->assertOk()
            ->assertSee('agora.usp_Reports_GridDayClose');
    }

    public function test_the_extract_endpoint_streams_a_csv_of_the_columns_asked_for(): void
    {
        $response = $this->actingAs($this->admin())->get(
            route('app.grids.extract', ['grid' => 'app.dev.grids:branches'])
            .'?format=csv&columns=Name,BranchId&branches_sort=Name'
        );

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));

        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        $this->assertStringContainsString('Name,Id', $csv);
        $this->assertStringContainsString('Zululand Petroleum', $csv);
    }

    public function test_the_extract_endpoint_serves_a_workbook(): void
    {
        $response = $this->actingAs($this->admin())->get(
            route('app.grids.extract', ['grid' => 'app.dev.grids:branches']).'?format=xlsx&columns=Name'
        );

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type'),
        );
    }

    public function test_the_extract_endpoint_refuses_a_grid_key_nothing_claims(): void
    {
        $this->actingAs($this->admin())
            ->get(route('app.grids.extract', ['grid' => 'app.not.a.grid']).'?format=csv')
            ->assertNotFound();
    }

    public function test_an_anonymous_caller_cannot_extract(): void
    {
        $this->get(route('app.grids.extract', ['grid' => 'app.dev.grids:branches']).'?format=csv')
            ->assertRedirect('/login');
    }
}
