<?php

namespace Tests\Unit\StockRecon;

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
}
