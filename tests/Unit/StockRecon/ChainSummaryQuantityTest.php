<?php

namespace Tests\Unit\StockRecon;

use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Services\ChainSummary;
use PHPUnit\Framework\TestCase;

class ChainSummaryQuantityTest extends TestCase
{
    public function test_status_quantities_omit_only_insignificant_decimals(): void
    {
        $this->assertSame('Balance 2 shifts · issue of 7 captured', ChainSummary::verdict(7, 2, true, 0)['label']);
        $this->assertSame('Balance 1 shift · issue of 1 captured', ChainSummary::verdict(1, 1, true, 0)['label']);
        $this->assertSame('Balance 2 shifts · issue of 7.5 captured', ChainSummary::verdict(7.5, 2, true, 0)['label']);
        $this->assertSame('Balance 2 shifts · issue of 7.126 captured', ChainSummary::verdict(7.1256, 2, true, 0)['label']);
        $this->assertSame('Over 1 000 · capture the missing issue', ChainSummary::verdict(1000, 0, false)['label']);
        $this->assertSame('Short 10 · nothing to balance', ChainSummary::verdict(-10, 0, false)['label']);
        $this->assertSame('a short of 0.001 stays', ChainSummary::verdict(-0.001, 1, true)['hint']);
        $this->assertStringContainsString('short of 7.5.', ChainSummary::balanceNote([
            'letter' => 'A', 'series' => 1, 'with' => 'a shift', 'net' => -7.5,
        ]));
    }

    public function test_a_chain_with_shorts_and_overs_left_names_both_totals(): void
    {
        // RRM-RFG CHICKEN BURGER PIE, run #70235 (Ryan, 14 Nov 2026): the
        // shifts' variances after the run; shorts 11, overs 16, net +5.
        $lines = collect([1, 2, 1, 0, -4, 4, 0, 1, -5, 4, -2, 2, 0, 1])->map(fn ($v, $i) => (new StockReconRunLine)->forceFill([
            'Id' => $i + 1, 'AreaNo' => 1, 'StockItemNo' => '12', 'ContinuitySegment' => 0, 'ChainBlocked' => false,
            'QtyVarNew' => $v, 'QtyVar' => $v, 'WouldAmend' => false, 'CommitState' => 'pending', 'ChainNetVar' => 5,
        ]));

        $state = ChainSummary::of($lines)->first()->state();

        $this->assertSame('Total short: 11 · Total over: 16', $state['label']);
        $this->assertStringContainsString('net +5', $state['title']);
    }
}
