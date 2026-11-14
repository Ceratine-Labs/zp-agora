<?php

namespace Tests\Feature\StockRecon;

use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;
use Modules\StockRecon\Services\ReconDayService;
use Tests\TestCase;

/**
 * Is each day reconciled? (Ryan, 13 Nov 2026) — agora.usp_StockRecon_DayStatus
 * and the two screens over it.
 *
 * Branch 999 (TEST-Playwright), March 2031, three TEST areas: 91 and 92 are
 * stock, 93 is Virtual Items and never asked about. Everything is written
 * inside one transaction on the agora connection — the PumpIT stub is on the
 * same instance, so the recon lines roll back with it — and nothing another
 * session keeps at branch 999 is touched.
 *
 *    1 Mar  91, 92 counted · R0 (older) holds 91; R1 (latest) clean, one
 *           shift amended, one skipped as already amended elsewhere  → done
 *    2 Mar  91, 92 counted · R1 holds a chain on 91, an amendment on 92
 *           reversed since                                           → partial
 *    3 Mar  91, 92 counted · R2 covers area 91 only                  → partial
 *    4 Mar  91, 92 counted · no run                                  → open
 *    5 Mar  91 counted twice · R3 saw one line: counted after it     → partial
 *    6 Mar  91 counted · a preview and a reset only                  → open
 *    7 Mar  91 counted · R6 posted, an amendment never ticked        → partial
 *    8 Mar  only the virtual area counted                            → none
 *    9 Mar  the business date                                        → later
 */
class ReconDayTest extends TestCase
{
    private const BRANCH = 999;

    private const USER = 919994;

    private bool $transaction = false;

    /** @var array<string, int> */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub('This fixture writes recon lines into the PumpIT stub.');
        DB::connection('agora')->beginTransaction();
        $this->transaction = true;
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->transaction) {
            DB::connection('agora')->rollBack();
        }
        parent::tearDown();
    }

    public function test_each_day_takes_its_colour_from_the_latest_posted_run(): void
    {
        $days = $this->calendar()->keyBy(fn ($d) => substr((string) $d->Day, 0, 10));

        $this->assertSame([
            '2031-03-01' => 'done',
            '2031-03-02' => 'partial',
            '2031-03-03' => 'partial',
            '2031-03-04' => 'open',
            '2031-03-05' => 'partial',
            '2031-03-06' => 'open',
            '2031-03-07' => 'partial',
            '2031-03-08' => 'none',
            '2031-03-09' => 'later',
        ], $days->mapWithKeys(fn (object $d, string $day): array => [$day => (string) $d->DayStatus])->all());

        // The older run's hold on 1 Mar is not the answer; the latest run's is.
        $this->assertSame(0, (int) $days['2031-03-01']->HeldItems);
        $this->assertSame(1, (int) $days['2031-03-01']->AmendedLines, 'Amended is counted, and does not colour the day.');
        $this->assertSame(0, (int) $days['2031-03-01']->LeftLines, 'Skipped because another run amended it is done.');
        $this->assertSame(2, (int) $days['2031-03-01']->AreasCounted, 'The virtual area is never asked about.');

        $this->assertSame(1, (int) $days['2031-03-02']->HeldItems);
        $this->assertSame(1, (int) $days['2031-03-02']->ReversedLines);
        $this->assertSame([2, 1], [(int) $days['2031-03-03']->AreasCounted, (int) $days['2031-03-03']->AreasPosted]);
        $this->assertStringContainsString('1 area(s) not posted', $days['2031-03-03']->Summary);
        $this->assertSame(1, (int) $days['2031-03-05']->LateLines);
        $this->assertSame(1, (int) $days['2031-03-07']->LeftLines);
        $this->assertStringStartsWith('Not reconciled', $days['2031-03-04']->Summary);
    }

    public function test_one_day_names_its_areas_what_keeps_it_open_and_every_run_over_it(): void
    {
        [$areas, $open, $runs] = (new ProcedureService)->callSets('agora.usp_StockRecon_DayStatus', [
            'Today' => '2031-03-09', 'ExcludedAreaGroups' => 'Virtual Items',
            'DetailBranchId' => self::BRANCH, 'DetailDay' => '2031-03-02',
        ]);

        $this->assertSame(['partial', 'partial'], $areas->pluck('AreaStatus')->all());
        $this->assertSame([$this->runs['R1'], $this->runs['R1']], $areas->pluck('RunId')->map(fn ($id) => (int) $id)->all());

        $this->assertEqualsCanonicalizing(['Held back', 'Reversed'], $open->pluck('Kind')->all());
        $this->assertSame('TEST- possible duplicate', $open->firstWhere('Kind', 'Held back')->Reason);
        $this->assertSame('TEST- reversed by hand', $open->firstWhere('Kind', 'Reversed')->Reason);

        $this->assertSame([$this->runs['R1']], $runs->where('Decides', 1)->pluck('RunId')->map(fn ($id) => (int) $id)->values()->all());
    }

    public function test_the_screens_are_behind_runs_view(): void
    {
        $viewer = $this->person(['stockrecon.runs.view']);

        $this->actingAs($viewer)
            ->get(route('app.stockrecon.days.show', ['branch' => self::BRANCH, 'day' => '2031-03-02']))
            ->assertOk()
            ->assertSee('What keeps it open')
            ->assertSee('TEST- possible duplicate')
            ->assertSee('Run '.$this->runs['R1']);

        $this->actingAs($viewer)
            ->get(route('app.stockrecon.days', ['branch_id' => self::BRANCH, 'month' => '2031-03']))
            ->assertOk()
            ->assertSee('dcal-cell', false)
            ->assertSee('March 2031');

        $other = $this->person(['stockrecon.exceptions.view']);
        $this->actingAs($other)
            ->get(route('app.stockrecon.days.show', ['branch' => self::BRANCH, 'day' => '2031-03-02']))
            ->assertForbidden();
        $this->actingAs($other)
            ->get(route('app.stockrecon.days', ['month' => '2031-03']))
            ->assertForbidden();
    }

    public function test_a_day_opens_each_area_onto_its_items_and_flags_missing_till_quantities(): void
    {
        // Area 91's item T1 sold 7 at the till on 2 Mar; its recon line carries a POS of 0.
        $db = DB::connection('agora');
        $db->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::BRANCH)->whereIn('AreaNo', [91, 92])->update(['Shift_TimeBased' => 0]);
        foreach ([[91, 'T1', 'TEST days item one'], [92, 'T2', 'TEST days item two']] as [$area, $item, $name]) {
            $db->table('PumpIT.dbo.STK_StockMaster')->insert([
                'SSBranchId' => self::BRANCH, 'StockItemNo' => $item, 'StockItemDescription' => $name,
                'UOMCode' => 'EA', 'AreaNo' => $area, 'QtyVarAllowance' => 0, 'IsDoCloseQtyCalc' => 0,
                'POSCode' => 'TEST-D'.$item, 'Location' => 'TPOS',
            ]);
        }
        foreach ([[4, 1], [3, 3]] as [$qty, $shift]) {
            $db->table('PumpIT.dbo.DBF_P3TRANS_STKRECON')->insert([
                'SSBranchId' => self::BRANCH, 'DATE' => '2031-03-02 10:00', 'CODE' => 'TEST-DT1',
                'LOCATION' => 'TPOS', 'Qty' => $qty, 'ShiftNo' => $shift,
            ]);
        }

        $items = app(ReconDayService::class)->items(self::BRANCH, '2031-03-02');

        $this->assertSame([91, 92], $items->keys()->map(fn ($k) => (int) $k)->sort()->values()->all());
        $t1 = $items->get(91)->firstWhere('StockItemNo', 'T1');
        $this->assertSame('day', $t1->Kind);
        $this->assertEqualsWithDelta(7.0, (float) $t1->QtyTill, 0.0005);
        $this->assertEqualsWithDelta(7.0, (float) $t1->QtyMissing, 0.0005);
        $this->assertEqualsWithDelta(0.0, (float) $t1->QtyPOS, 0.0005);
        $t2 = $items->get(92)->firstWhere('StockItemNo', 'T2');
        $this->assertNull($t2->Kind, 'No till sales for T2: nothing is missing.');
        $this->assertNull($t2->QtyTill);

        $this->actingAs($this->person(['stockrecon.runs.view']))
            ->get(route('app.stockrecon.days.show', ['branch' => self::BRANCH, 'day' => '2031-03-02']))
            ->assertOk()
            ->assertSee('Till quantities are missing in PumpIT — 1 item in 1 area cannot balance yet')
            ->assertSee('data-area="91"', false)
            ->assertSee('What remains')
            ->assertSee('?tab=area-91', false)
            ->assertSee('TEST days item one')
            ->assertSee('7.000 · whole day')
            ->assertSee('agora.usp_StockRecon_DayItems');
    }

    // ---- fixture -------------------------------------------------------------

    /** @return Collection<int, object> */
    private function calendar(): Collection
    {
        return (new ProcedureService)->callSets('agora.usp_StockRecon_DayStatus', [
            'BranchIds' => (string) self::BRANCH, 'FromDate' => '2031-03-01', 'ToDate' => '2031-03-09',
            'Today' => '2031-03-09', 'ExcludedAreaGroups' => 'Virtual Items',
        ])[0];
    }

    private function seedFixture(): void
    {
        $db = DB::connection('agora');

        foreach ([[91, 'TEST-days A', 'Food Items'], [92, 'TEST-days B', 'Food Items'], [93, 'TEST-days virtual', 'Virtual Items']] as [$no, $name, $group]) {
            $db->table('PumpIT.dbo.STK_Area')->insert([
                'SSBranchId' => self::BRANCH, 'AreaNo' => $no, 'AreaDescription' => $name, 'AreaGroup' => $group,
                'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 1, 'ShowReport' => 1, 'IsCaptureWaste' => 0,
            ]);
        }

        // The counts: [day, area, item] — one line each, shift 1.
        $counts = [
            ['01', 91, 'T1'], ['01', 92, 'T2'], ['01', 93, 'TV'],
            ['02', 91, 'T1'], ['02', 92, 'T2'],
            ['03', 91, 'T1'], ['03', 92, 'T2'],
            ['04', 91, 'T1'], ['04', 92, 'T2'],
            ['05', 91, 'T1'], ['05', 91, 'T3'],
            ['06', 91, 'T1'],
            ['07', 91, 'T1'],
            ['08', 93, 'TV'],
            ['09', 91, 'T1'],
        ];
        foreach ($counts as [$d, $area, $item]) {
            $db->table('PumpIT.dbo.STK_StockReconLine')->insert([
                'SSBranchId' => self::BRANCH, 'TransactionDate' => "2031-03-{$d}", 'ShiftNo' => 1, 'AreaNo' => $area,
                'StockItemNo' => $item, 'QtyOpen_Original' => 1, 'QtyIssued_Original' => 0, 'QtyClose_Original' => 1,
                'QtyComputer_Original' => 0, 'QtyOpen' => 1, 'QtyIssued' => 0, 'QtyClose' => 1, 'QtyComputer' => 0,
            ]);
        }

        // [run, area (null = all), from, to, status, kind, committed at, lines]
        // line: [day, area, item, blocked, state, wouldAmend, selected, reason]
        $plan = [
            ['R0', null, '01', '01', 'committed', null, '2031-03-10 08:00', [
                ['01', 91, 'T1', 1, 'pending', 0, 1, 'TEST- older hold'],
                ['01', 92, 'T2', 0, 'pending', 0, 1, null],
            ]],
            ['R1', null, '01', '02', 'committed', null, '2031-03-10 09:00', [
                ['01', 91, 'T1', 0, 'committed', 1, 1, null],
                ['01', 92, 'T2', 0, 'skipped', 1, 1, 'This shift already carries an amendment from another run. Reverse that first.'],
                ['02', 91, 'T1', 1, 'pending', 0, 1, 'TEST- possible duplicate'],
                ['02', 92, 'T2', 0, 'committed', 1, 1, null],
            ]],
            ['R2', 91, '03', '03', 'committed', null, '2031-03-10 09:00', [['03', 91, 'T1', 0, 'pending', 0, 1, null]]],
            ['R3', null, '05', '05', 'committed', null, '2031-03-10 09:00', [['05', 91, 'T1', 0, 'pending', 0, 1, null]]],
            ['R4', null, '06', '06', 'previewed', null, null, [['06', 91, 'T1', 0, 'pending', 0, 1, null]]],
            ['R5', null, '06', '06', 'committed', 'reset', '2031-03-10 09:00', [['06', 91, 'T1', 0, 'committed', 1, 1, null]]],
            ['R6', null, '07', '07', 'committed', null, '2031-03-10 09:00', [['07', 91, 'T1', 0, 'pending', 1, 0, null]]],
        ];

        foreach ($plan as [$key, $area, $from, $to, $status, $kind, $at, $lines]) {
            $runId = (int) $db->table('agora.StockReconRun')->insertGetId([
                'BranchId' => self::BRANCH, 'GroupRef' => (string) Str::uuid(), 'AreaNo' => $area,
                'FromDate' => "2031-03-{$from}", 'ToDate' => "2031-03-{$to}", 'CompleteTo' => "2031-03-{$to}",
                'Status' => $status, 'CommitKind' => $kind, 'StampMode' => 'live', 'ProcedureName' => 'TEST-days',
                'CommittedAt' => $at, 'CreatedBy' => self::USER, 'Note' => 'TEST-days '.$key,
            ]);
            $this->runs[$key] = $runId;

            foreach ($lines as $i => [$d, $lineArea, $item, $blocked, $state, $would, $selected, $reason]) {
                $lineId = (int) $db->table('agora.StockReconRunLine')->insertGetId([
                    'BranchId' => self::BRANCH, 'RunId' => $runId, 'LineNo' => $i + 1, 'AreaNo' => $lineArea,
                    'StockItemNo' => $item, 'TransactionDate' => "2031-03-{$d}", 'ShiftNo' => 1,
                    'ChainBlocked' => $blocked, 'CommitState' => $state, 'WouldAmend' => $would, 'Selected' => $selected,
                    'BlockReason' => $reason, 'Outcome' => 'TEST-days',
                ]);

                // R1's committed line on 2 Mar, area 92, was reversed since.
                if ($key === 'R1' && $d === '02' && $lineArea === 92) {
                    $db->table('agora.StockReconAmendment')->insert([
                        'BranchId' => self::BRANCH, 'RunId' => $runId, 'RunLineId' => $lineId, 'AreaNo' => 92,
                        'StockItemNo' => $item, 'TransactionDate' => '2031-03-02', 'ShiftNo' => 1, 'StampMode' => 'live',
                        'State' => 'reversed', 'ReversalReason' => 'TEST- reversed by hand',
                    ]);
                }
            }
        }
    }

    /** @param  array<int, string>  $codes */
    private function person(array $codes): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-days-'.uniqid().'@agora.invalid', 'UserName' => 'TEST-days',
            'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        foreach ($codes as $code) {
            UserPermission::query()->acrossBranches()->create([
                'BranchId' => 2, 'UserId' => (int) $user->Id,
                'PermissionId' => (int) Permission::query()->acrossBranches()->where('Code', $code)->firstOrFail()->Id,
            ]);
        }
        app(PermissionService::class)->forget($user);

        return $user;
    }
}
