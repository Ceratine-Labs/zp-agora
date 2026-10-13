<?php

namespace Tests\Feature\Recon;

use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * The bank recon dashboard (Ryan, 13 Oct 2026): what is outstanding, a
 * summary and a per-site view, every site by default and several at once.
 *
 * Read-only throughout. The figures are checked against each other rather
 * than against invented numbers: the summary procedure and the grid procedure
 * are two readings of one definition of outstanding, and they must agree to
 * the cent.
 */
class ReconDashboardTest extends TestCase
{
    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    /** @return Collection<int, object> */
    private function summary(array $params = []): Collection
    {
        return app(ProcedureService::class)->call('usp_Recon_DashboardOutstanding', $params + ['AsAt' => '2026-10-13']);
    }

    /** The summary and the rows behind it are the same set, count and amount. */
    public function test_the_summary_and_the_items_agree_to_the_cent(): void
    {
        $summary = $this->summary();

        if ($summary->isEmpty()) {
            $this->markTestSkipped('Nothing outstanding in this database.');
        }

        $grid = fn (?string $area) => app(ProcedureService::class)->callSets('usp_Recon_GridOutstanding',
            ['AsAt' => '2026-10-13', 'PageSize' => 1, 'ReconArea' => $area]);

        // Every classified area, as the headline figures are; Unclassified by name.
        foreach ([null => $summary->where('ReconArea', '!=', 'Unclassified'),
            'Unclassified' => $summary->where('ReconArea', 'Unclassified')] as $area => $expected) {
            $sets = $grid($area === '' ? null : $area);
            $this->assertSame((int) $expected->sum('Rows'), (int) $sets[1]->first()->TotalRows);
            $this->assertEqualsWithDelta((float) $expected->sum(fn ($r) => (float) $r->Amount), (float) $sets[2]->first()->Amount, 0.005);
        }

        foreach ($summary as $row) {
            $buckets = $row->Age7Rows + $row->Age30Rows + $row->Age90Rows + $row->Age365Rows + $row->AgeOverRows;
            $this->assertSame((int) $row->Rows, (int) $buckets, 'Every item falls in exactly one age bucket.');
        }
    }

    /** Selecting sites narrows to exactly those sites. */
    public function test_selected_sites_narrow_the_figures(): void
    {
        $site = (int) ($this->summary()->first()->BranchId ?? 0);

        if ($site === 0) {
            $this->markTestSkipped('Nothing outstanding in this database.');
        }

        $one = $this->summary(['BranchIds' => (string) $site]);

        $this->assertNotEmpty($one);
        $this->assertSame([$site], $one->pluck('BranchId')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $this->assertTrue($this->summary(['BranchIds' => (string) $site, 'AllowedBranchIds' => '999999'])->isEmpty(),
            'A site outside the grant is not read, whatever was selected.');
    }

    /** The three views render, each with the procedure it reads. */
    public function test_the_summary_per_site_and_items_views_render(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('app.recon.dashboard'))
            ->assertOk()
            ->assertSee('What is outstanding')
            ->assertSee('Bank lines outstanding')
            ->assertSee('PumpIT deposits outstanding')
            ->assertSee('agora.usp_Recon_DashboardOutstanding')
            ->assertSee('name="branches[]"', false);

        $site = (int) ($this->summary()->first()->BranchId ?? 0);

        if ($site !== 0) {
            $this->actingAs($admin)->get(route('app.recon.dashboard', ['view' => 'sites', 'branches' => [$site]]))
                ->assertOk()
                ->assertSee('0–7 days')
                ->assertSee('Over a year');
        }

        $this->actingAs($admin)->get(route('app.recon.dashboard', ['view' => 'items', 'side' => 'PumpIT', 'area' => 'FNB']))
            ->assertOk()
            ->assertSee('Outstanding items')
            ->assertSee('agora.usp_Recon_GridOutstanding')
            ->assertSee('PumpIT side of');
    }

    /** It is on the menu, under Bank reconciliation. */
    public function test_the_dashboard_is_under_bank_recon_on_the_menu(): void
    {
        $item = MenuItem::query()->acrossBranches()
            ->where('RouteName', 'app.recon.dashboard')->first();

        if ($item === null) {
            $this->markTestSkipped('Menu not seeded — run seed:master.');
        }

        $parent = MenuItem::query()->acrossBranches()->find($item->ParentId);
        $this->assertSame('money/bank-recon', $parent?->Path);
    }
}
