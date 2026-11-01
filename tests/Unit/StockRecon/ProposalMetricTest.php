<?php

namespace Tests\Unit\StockRecon;

use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Services\ProposalMetric;
use PHPUnit\Framework\TestCase;

class ProposalMetricTest extends TestCase
{
    public function test_scopes_use_preview_grain_thresholds_and_saved_flags(): void
    {
        $line = fn (array $values) => (new StockReconRunLine)->forceFill($values);
        $lines = collect([
            $line(['Id' => 1, 'AreaNo' => 1, 'StockItemNo' => 'A', 'ChainNetVar' => '2', 'SellPrice' => '5', 'WouldAmend' => true, 'CountsMove' => true, 'QtyVar' => '0.006', 'QtyVarNew' => '-0.006', 'FlagChainBroken' => true]),
            $line(['Id' => 2, 'AreaNo' => 1, 'StockItemNo' => 'A', 'ChainNetVar' => '2', 'SellPrice' => '6', 'QtyVar' => '0.005', 'QtyVarNew' => '-0.005', 'IsDormant' => true]),
            $line(['Id' => 3, 'AreaNo' => 2, 'StockItemNo' => 'A', 'ChainNetVar' => '-1', 'ChainBlocked' => true, 'RestoresHand' => true, 'FlagChainBroken' => true, 'AuditCode' => 'S1', 'QtyVar' => '-1', 'QtyVarNew' => '1']),
            $line(['Id' => 4, 'AreaNo' => 3, 'StockItemNo' => 'B', 'ChainNetVar' => '0.005', 'WouldAmend' => true, 'QtyIssuedNew' => '7', 'DupTier' => null]),
            $line(['Id' => 5, 'AreaNo' => 4, 'StockItemNo' => 'C', 'ChainNetVar' => '0', 'WouldAmend' => true, 'QtyIssuedNew' => '0', 'DupTier' => 'D1']),
            $line(['Id' => 6, 'AreaNo' => 5, 'StockItemNo' => 'D', 'ChainNetVar' => '0', 'ChainBlocked' => true, 'FlagChainBroken' => true]),
        ]);
        $metric = new ProposalMetric;
        $expected = [
            'amended' => [1, 5], 'balance' => [1, 4, 5], 'reported' => [3, 6],
            'unrecorded' => [1], 'over-before' => [1], 'over-after' => [3],
            'short-before' => [3], 'short-after' => [1], 'dormant' => [2],
            'openings' => [1, 3], 'audit' => [3],
        ];
        foreach ($expected as $scope => $ids) {
            $this->assertSame($ids, $metric->records($lines, $scope)->pluck('Id')->all(), $scope);
        }
        $chain = $metric->records($lines, 'unrecorded')->first();
        $this->assertEquals(2, $chain->metricChainTotal);
        $this->assertEquals(6, $chain->metricSellPrice);
        $this->assertCount(0, $metric->records(collect(), 'dormant'));
    }
}
