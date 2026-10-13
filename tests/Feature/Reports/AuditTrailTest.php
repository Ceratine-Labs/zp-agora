<?php

namespace Tests\Feature\Reports;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * The audit trail (1 Oct 2026): every change Agora makes in PumpIT, read from
 * the ledgers the reversals run on by agora.vw_PumpItChange.
 *
 * Read-only. It checks the view against the ledgers it reads, so whatever
 * the local database holds is the fixture.
 */
class AuditTrailTest extends TestCase
{
    /**
     * Agora tables that keep a record of what a press wrote to PumpIT. A new
     * named write that keeps its own ledger must add it to the view, or the
     * audit trail silently stops being the whole story. ZreadAllocation is the
     * one ledger whose name does not say so.
     */
    public function test_every_ledger_of_a_pumpit_write_is_in_the_audit_trail(): void
    {
        $ledgers = collect($this->db()->select("
            SELECT t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id
            WHERE s.name = 'agora'
              AND (t.name LIKE '%Event' OR t.name LIKE '%Amendment' OR t.name LIKE '%Write' OR t.name LIKE '%Stamp')"))
            ->pluck('name')->push('ZreadAllocation');

        $definition = (string) $this->db()->selectOne(
            "SELECT OBJECT_DEFINITION(OBJECT_ID('agora.vw_PumpItChange')) AS d")->d;

        $this->assertNotSame('', $definition, 'agora.vw_PumpItChange is missing: run the Reports migrations.');

        foreach ($ledgers as $ledger) {
            $this->assertStringContainsString("].{$ledger} ", $definition.' ',
                "agora.{$ledger} records PumpIT writes but the audit trail does not read it.");
        }
    }

    public function test_the_audit_trail_has_one_row_per_change_in_each_ledger(): void
    {
        $view = collect($this->db()->select('SELECT Ledger, COUNT_BIG(*) AS n FROM agora.vw_PumpItChange GROUP BY Ledger'))
            ->mapWithKeys(fn ($r) => [$r->Ledger => (int) $r->n]);

        $ledgers = [
            'StockReconAmendment' => 'SELECT COUNT_BIG(*) AS n FROM agora.StockReconAmendment',
            'ReconStamp' => 'SELECT COUNT_BIG(*) AS n FROM agora.ReconStamp',
            'ZreadAllocation' => "SELECT COUNT_BIG(*) AS n FROM agora.ZreadAllocation WHERE StampMode = 'live' AND StampedAt IS NOT NULL",
            'UtilityReadingWrite' => 'SELECT COUNT_BIG(*) AS n FROM agora.UtilityReadingWrite',
            'CashupEvent' => "SELECT COUNT_BIG(*) AS n FROM agora.CashupEvent WHERE WriteMode = 'live' AND WritesJson IS NOT NULL",
            'TillBalanceEvent' => "SELECT COUNT_BIG(*) AS n FROM agora.TillBalanceEvent WHERE WriteMode = 'live' AND WritesJson IS NOT NULL",
            'DropSafeEvent' => "SELECT COUNT_BIG(*) AS n FROM agora.DropSafeEvent WHERE WriteMode = 'live' AND WritesJson IS NOT NULL",
            'StaffShortEvent' => "SELECT COUNT_BIG(*) AS n FROM agora.StaffShortEvent WHERE WriteMode = 'live' AND WritesJson IS NOT NULL",
        ];

        foreach ($ledgers as $ledger => $sql) {
            $this->assertSame((int) $this->db()->selectOne($sql)->n, $view[$ledger] ?? 0,
                "{$ledger}: the audit trail must hold exactly one row per change — no more (a join fanned out), no fewer.");
        }
    }

    public function test_a_stock_recon_amendment_reads_as_before_and_after_with_its_reason(): void
    {
        $a = $this->db()->selectOne('SELECT TOP 1 a.*, rl.Outcome FROM agora.StockReconAmendment a
            JOIN agora.StockReconRunLine rl ON rl.BranchId = a.BranchId AND rl.Id = a.RunLineId
            WHERE a.PriorQtyClose <> a.NewQtyClose AND a.PriorQtyClose = FLOOR(a.PriorQtyClose) AND a.NewQtyClose = FLOOR(a.NewQtyClose)
            ORDER BY a.Id');

        if ($a === null) {
            $this->markTestSkipped('No stock recon amendment with a moved closing in this database.');
        }

        $row = $this->db()->selectOne("SELECT * FROM agora.vw_PumpItChange WHERE Ledger = 'StockReconAmendment' AND LedgerId = ?", [$a->Id]);

        // Three places, whole or not (v1__22h: "sometimes 1, sometimes 1.000").
        $closing = sprintf('Closing %.3f → %.3f', (float) $a->PriorQtyClose, (float) $a->NewQtyClose);
        $this->assertStringContainsString($closing, $row->Change);
        $this->assertSame($a->Outcome, $row->Reason, 'The reason is the run line\'s own outcome.');
        $this->assertSame("Stock recon run {$a->RunId}", $row->Ref);
        $this->assertSame('STK_StockReconLine', $row->PumpItTable);
        $this->assertSame($a->State, $row->State);

        // Stored on UTC since v1__14po, shown in SAST like every other ledger:
        // the server's own offset added, never the raw stamp.
        $offset = (int) $this->db()->selectOne('SELECT DATEPART(tzoffset, SYSDATETIMEOFFSET()) AS o')->o;
        // Within a second: the view rounds to datetime2(0), strtotime truncates.
        $this->assertEqualsWithDelta(strtotime((string) $a->CreatedAt) + $offset * 60, strtotime((string) $row->ChangedAt), 1,
            'The stock recon ledger is converted to SAST like the others.');
    }

    public function test_the_report_answers_newest_first_and_counts_what_it_holds(): void
    {
        $sets = app(ProcedureService::class)->callSets('usp_Reports_GridAuditTrail', [
            'DateFrom' => '2000-01-01', 'DateTo' => '2099-12-31', 'PageSize' => 100000,
        ]);

        $rows = $sets[0];
        $this->assertSame((int) $this->db()->selectOne('SELECT COUNT_BIG(*) AS n FROM agora.vw_PumpItChange')->n,
            (int) $sets[1]->first()->TotalRows);

        $times = $rows->pluck('ChangedAt')->map(fn ($t) => (string) $t)->all();
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times, 'With no sort chosen the newest change comes first.');
    }

    public function test_pages_follow_on_from_each_other_newest_first_without_overlap(): void
    {
        $call = fn (int $page) => app(ProcedureService::class)->callSets('usp_Reports_GridAuditTrail', [
            'DateFrom' => '2000-01-01', 'DateTo' => '2099-12-31', 'Page' => $page, 'PageSize' => 5,
        ])[0];

        $one = $call(1);
        $two = $call(2);
        if ($two->isEmpty()) {
            $this->markTestSkipped('Fewer than six changes in this database.');
        }

        $key = fn ($r) => $r->Ledger.'#'.$r->LedgerId;
        $this->assertCount(5, $one);
        $this->assertSame([], array_intersect($one->map($key)->all(), $two->map($key)->all()), 'Page 2 repeats page 1.');
        $this->assertGreaterThanOrEqual((string) $two->first()->ChangedAt, (string) $one->last()->ChangedAt,
            'Page 2 starts after page 1 ends, newest first.');
        $this->assertNotNull($one->first()->Change, 'The page carries its text, built for these rows.');
    }

    public function test_the_audit_trail_renders_under_control_governance(): void
    {
        $admin = User::query()->acrossBranches()->where('EmailAddress', config('agora.admin.email'))->first();

        if (! $admin) {
            $this->markTestSkipped('No seeded administrator — run php artisan seed:master first.');
        }

        $this->actingAs($admin)->get('/app/reports/audit-trail')
            ->assertOk()
            ->assertSee('Audit trail')
            ->assertSee('agora.usp_Reports_GridAuditTrail')
            ->assertSee('Before → after', false);

        $item = MenuItem::query()->acrossBranches()->where('Path', 'governance/audit-trail')->first();
        if ($item === null) {
            $this->markTestSkipped('Core menu not seeded here.');
        }
        $this->assertSame('/app/reports/audit-trail', $item->Url);
    }

    public function test_each_row_of_the_audit_trail_opens_its_correction(): void
    {
        $admin = $this->admin();
        $first = $this->db()->selectOne('SELECT TOP 1 Ledger, LedgerId FROM agora.vw_PumpItChange ORDER BY ChangedAt DESC');
        if ($first === null) {
            $this->markTestSkipped('Nothing in the audit trail in this database.');
        }

        $this->actingAs($admin)->get('/app/reports/audit-trail?from=2000-01-01&to=2099-12-31')
            ->assertOk()
            ->assertSee('data-row-detail', false)
            ->assertSee(route('app.reports.audit.change', [$first->Ledger, $first->LedgerId]), false);
    }

    public function test_a_stock_recon_change_opens_with_the_items_shifts_before_and_after(): void
    {
        $a = $this->db()->selectOne('SELECT TOP 1 a.Id, rl.ItemDescription FROM agora.StockReconAmendment a
            JOIN agora.StockReconRunLine rl ON rl.BranchId = a.BranchId AND rl.Id = a.RunLineId
            WHERE a.PriorQtyClose <> a.NewQtyClose ORDER BY a.Id');
        if ($a === null) {
            $this->markTestSkipped('No stock recon amendment with a moved closing in this database.');
        }

        $url = route('app.reports.audit.change', ['StockReconAmendment', $a->Id]);

        $this->actingAs($this->admin())->get($url, ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('What changed')
            ->assertSee('This shift, highlighted')
            ->assertSee('ba-old', false)                       // a figure struck through beside its new value
            ->assertSee('agora.usp_Reports_GetAuditChange')
            ->assertDontSee('<html', false);                   // a fragment under the row, not a page

        $this->actingAs($this->admin())->get($url)
            ->assertOk()
            ->assertSee('A change Agora made in PumpIT');      // followed directly: a page of its own
    }

    public function test_a_bank_recon_stamp_opens_with_its_whole_batch(): void
    {
        $s = $this->db()->selectOne('SELECT TOP 1 Id FROM agora.ReconStamp ORDER BY Id DESC');
        if ($s === null) {
            $this->markTestSkipped('No bank recon stamp in this database.');
        }

        $this->actingAs($this->admin())
            ->get(route('app.reports.audit.change', ['ReconStamp', $s->Id]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Every row this batch stamped');
    }

    public function test_a_change_that_does_not_exist_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->get(route('app.reports.audit.change', ['StockReconAmendment', 999999999]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertNotFound();
    }

    private function admin(): User
    {
        $admin = User::query()->acrossBranches()->where('EmailAddress', config('agora.admin.email'))->first();
        if (! $admin) {
            $this->markTestSkipped('No seeded administrator — run php artisan seed:master first.');
        }

        return $admin;
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
