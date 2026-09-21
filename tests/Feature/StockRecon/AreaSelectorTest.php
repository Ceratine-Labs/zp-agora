<?php

namespace Tests\Feature\StockRecon;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\StockRecon\Services\StockReconService;
use Tests\TestCase;

/**
 * Which counting areas a site offers.
 *
 * STK_Area keeps every area a site has ever had. The three shift bits are what
 * say whether it is still counted, and most of the list fails them — Esikhawini
 * Convenience carries 18 areas and counts 9; estate-wide it is 198 of 250. An
 * area with no shift ticked cannot produce a proposal, so offering it is
 * offering a run that comes back empty for a reason nobody can see.
 *
 * THE TRAP THIS FILE EXISTS FOR is the second test. 'NOT USED' is an AreaGroup
 * the customer types into their own configuration, and it is not a statement
 * about whether the area is counted: 27 of its 31 areas estate-wide are on a
 * shift, including Esikhawini's High Shrinkage Items OK on days and nights.
 * Filtering on that name instead of on the flags would hide areas people are
 * counting today, and it would look right while doing it.
 */
class AreaSelectorTest extends TestCase
{
    private const BRANCH = 999;

    private StockReconService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->service = new StockReconService(new ProcedureService);
        $this->cleanUp();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    public function test_only_areas_on_a_shift_are_offered(): void
    {
        $offered = $this->service->areas([self::BRANCH])->pluck('AreaDescription')->all();

        $this->assertContains('TEST-Days only', $offered);
        $this->assertContains('TEST-Nights only', $offered);
        $this->assertContains('TEST-Afternoons only', $offered);

        $this->assertNotContains('TEST-No shift ticked', $offered,
            'An area with no shift can only produce an empty run — it must not be offered.');
    }

    /**
     * The group is a label the customer types, not a flag. Reading it as one
     * hides areas that are counted every day.
     */
    public function test_an_area_grouped_not_used_is_still_offered_when_it_is_on_a_shift(): void
    {
        $offered = $this->service->areas([self::BRANCH])->pluck('AreaDescription')->all();

        $this->assertContains('TEST-Called NOT USED but counted', $offered,
            "'NOT USED' is an AreaGroup, not a statement about whether the area is counted.");
    }

    /** The configured exclusions still apply, and they are by group. */
    public function test_an_excluded_group_is_still_excluded(): void
    {
        config(['stockrecon.excluded_area_groups' => ['Virtual Items']]);

        $this->assertNotContains(
            'TEST-Virtual and on a shift',
            $this->service->areas([self::BRANCH])->pluck('AreaDescription')->all(),
            'Virtual items have no physical count and are excluded by group, shift or no shift.'
        );
    }

    /** Areas belong to a site; another site's must never appear. */
    public function test_areas_are_scoped_to_the_sites_asked_for(): void
    {
        $this->assertSame([], $this->service->areas([])->all());

        foreach ($this->service->areas([self::BRANCH]) as $area) {
            $this->assertSame(self::BRANCH, (int) $area->BranchId);
        }
    }

    /**
     * THE SCREEN ACTUALLY RENDERS, and the Counting area field is on it.
     *
     * On 17 September 2026 it did not. The explanatory comment added beside
     * the label carried a pair of double quotes, and it sat INSIDE
     * :choices="…" — an HTML attribute delimited by double quotes. The first
     * quote in the prose ended the attribute and the rest of the expression
     * rendered as text on the page, taking the whole field with it.
     *
     * Every gate passed: pint, phpstan, check-blades, check-components and four
     * unit tests on areas() itself, because none of them renders the view. It
     * shipped to live and Ryan found it. This is the assertion that was
     * missing — not "is the data right" but "does the form come back".
     */
    public function test_the_form_renders_with_a_counting_area_field(): void
    {
        $this->actingAs($this->admin());

        $page = $this->get(route('app.stockrecon.index'))->assertOk();

        $page->assertSee('Counting area', false);
        $page->assertSee('name="area_no"', false);
        $page->assertSee('Every area at this site', false);

        // The tells of an attribute that ended early. Any of these on the page
        // means the expression leaked instead of being evaluated.
        foreach (['->all()"', '$a->AreaDescription', '->concat($areas', 'collect(['] as $leak) {
            $page->assertDontSee($leak, false);
        }
    }

    private function admin(): User
    {
        $user = User::query()->acrossBranches()
            ->where('EmailAddress', config('agora.e2e.email'))->first();

        if (! $user) {
            $this->markTestSkipped('No E2E fixture user — run the E2eFixtureSeeder.');
        }

        return $user;
    }

    private function seedFixture(): void
    {
        $rows = [
            //  desc                              group             D  A  N
            ['TEST-Days only',                    'Food Items',     1, 0, 0],
            ['TEST-Afternoons only',              'Retail Items',   0, 1, 0],
            ['TEST-Nights only',                  'Retail Items',   0, 0, 1],
            ['TEST-No shift ticked',              'Retail Items',   0, 0, 0],
            ['TEST-Called NOT USED but counted',  'NOT USED',       1, 0, 1],
            ['TEST-Virtual and on a shift',       'Virtual Items',  1, 0, 1],
        ];

        foreach ($rows as $i => [$desc, $group, $d, $a, $n]) {
            $this->db()->table('PumpIT.dbo.STK_Area')->insert([
                'SSBranchId' => self::BRANCH, 'AreaNo' => 900 + $i,
                'AreaDescription' => $desc, 'AreaGroup' => $group,
                'DayShift' => $d, 'AfternoonShift' => $a, 'NightShift' => $n,
                'ShowReport' => 1, 'IsCaptureWaste' => 0,
            ]);
        }
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }

    private function cleanUp(): void
    {
        $this->db()->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::BRANCH)->delete();
    }
}
