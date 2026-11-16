<?php

namespace Tests\Feature\Recon;

use App\Exceptions\AgoraProcException;
use App\Support\BusinessDate;
use App\Support\ProcedureService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\User;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\PermissionService;
use Modules\Recon\Services\ReconDashboardService;
use Modules\Recon\Services\ReconPivot;
use Modules\Recon\Services\ReconService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * The bank recon dashboard (Ryan, 13 Oct 2026): what is outstanding, a
 * summary and a per-site view, every site by default and several at once.
 *
 * Read-only throughout. The figures are checked against each other rather
 * than against invented numbers: the summary procedure and the grid procedure
 * are two readings of one definition of outstanding, and they must agree to
 * the cent.
 *
 * The Pivot (16 Nov 2026) adds three result sets and a second procedure, and
 * the tests below hold them to the same standard: the sets tie to set 1, the
 * tiles tie to each other and to the Items grid, a cell's link opens exactly
 * the rows behind its figure, and every mode renders. Ages count to today, as
 * the page does, so these read as at BusinessDate::today().
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

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, object>
     */
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

    private function asAt(): string
    {
        return BusinessDate::today()->toDateString();
    }

    private static function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    /**
     * The last day anything outstanding is dated, as at today: a window
     * ending there has rows to show, whatever day the data stops.
     */
    private function lastDay(): ?string
    {
        $newest = $this->summary(['AsAt' => $this->asAt()])->max('NewestDate');

        return $newest === null ? null : min(substr((string) $newest, 0, 10), $this->asAt());
    }

    /** The four sets of the pivot's procedure come back in order and tie to set 1. */
    public function test_the_four_result_sets_come_back_in_order_and_tie_out(): void
    {
        $sets = app(ProcedureService::class)->callSets('usp_Recon_DashboardOutstanding', ['AsAt' => $this->asAt(), 'Grain' => 'month']);

        $this->assertCount(4, $sets, 'Rows, sites, coverage, periods — always all four, in that order.');
        [$rows, $sites, $coverage, $periods] = $sets;

        if ($rows->isEmpty()) {
            $this->markTestSkipped('Nothing outstanding in this database.');
        }

        // Set 1: the eighteen columns every caller read before the pivot, in
        // their order, and the four the pivot appended after them.
        $this->assertSame([
            'BranchId', 'BranchName', 'ReconArea', 'Side', 'Rows', 'Amount', 'OldestDate', 'NewestDate',
            'Age7Rows', 'Age7Amount', 'Age30Rows', 'Age30Amount', 'Age90Rows', 'Age90Amount',
            'Age365Rows', 'Age365Amount', 'AgeOverRows', 'AgeOverAmount',
            'TransitRows', 'TransitAmount', 'CreditRows', 'CreditAmount',
        ], array_keys((array) $rows->first()));

        // Set 2 names every site set 1 does.
        $named = $sites->pluck('BranchId')->map(fn ($id) => (int) $id)->all();
        foreach ($rows->pluck('BranchId')->unique() as $id) {
            $this->assertContains((int) $id, $named, "Site {$id} is outstanding but not in the site set.");
        }

        // Set 3: one row per classified area.
        $this->assertEqualsCanonicalizing(ReconPivot::AREAS, $coverage->pluck('ReconArea')->all());

        // Transit is PumpIT's alone, and never more than the rows.
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual((int) $row->Rows, (int) $row->TransitRows);
            if ($row->Side === 'Bank' || $row->ReconArea === 'Unclassified') {
                $this->assertSame(0, (int) $row->TransitRows);
                $this->assertSame(0, self::cents($row->TransitAmount));
            }
        }

        // Set 4 by month over all time adds up to set 1, per site × area × side, to the cent.
        $one = [];
        foreach ($rows->where('ReconArea', '!=', 'Unclassified') as $row) {
            $one["{$row->BranchId}|{$row->ReconArea}|{$row->Side}"] = [(int) $row->Rows, self::cents($row->Amount),
                (int) $row->TransitRows, self::cents($row->TransitAmount)];
        }
        $four = [];
        foreach ($periods as $p) {
            $key = "{$p->BranchId}|{$p->ReconArea}|{$p->Side}";
            $four[$key] ??= [0, 0, 0, 0];
            $four[$key][0] += (int) $p->Rows;
            $four[$key][1] += self::cents($p->Amount);
            $four[$key][2] += (int) $p->TransitRows;
            $four[$key][3] += self::cents($p->TransitAmount);
        }
        ksort($one);
        ksort($four);
        $this->assertSame($one, $four);
    }

    /** Day columns cover the window and nothing else; past 92 days they are refused. */
    public function test_day_grain_matches_the_window_and_93_days_is_refused(): void
    {
        $to = $this->lastDay();

        if ($to === null) {
            $this->markTestSkipped('Nothing outstanding in this database.');
        }

        $from = Carbon::parse($to)->subDays(6)->toDateString();
        $call = fn (string $from, string $to) => app(ProcedureService::class)->callSets('usp_Recon_DashboardOutstanding',
            ['AsAt' => $this->asAt(), 'DateFrom' => $from, 'DateTo' => $to, 'Grain' => 'day']);
        [$rows, , , $periods] = $call($from, $to);

        $this->assertNotEmpty($periods, 'A week ending on the last day with rows has rows by day.');
        $days = [];
        foreach ($periods as $p) {
            $day = substr((string) $p->PeriodStart, 0, 10);
            $this->assertTrue($day >= $from && $day <= $to, "{$day} is outside {$from} – {$to}.");
            $days["{$p->BranchId}|{$p->ReconArea}|{$p->Side}"] = ($days["{$p->BranchId}|{$p->ReconArea}|{$p->Side}"] ?? 0) + self::cents($p->Amount);
        }
        foreach ($rows->where('ReconArea', '!=', 'Unclassified') as $row) {
            $this->assertSame(self::cents($row->Amount), $days["{$row->BranchId}|{$row->ReconArea}|{$row->Side}"] ?? 0);
        }

        // 92 days is the ceiling; 93 is a refusal, not a slow answer.
        $call(Carbon::parse($to)->subDays(91)->toDateString(), $to);
        try {
            $call(Carbon::parse($to)->subDays(92)->toDateString(), $to);
            $this->fail('Day columns over 93 days were not refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('GRAIN_TOO_FINE', $e->code());
        }
    }

    /** The feed history covers every outstanding cell, and its rules are the extraction rules. */
    public function test_feeds_cover_every_outstanding_cell_and_rules_match_the_criteria(): void
    {
        $rows = $this->summary(['AsAt' => $this->asAt()])->where('ReconArea', '!=', 'Unclassified');

        if ($rows->isEmpty()) {
            $this->markTestSkipped('Nothing outstanding in this database.');
        }

        [$sides, $rules] = app(ProcedureService::class)->callSets('usp_Recon_DashboardFeeds', ['AsAt' => $this->asAt()]);
        $feed = $sides->keyBy(fn ($f) => "{$f->BranchId}|{$f->ReconArea}|{$f->Side}");

        foreach ($rows as $row) {
            $f = $feed["{$row->BranchId}|{$row->ReconArea}|{$row->Side}"] ?? null;
            $this->assertNotNull($f, "No feed history for {$row->BranchName} {$row->ReconArea} {$row->Side}.");
            $this->assertGreaterThanOrEqual((int) $row->Rows, (int) $f->Rows);
            $this->assertLessThanOrEqual(substr((string) $row->OldestDate, 0, 10), substr((string) $f->FirstDate, 0, 10));
            $this->assertGreaterThanOrEqual(substr((string) $row->NewestDate, 0, 10), substr((string) $f->LastDate, 0, 10));
        }

        $inScope = $rules->pluck('BranchId')->map(fn ($id) => (int) $id)->unique()->all();
        foreach (ReconPivot::AREAS as $area) {
            $expected = array_values(array_intersect(app(ReconService::class)->branchesWithRules($area), $inScope));
            $actual = $rules->where('ReconArea', $area)->filter(fn ($r) => (int) $r->Rules > 0)
                ->pluck('BranchId')->map(fn ($id) => (int) $id)->values()->all();
            $this->assertEqualsCanonicalizing($expected, $actual, "Rules for {$area}.");
        }
    }

    /** A pivot cell's link opens exactly the rows behind its figure. */
    public function test_a_cells_link_opens_exactly_the_rows_behind_it(): void
    {
        $asAt = $this->asAt();
        $figures = app(ReconDashboardService::class)->figures([], [], null, null, $asAt, true);
        $pivot = new ReconPivot($figures, null, [], null, null, $asAt);
        $link = fn (array $q) => '?'.http_build_query(array_filter($q, fn ($v) => $v !== null));
        $amounts = $pivot->areas('amount', $link);
        $counts = $pivot->areas('count', $link);

        $checked = 0;
        foreach ($amounts['rows'] as $i => $row) {
            foreach (ReconPivot::AREAS as $area) {
                foreach (ReconPivot::SIDES as $side) {
                    $cell = $row['cells']["{$area}.{$side}"];
                    if ($cell['href'] === null || $checked >= 5) {
                        continue;
                    }
                    parse_str((string) parse_url($cell['href'], PHP_URL_QUERY), $q);
                    $this->assertEquals(['view' => 'items', 'branches' => [(string) $row['id']], 'area' => $area, 'side' => $side],
                        array_intersect_key($q, array_flip(['view', 'branches', 'area', 'side'])));
                    $sets = app(ProcedureService::class)->callSets('usp_Recon_GridOutstanding', [
                        'AsAt' => $asAt, 'PageSize' => 1, 'BranchIds' => implode(',', $q['branches']),
                        'ReconArea' => $q['area'], 'Side' => $q['side'], 'DateFrom' => $q['from'] ?? null, 'DateTo' => $q['to'] ?? null,
                    ]);
                    $this->assertSame((int) $counts['rows'][$i]['cells']["{$area}.{$side}"]['sort'], (int) $sets[1]->first()->TotalRows);
                    $this->assertSame(self::cents($cell['sort']), self::cents($sets[2]->first()->Amount));
                    $checked++;
                }
            }
        }

        if ($checked === 0) {
            $this->markTestSkipped('Nothing outstanding in this database.');
        }
    }

    /** The tiles add up to each other, to the totals row and to the Items grid, to the cent. */
    public function test_the_tiles_tie_out(): void
    {
        $asAt = $this->asAt();
        $figures = app(ReconDashboardService::class)->figures([], [], null, null, $asAt, true);
        $pivot = new ReconPivot($figures, null, [], null, null, $asAt);
        $t = $pivot->totals();

        if ($t['bank_rows'] + $t['pumpit_rows'] === 0) {
            $this->markTestSkipped('Nothing outstanding in this database.');
        }

        // Bank = on both sides + not declared; PumpIT = on both sides + not in bank + in transit.
        $this->assertSame(self::cents($t['bank_amount']), self::cents($t['both']) + self::cents($t['over']));
        $this->assertSame(self::cents($t['pumpit_amount']), self::cents($t['both']) + self::cents($t['short']) + self::cents($t['wait']));

        $grid = fn (?string $side, ?string $area = null) => app(ProcedureService::class)->callSets('usp_Recon_GridOutstanding',
            ['AsAt' => $asAt, 'PageSize' => 1, 'Side' => $side, 'ReconArea' => $area]);
        foreach (['Bank' => 'bank', 'PumpIT' => 'pumpit'] as $side => $key) {
            $sets = $grid($side);
            $this->assertSame($t[$key.'_rows'], (int) $sets[1]->first()->TotalRows, "{$side} rows.");
            $this->assertSame(self::cents($t[$key.'_amount']), self::cents($sets[2]->first()->Amount), "{$side} amount.");
        }
        $loose = $grid(null, 'Unclassified');
        $this->assertSame($t['unclassified_rows'], (int) $loose[1]->first()->TotalRows, 'Unclassified lines.');

        // The totals row is the tiles, and the Difference block is tiles 3 to 5 and the transit note.
        $areas = $pivot->areas('amount');
        $difference = $pivot->areas('difference');
        if ($areas['total'] !== null) {
            $this->assertSame(self::cents($t['bank_amount']), self::cents($areas['total']['cells']['AllBank']['sort']));
            $this->assertSame(self::cents($t['pumpit_amount']), self::cents($areas['total']['cells']['AllPumpIT']['sort']));
            foreach (['NotInBank' => 'short', 'NotDeclared' => 'over', 'Both' => 'both', 'Transit' => 'wait'] as $cell => $key) {
                $this->assertSame(self::cents($t[$key]), self::cents($difference['total']['cells'][$cell]['sort']), $cell);
            }
        }
    }

    /** Every mode of the page renders: the pivot's lenses and dates, Summary's totals, Per site's Work. */
    public function test_every_mode_of_the_pivot_renders(): void
    {
        $admin = $this->admin();
        $to = $this->lastDay();

        $this->actingAs($admin)->get(route('app.recon.dashboard'))
            ->assertOk()
            ->assertSee('Outstanding by site')
            ->assertSee('class="dt dense fit pin-first rp-pivot"', false)
            ->assertSee('data-tt-row-noun="site"', false)
            ->assertSee('data-tt-no-filter', false)
            ->assertSee('data-tt-pill-order="'.ReconPivot::PILL_ORDER.'"', false)
            ->assertSee('Not in bank')
            ->assertSee('On both sides')
            ->assertSee('Unclassified bank lines')
            ->assertSee('ABSA bank')
            ->assertSee('Smart ATM PumpIT')
            ->assertSee('agora.usp_Recon_DashboardFeeds');

        $this->actingAs($admin)->get(route('app.recon.dashboard', ['lens' => 'count']))
            ->assertOk()->assertSee('Items outstanding per area');
        $this->actingAs($admin)->get(route('app.recon.dashboard', ['lens' => 'difference', 'status' => 'Not in bank']))
            ->assertOk()->assertSee('Not in bank, last 30 days')->assertSee('data-tt-pills="Not in bank"', false);

        if ($to === null) {
            $this->markTestSkipped('Nothing outstanding in this database.');
        }

        // Dates over seven days: one column per day, headed "Mon 3".
        $from = Carbon::parse($to)->subDays(6);
        $page = $this->actingAs($admin)->get(route('app.recon.dashboard', ['cols' => 'dates', 'from' => $from->toDateString(), 'to' => $to]))
            ->assertOk()->assertSee('PumpIT deposits outstanding by the day they are dated');
        for ($d = $from->copy(); $d->toDateString() <= $to; $d = $d->addDay()) {
            $page->assertSee($d->format('D j'));
        }

        // Dates with no window: months, the last of them this one.
        $this->actingAs($admin)->get(route('app.recon.dashboard', ['cols' => 'dates', 'side' => 'Bank']))
            ->assertOk()->assertSee(Carbon::parse($this->asAt())->format('M y'))->assertSee('Bank lines outstanding by the month they are dated');

        $this->actingAs($admin)->get(route('app.recon.dashboard', ['view' => 'summary']))
            ->assertOk()->assertSee('All sites in scope (');

        $this->actingAs($admin)->get(route('app.recon.dashboard', ['view' => 'sites']))
            ->assertOk()->assertSee('/app/recon/auto/', false)->assertSee('In transit');
    }

    /** The pivot downloads: the table on screen as CSV and XLSX, and the long form. */
    public function test_the_pivot_exports(): void
    {
        $admin = $this->admin();

        $csv = $this->actingAs($admin)->get(route('app.recon.dashboard.export', ['format' => 'csv']));
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('ABSA bank', $csv->streamedContent());
        $this->assertStringContainsString('recon-pivot-areas-amount-', (string) $csv->headers->get('Content-Disposition'));

        $xlsx = $this->actingAs($admin)->get(route('app.recon.dashboard.export', ['format' => 'xlsx']));
        $xlsx->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::discard($xlsx);

        $long = $this->actingAs($admin)->get(route('app.recon.dashboard.export', ['format' => 'xlsx', 'kind' => 'long']));
        $long->assertOk();
        $this->assertStringContainsString('recon-pivot-long-form-', (string) $long->headers->get('Content-Disposition'));
        self::discard($long);
    }

    /**
     * A workbook is a temp file the download deletes once sent, and a test
     * never sends it: without this every run left one in /tmp, site names in it.
     *
     * @param  TestResponse<Response>  $response
     */
    private static function discard(TestResponse $response): void
    {
        $base = $response->baseResponse;
        if ($base instanceof BinaryFileResponse && is_file($base->getFile()->getPathname())) {
            unlink($base->getFile()->getPathname());
        }
    }

    /**
     * The admin's own row, in memory only, granted these sites instead —
     * never saved, so nothing about the real account changes.
     *
     * @param  list<int>  $grant
     */
    private function granted(array $grant): User
    {
        $proto = new class extends User
        {
            /** @var list<int> */
            public array $grantIds = [];

            /** @return array<int, int> */
            public function allowedBranchIds(): array
            {
                return $this->grantIds;
            }

            /** @param  array<string, mixed>  $options */
            public function save(array $options = []): bool
            {
                throw new \LogicException('A test user with a borrowed grant is never saved.');
            }
        };

        $user = $proto->newFromBuilder($this->admin()->getAttributes());
        $user->grantIds = $grant;

        return $user;
    }

    /**
     * Two sites with something classified outstanding today, and their names.
     *
     * @return array{0: int, 1: int, 2: array<int, string>}
     */
    private function twoSites(): array
    {
        $names = $this->summary(['AsAt' => $this->asAt()])->where('ReconArea', '!=', 'Unclassified')
            ->mapWithKeys(fn ($row) => [(int) $row->BranchId => (string) $row->BranchName])->all();

        if (count($names) < 2) {
            $this->markTestSkipped('Fewer than two sites with anything outstanding in this database.');
        }

        [$mine, $other] = array_slice(array_keys($names), 0, 2);

        return [$mine, $other, $names];
    }

    /** A grant holds on every view and in every file: no other site's name anywhere, whatever is asked for. */
    public function test_a_restricted_grant_sees_and_exports_only_its_own_site(): void
    {
        [$mine, $other, $names] = $this->twoSites();
        // A name inside the granted site's own name cannot be told apart from it.
        $others = array_filter(array_diff_key($names, [$mine => true]), fn (string $name) => ! str_contains($names[$mine], $name));
        $user = $this->granted([$mine]);

        foreach ([
            route('app.recon.dashboard'),
            route('app.recon.dashboard', ['branches' => [$other]]),
            route('app.recon.dashboard', ['lens' => 'difference', 'branches' => [$mine, $other]]),
            route('app.recon.dashboard', ['cols' => 'dates', 'side' => 'Bank']),
            route('app.recon.dashboard', ['view' => 'summary']),
            route('app.recon.dashboard', ['view' => 'sites', 'branches' => [$other]]),
            route('app.recon.dashboard.export', ['format' => 'csv', 'branches' => [$other]]),
            route('app.recon.dashboard.export', ['format' => 'csv', 'kind' => 'long']),
        ] as $url) {
            $response = $this->actingAs($user)->get($url)->assertOk();
            $body = $response->baseResponse instanceof StreamedResponse ? (string) $response->streamedContent() : (string) $response->getContent();

            foreach ($others as $name) {
                $this->assertStringNotContainsString($name, $body, "{$url} shows {$name}, which is outside the grant.");
            }
            $this->flushSession();
        }

        // A grant of nothing but ids that are no site fails closed: it is not "every site".
        $page = (string) $this->actingAs($this->granted([0]))->get(route('app.recon.dashboard'))->assertOk()->getContent();
        foreach ($names as $name) {
            $this->assertStringNotContainsString($name, $page, "A grant of site 0 shows {$name}.");
        }
    }

    /** The figures are cached per grant: a narrower grant reads its own, and the wider one's next load reads nothing. */
    public function test_the_cache_is_kept_per_grant(): void
    {
        [$mine] = $this->twoSites();
        $reads = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$reads): void {
            $procedure = (string) ($message->context['procedure'] ?? '');
            if ($message->message === 'agora.proc' && str_contains($procedure, 'Recon_Dashboard')) {
                $reads[] = $procedure;
            }
        });
        $all = $this->granted([]);

        $this->actingAs($all)->get(route('app.recon.dashboard'))->assertOk();
        $this->flushSession();
        $before = count($reads);
        $this->actingAs($this->granted([$mine]))->get(route('app.recon.dashboard'))->assertOk();
        $this->flushSession();
        $this->assertCount(2, array_slice($reads, $before), 'The narrower grant reads its own figures and feed history.');

        $before = count($reads);
        $this->actingAs($all)->get(route('app.recon.dashboard'))->assertOk();
        $this->assertSame([], array_slice($reads, $before), 'Every site is served from its own entries.');
    }

    /** The export answers only where the dashboard itself can be reached, as a grid extract does. */
    public function test_the_export_needs_the_dashboards_menu_entry(): void
    {
        $this->app->instance(MenuAccessService::class, new class(app(PermissionService::class)) extends MenuAccessService
        {
            public function canReachRoute(User $user, string $routeName): bool
            {
                return $routeName !== 'app.recon.dashboard' && parent::canReachRoute($user, $routeName);
            }
        });

        $this->actingAs($this->admin())->get(route('app.recon.dashboard.export', ['format' => 'csv']))->assertForbidden();
    }

    /** Odd parameters answer with a page, Refresh does not stay in the address, and From after To reads nothing. */
    public function test_odd_parameters_refresh_and_an_inverted_window(): void
    {
        $admin = $this->admin();

        foreach (['view[]=pivot', 'from=0001-01-01', 'to=9999-12-31', 'branches[]=99999999999999999999'] as $query) {
            $this->actingAs($admin)->get(route('app.recon.dashboard').'?'.$query)->assertOk();
        }
        foreach (['from=0001-01-01', 'to=9999-12-31'] as $query) {
            $this->actingAs($admin)->get(route('app.recon.dashboard.export').'?format=csv&'.$query)->assertOk();
        }
        $this->actingAs($admin)->get(route('app.recon.dashboard').'?branches[]=99999999999999999999')
            ->assertDontSee('· 1 site', false);

        // Refresh reads again, then the same address without `fresh`.
        $this->actingAs($admin)->get(route('app.recon.dashboard', ['lens' => 'count', 'fresh' => 1]))
            ->assertRedirect(route('app.recon.dashboard', ['lens' => 'count']));

        // From after To: the notice, and no figures that read as all clear.
        $this->actingAs($admin)->get(route('app.recon.dashboard', ['from' => '2026-11-10', 'to' => '2026-11-01']))
            ->assertOk()->assertSee('From is after To')->assertDontSee('Outstanding by site')->assertDontSee('Bank lines outstanding');
        $this->actingAs($admin)->get(route('app.recon.dashboard.export', ['format' => 'csv', 'from' => '2026-11-10', 'to' => '2026-11-01']))
            ->assertStatus(422);
    }

    /** An Items reference opens Trace on that value, at its site, a fortnight either side. */
    public function test_items_references_open_trace(): void
    {
        $page = $this->actingAs($this->admin())->get(route('app.recon.dashboard', ['view' => 'items']))->assertOk();

        if (! str_contains((string) $page->getContent(), 'class="dg-row-link"')) {
            $this->markTestSkipped('Nothing outstanding with a reference in this database.');
        }

        $page->assertSee('/app/recon/trace?q=', false)->assertSee('branch_id=', false);
    }
}
