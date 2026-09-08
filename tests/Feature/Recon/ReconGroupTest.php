<?php

namespace Tests\Feature\Recon;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\User;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunGroup;
use Tests\TestCase;

/**
 * The master controller: one period, every site.
 *
 * This test WRITES — a group and a run per site is the only way to prove that
 * a site which cannot run becomes a row rather than a dead page. Everything it
 * creates is named `TEST-` and removed in tearDown, and nothing it does
 * reaches the customer's estate: a preview is a read, and no group here is
 * ever posted.
 */
class ReconGroupTest extends TestCase
{
    private const NAME = 'TEST-group';

    protected function tearDown(): void
    {
        $refs = ReconRunGroup::query()->acrossBranches()->where('Note', self::NAME)->pluck('GroupRef');

        if ($refs->isNotEmpty()) {
            $runs = ReconRun::query()->acrossBranches()->whereIn('GroupRef', $refs)->pluck('Id');

            if ($runs->isNotEmpty()) {
                DB::connection(config('agora.connections.app'))
                    ->table(config('agora.schema').'.ReconRunLine')
                    ->whereIn('RunId', $runs)->delete();
            }

            ReconRun::query()->acrossBranches()->whereIn('GroupRef', $refs)->delete();
            ReconRunGroup::query()->acrossBranches()->whereIn('GroupRef', $refs)->delete();
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    /** The form asks for a period and pointedly not for a site. */
    public function test_the_form_asks_for_a_period_and_no_site(): void
    {
        $this->actingAs($this->admin())->get('/app/recon/auto/ABSA/all')
            ->assertOk()
            ->assertSee('Run every site')
            ->assertSee('trading')
            // The site is what this screen exists to remove.
            ->assertDontSee('name="branch_id"', false);
    }

    /**
     * "Every site" means nothing where the workspace is one site.
     *
     * A branch user reaching it would get a group of exactly the branch they
     * are already on, which is the Auto tab with more steps.
     */
    public function test_a_branch_workspace_is_refused_rather_than_shown_a_group_of_one(): void
    {
        $this->actingAs($this->admin())->get('/app/recon/auto/ABSA/all?ws=branch&branch=18')
            ->assertForbidden();
    }

    /** The tab is offered in head office and withheld in a branch workspace. */
    public function test_the_tab_appears_only_in_head_office(): void
    {
        $this->actingAs($this->admin())->get('/app/recon/auto/ABSA')
            ->assertOk()
            ->assertSee('Every site');

        $this->actingAs($this->admin())->get('/app/recon/auto/ABSA?ws=branch&branch=18')
            ->assertOk()
            ->assertDontSee('Every site');
    }

    /**
     * Starting a group records the intention and sends the operator to it.
     *
     * Nothing is previewed by the POST: the group is what was meant to happen,
     * and the screen carries it out one site at a time. That is what makes a
     * browser which gave up half way leave something resumable.
     */
    public function test_starting_a_group_records_what_was_meant_to_happen(): void
    {
        $response = $this->actingAs($this->admin())->post('/app/recon/auto/ABSA/all', [
            'area' => 'ABSA',
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'note' => self::NAME,
        ]);

        $group = ReconRunGroup::query()->acrossBranches()->where('Note', self::NAME)->firstOrFail();

        $response->assertRedirect(route('app.recon.group', $group->GroupRef));

        $this->assertSame('running', $group->Status);
        $this->assertGreaterThan(1, $group->BranchCount, 'A group covers every trading site.');
        $this->assertSame(0, $group->CompletedCount);
        // The group carries the group entity, never one of its sites.
        $this->assertSame((int) config('agora.group_branch_id'), $group->BranchId);
        $this->assertSame(0, $group->runs()->count(), 'The POST records the intention and previews nothing.');
    }

    /**
     * A site that cannot run is a ROW saying why, not an empty result.
     *
     * On the live system twenty-four of twenty-six branches produce nothing
     * and the screen never says why (finding 1). In a group that distinction
     * is the entire answer, so a refusal is recorded as a run with a code on
     * it and rendered as its own state.
     */
    public function test_a_site_with_no_criteria_row_is_recorded_as_a_refusal(): void
    {
        $group = $this->startGroup();
        $site = $this->siteWithoutCriteria($group);

        if ($site === null) {
            $this->markTestSkipped('Every trading site has a criteria row on this instance.');
        }

        $this->actingAs($this->admin())
            ->post(route('app.recon.group.branch', [$group->GroupRef, $site]))
            ->assertOk()
            ->assertSee('NO_CRITERIA');

        $run = $group->runs()->where('BranchId', $site)->firstOrFail();

        $this->assertSame('failed', $run->Status);
        $this->assertSame('NO_CRITERIA', $run->FailureCode);
        $this->assertNotNull($run->FailureMessage);

        // And the group counted it as answered rather than as still pending.
        $this->assertSame(1, $group->fresh()?->FailedCount);
    }

    /** Asking for the same site twice does not preview it twice. */
    public function test_running_a_site_is_idempotent(): void
    {
        $group = $this->startGroup();
        $site = $this->siteWithoutCriteria($group) ?? 18;

        foreach ([1, 2] as $ignored) {
            $this->actingAs($this->admin())
                ->post(route('app.recon.group.branch', [$group->GroupRef, $site]))
                ->assertOk();
        }

        $this->assertSame(1, $group->runs()->where('BranchId', $site)->count());
    }

    /** The group screen lists every site in scope, previewed or not. */
    public function test_the_group_screen_lists_every_site(): void
    {
        $group = $this->startGroup();

        $this->actingAs($this->admin())->get(route('app.recon.group', $group->GroupRef))
            ->assertOk()
            ->assertSee('every site')
            ->assertSee('still to preview')
            ->assertSee('Waiting…');
    }

    /** Posting nothing posts nothing, and says so. */
    public function test_posting_with_nothing_ticked_is_refused(): void
    {
        $group = $this->startGroup();

        $this->actingAs($this->admin())
            ->from(route('app.recon.group', $group->GroupRef))
            ->post(route('app.recon.group.execute', $group->GroupRef), ['runs' => []])
            ->assertRedirect(route('app.recon.group', $group->GroupRef))
            ->assertSessionHas('refusal');

        $this->assertNull($group->fresh()?->CommittedAt);
    }

    private function startGroup(): ReconRunGroup
    {
        $this->actingAs($this->admin())->post('/app/recon/auto/ABSA/all', [
            'area' => 'ABSA',
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'note' => self::NAME,
        ])->assertRedirect();

        return ReconRunGroup::query()->acrossBranches()->where('Note', self::NAME)->firstOrFail();
    }

    /**
     * A trading site with no ABSA criteria row — the case this screen exists
     * to make visible. Null where every site has one.
     */
    private function siteWithoutCriteria(ReconRunGroup $group): ?int
    {
        $configured = DB::connection(config('agora.connections.app'))
            ->table(config('agora.schema').'.vw_AutoReconCriteria')
            ->where('BankReconArea', 'ABSA')
            ->pluck('BranchId')
            ->map(fn (mixed $id) => (int) $id)
            ->all();

        $sites = Branch::query()->acrossBranches()
            ->where('IsActive', true)->where('IsTrading', true)
            ->pluck('BranchId')->map(fn (mixed $id) => (int) $id)->all();

        return collect($sites)->first(fn (int $id) => ! in_array($id, $configured, true));
    }
}
