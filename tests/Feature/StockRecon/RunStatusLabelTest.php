<?php

namespace Tests\Feature\StockRecon;

use App\Support\ProcedureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\StockRecon\Grids\StockReconRunGrid;
use Modules\StockRecon\Models\StockReconRun;
use Tests\TestCase;

/**
 * A committed run reads "Posted" (Tessa Coetzee, Ngwenya Convenience,
 * 12 Nov 2026), while the stored Status and everything that compares against
 * it stays 'committed'.
 */
class RunStatusLabelTest extends TestCase
{
    private const USER = 919993;

    private bool $transaction = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        DB::connection('agora')->beginTransaction();
        $this->transaction = true;
    }

    protected function tearDown(): void
    {
        if ($this->transaction) {
            DB::connection('agora')->rollBack();
        }
        parent::tearDown();
    }

    public function test_the_runs_grid_shows_posted_and_still_filters_on_the_stored_value(): void
    {
        $posted = $this->makeRun('committed');
        $open = $this->makeRun('previewed');

        $rows = $this->grid();
        $this->assertSame('Posted', $rows[$posted->Id]->Status);
        $this->assertSame('committed', $rows[$posted->Id]->StatusCode);
        $this->assertSame('Previewed', $rows[$open->Id]->Status);

        $filtered = $this->grid(['committed']);
        $this->assertSame([$posted->Id], array_keys($filtered), 'The filter sends the stored value, not the label.');

        $this->assertSame('committed', $posted->fresh()->Status, 'Only the wording changed.');
        $this->assertTrue($posted->fresh()->isCommitted());
    }

    public function test_the_status_filter_offers_posted_for_the_committed_value(): void
    {
        $filter = (new StockReconRunGrid)->filters()['Status'];

        $this->assertContains('committed', $filter->options);
        $this->assertSame('Posted', $filter->labels['committed']);
        $this->assertSame('Posted', StockReconRun::labelFor('committed'));
        $this->assertSame('Pending', StockReconRun::labelFor('pending'), 'An unmapped value still reads as itself.');
    }

    /**
     * @param  array<int, string>|null  $statuses
     * @return array<int, object>
     */
    private function grid(?array $statuses = null): array
    {
        $sets = (new ProcedureService)->callSets('usp_StockRecon_GridRuns', [
            'MineOnly' => 1, 'UserId' => self::USER, 'PageSize' => 50,
            'FiltersJson' => $statuses === null ? null
                : json_encode([['column' => 'Status', 'type' => 'set', 'in' => $statuses]]),
        ]);

        return collect($sets[0])->keyBy('Id')->all();
    }

    private function makeRun(string $status): StockReconRun
    {
        return StockReconRun::create([
            'BranchId' => 2, 'GroupRef' => (string) Str::uuid(), 'FromDate' => '2031-01-01', 'ToDate' => '2031-01-07',
            'Status' => $status, 'ProcedureName' => 'TEST-status-label',
            'Note' => 'TEST-status-label '.$status, 'CreatedBy' => self::USER,
        ]);
    }
}
