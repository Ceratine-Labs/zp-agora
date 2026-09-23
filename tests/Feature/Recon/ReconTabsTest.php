<?php

namespace Tests\Feature\Recon;

use Modules\Core\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One reconciliation area is four screens, and the strip is the map of them.
 *
 * Read-only throughout: it signs in as the seeded administrator and asks for
 * pages. Nothing here previews, so nothing is written — a tab test that ran a
 * procedure against the customer's estate to prove a heading exists would be
 * paying an absurd price for the answer.
 *
 * What is actually being guarded is the pairing of three things that have to
 * agree and live in three files: the tab catalogue on ReconController, the
 * routes behind it, and the panes it includes. A tab whose route name is wrong
 * is a RouteNotFoundException on a screen that used to work, and it would not
 * be caught by anything else in the suite.
 */
class ReconTabsTest extends TestCase
{
    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    /**
     * Keyed, so a failure names the pane rather than an index — "with data set
     * 'config'" is the difference between a useful failure and a puzzle.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function tabs(): array
    {
        return [
            'auto' => ['/app/recon/auto/ABSA', 'Rules and readings'],
            'match' => ['/app/recon/auto/ABSA/match', 'What to pair'],
            'all' => ['/app/recon/auto/ABSA/all', 'Run every site'],
            'runs' => ['/app/recon/auto/ABSA/runs', 'Whose runs'],
            'config' => ['/app/recon/auto/ABSA/config', 'Extraction configuration'],
        ];
    }

    /**
     * Every tab answers, and answers with its OWN pane.
     *
     * The second assertion is the one that matters: all four routes render the
     * same blade, so a wrong `$tab` would give four identical 200s and a test
     * that only checked the status code would pass through it.
     */
    #[DataProvider('tabs')]
    public function test_each_tab_renders_its_own_pane(string $url, string $marker): void
    {
        $this->actingAs($this->admin())->get($url)
            ->assertOk()
            ->assertSee($marker);
    }

    /**
     * The strip is the whole map of the area from every one of its tabs.
     *
     * Four of them — "Every site" joined with the master controller and is
     * head-office only, which is why this signs in as an administrator without
     * pinning a workspace. Manual match and Suggestions are no longer on it
     * (23 Sep 2026): they are tabs INSIDE the recon centre, and a second door
     * to them here is how a clerk ends up looking at two scopes.
     */
    public function test_the_strip_carries_every_tab_on_every_tab(): void
    {
        foreach (array_column(self::tabs(), 0) as $url) {
            $response = $this->actingAs($this->admin())->get($url);

            foreach (['Recon centre', 'Every site', 'Runs', 'Configuration'] as $label) {
                $response->assertSee($label);
            }

            // Link mode, not panel mode: a tab is somewhere you can send
            // someone. Panel mode would render `data-tabs` and no anchors.
            $response->assertSee('href="'.route('app.recon.runs', 'ABSA').'"', false);
            $response->assertDontSee('href="'.route('app.recon.match', 'ABSA').'"', false);
        }
    }

    /** The site and the dates travel with every tab on the strip. */
    public function test_the_strip_carries_the_scope(): void
    {
        $scope = ['branch_id' => 18, 'from' => '2026-08-01', 'to' => '2026-08-31'];

        $this->actingAs($this->admin())->get(route('app.recon.runs', ['ABSA'] + $scope))
            ->assertOk()
            ->assertSee('href="'.e(route('app.recon.area', ['ABSA'] + $scope)).'"', false)
            ->assertSee('href="'.e(route('app.recon.config', ['ABSA'] + $scope)).'"', false);
    }

    /**
     * The area URL the menu and every existing link point at is the recon
     * centre, asking for its scope — and asking for it on the preview form, so
     * choosing a site and dates IS running the automatic balancing.
     */
    public function test_the_bare_area_url_is_the_centre_asking_for_its_scope(): void
    {
        $this->actingAs($this->admin())->get('/app/recon/auto/ABSA')
            ->assertOk()
            ->assertSee('What to reconcile')
            ->assertSee('Rules and readings')
            ->assertSee('name="centre" value="1"', false)
            ->assertSee('nothing in PumpIT changes until');
    }
}
