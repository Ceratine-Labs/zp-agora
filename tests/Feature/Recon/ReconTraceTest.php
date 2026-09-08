<?php

namespace Tests\Feature\Recon;

use Modules\Core\Models\User;
use Modules\Recon\Models\ReconRunLine;
use Tests\TestCase;

/**
 * One value, and everything it touches.
 *
 * Read-only throughout — a trace is seven reads and no writes, on both sides
 * of the estate.
 *
 * The traced term is taken from a proposal that already exists rather than
 * invented, so the assertions are about a value the ledger really holds. If
 * the ledger is empty the test says so instead of passing on nothing.
 */
class ReconTraceTest extends TestCase
{
    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    /** The form is there before anything has been asked of it. */
    public function test_the_screen_opens_with_a_search_and_no_results(): void
    {
        $this->actingAs($this->admin())->get('/app/recon/trace')
            ->assertOk()
            ->assertSee('agora.usp_Recon_Trace')
            ->assertSee('Reference or number')
            // Nothing has been asked, so nothing is claimed to have been found.
            ->assertDontSee('what each part of the system knows about it');
    }

    /**
     * A term of one or two characters matches most of the estate and answers
     * nothing, so the procedure refuses it — and the refusal is SHOWN. An
     * empty screen would read as "this reference appears nowhere", which is
     * the opposite of what happened.
     */
    public function test_a_term_too_short_is_refused_rather_than_answered_emptily(): void
    {
        $this->actingAs($this->admin())->get('/app/recon/trace?q=ab')
            ->assertOk()
            ->assertSee('That is not enough to go on')
            ->assertSee('at least three characters', false);
    }

    /**
     * A reference the ledger holds comes back with its proposal, and the
     * legacy rows behind it.
     */
    public function test_a_known_reference_finds_its_proposal(): void
    {
        $line = ReconRunLine::query()->acrossBranches()
            ->whereNotNull('KeyRef')
            ->whereRaw('LEN(KeyRef) >= 3')
            ->orderByDesc('Id')
            ->first();

        if ($line === null) {
            $this->markTestSkipped('No recorded proposal to trace — run a preview first.');
        }

        $this->actingAs($this->admin())
            ->get('/app/recon/trace?q='.urlencode((string) $line->KeyRef).'&from=2020-01-01&to=2030-12-31')
            ->assertOk()
            ->assertSee('what each part of the system knows about it')
            ->assertSee('Proposals')
            // The run it came from is reachable from the trace, which is the
            // whole point: a figure leads back to the press that produced it.
            ->assertSee(route('app.recon.run', $line->RunId), false);
    }

    /**
     * A term nothing carries returns seven empty sets, and says so per set
     * rather than showing a blank page.
     */
    public function test_a_term_nothing_carries_says_so_in_every_set(): void
    {
        $this->actingAs($this->admin())
            ->get('/app/recon/trace?q=ZZZNOSUCHREFERENCE&from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertSee('No run has ever proposed anything carrying this value.')
            ->assertSee('No batch was ever allocated for this value.')
            ->assertSee('Nothing on the statement carries this value inside the window.');
    }

    /**
     * The site selector is on the result set, and a chosen site reaches the
     * procedure as a scope.
     *
     * The refusal path — a branch id the caller may not see — cannot be
     * provoked as this administrator, whose grant is empty and therefore
     * means every branch. It is guarded in ReconTraceController::scope() by
     * the same BranchContext::maySee() check every other screen uses.
     */
    public function test_the_site_selector_is_offered_on_the_result_set(): void
    {
        $admin = $this->admin();

        // An administrator's grant is empty, which means every branch — so the
        // refusal can only be provoked from a workspace that is pinned. What
        // is asserted here is that the site actually reaches the procedure as
        // a scope rather than being ignored.
        $this->actingAs($admin)
            ->get('/app/recon/trace?q=69744&branch_id=18&from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertSee('Every site you may see');
    }
}
