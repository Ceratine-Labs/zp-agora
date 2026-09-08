<?php

namespace Tests\Feature\Recon;

use Modules\Core\Models\User;
use Modules\Recon\Models\ReconRun;
use Tests\TestCase;

/**
 * The runs a person made are theirs, findable, and in a real grid.
 *
 * Read-only: it signs in as the seeded administrator and reads screens. The
 * runs it asserts against are whatever the ledger already holds — nothing is
 * previewed here, because a preview against the customer's estate to prove a
 * list renders would be an absurd price for the answer, and a run written by a
 * test is a run somebody has to clean up.
 *
 * The one thing that cannot be asserted without rows is the resume card, so
 * that test says so rather than passing vacuously.
 */
class ReconRunListTest extends TestCase
{
    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    /** The tab is the grid, and the grid names the procedure behind it (§3.4). */
    public function test_the_runs_tab_renders_the_grid_over_its_procedure(): void
    {
        $this->actingAs($this->admin())->get('/app/recon/auto/ABSA/runs')
            ->assertOk()
            ->assertSee('agora.usp_Recon_GridRuns')
            // Typed header filters, which is the half that has to reach the
            // procedure in the shape it parses (§3.1).
            ->assertSee('Would reconcile')
            ->assertSee('Run by');
    }

    /**
     * Mine by default, everyone's on request — and the sub-heading says which,
     * because a toggle that does not report its own state is a toggle people
     * press twice.
     */
    public function test_the_scope_switch_defaults_to_mine_and_flips(): void
    {
        $this->actingAs($this->admin())->get('/app/recon/auto/ABSA/runs')
            ->assertOk()
            ->assertSee('The previews you have made in this area');

        $this->actingAs($this->admin())->get('/app/recon/auto/ABSA/runs?scope=all')
            ->assertOk()
            ->assertSee('Every preview made in this area, whoever made it');
    }

    /**
     * The hub renders the SAME grid with no area, rather than a second list.
     *
     * The hand-written ten-row table it replaced could not be filtered, sorted
     * or extracted, which made it the only list in Agora you could not get out
     * of the screen.
     */
    public function test_the_hub_renders_the_same_grid_across_every_area(): void
    {
        $this->actingAs($this->admin())->get('/app/recon')
            ->assertOk()
            ->assertSee('agora.usp_Recon_GridRuns')
            ->assertSee('Every preview across the five areas');
    }

    /** A run can be given a name on the way in, so it can be found by one. */
    public function test_the_preview_form_offers_a_name_for_the_run(): void
    {
        $this->actingAs($this->admin())->get('/app/recon/auto/ABSA')
            ->assertOk()
            ->assertSee('Name this run')
            ->assertSee('name="note"', false);
    }

    /**
     * Where the clerk left off.
     *
     * Asserted against the ledger rather than against a fixture: if this
     * administrator has an uncommitted run, both the hub and the area must
     * offer to resume it; if they have none, the card must not render at all —
     * an empty "you have no unfinished work" panel on every visit is noise.
     */
    public function test_an_open_run_is_offered_for_resuming(): void
    {
        $admin = $this->admin();
        $open = ReconRun::openFor($admin->Id);

        $hub = $this->actingAs($admin)->get('/app/recon')->assertOk();

        if ($open === null) {
            $hub->assertDontSee('You have a run open');
            $this->markTestSkipped('This administrator has no uncommitted run to resume.');
        }

        $hub->assertSee('You have a run open')
            ->assertSee('Resume run #'.$open->Id);

        // The area asks the same question of its own area only.
        $inArea = ReconRun::openFor($admin->Id, 'ABSA');

        $area = $this->actingAs($admin)->get('/app/recon/auto/ABSA')->assertOk();

        $inArea === null
            ? $area->assertDontSee('You have a run open')
            : $area->assertSee('Resume run #'.$inArea->Id);
    }

    /** The extract every other grid has (§3.2), over the same rows. */
    public function test_the_run_list_extracts(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('app.grids.extract', ['grid' => 'app.recon.runs', 'format' => 'csv', 'scope' => 'all']));

        $response->assertOk();
        $this->assertStringContainsString('Would reconcile', $response->streamedContent());
    }
}
