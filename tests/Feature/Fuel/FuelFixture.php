<?php

namespace Tests\Feature\Fuel;

use Illuminate\Support\Facades\DB;

/** Synthetic TEST source records, isolated in a rollback transaction on loopback only. */
trait FuelFixture
{
    private bool $transaction = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub('Fuel fixtures write only to the local PumpIT stub.');
        DB::beginTransaction();
        $this->transaction = true;

        foreach ([
            [999, '2026-08-01', '2026-08-10', '10.123456', '12.000000', '1.876544'],
            [999, '2026-08-05', '2026-08-31', '11.123456', '13.000000', '1.876544'],
            [998, '2026-08-05', '2026-08-31', '90.000000', '99.000000', '9.000000'],
        ] as [$branch, $start, $end, $cost, $sell, $margin]) {
            DB::table('PumpIT.dbo.BRN_MonthlyDOEFuelPricingMatrix')->insert([
                'SSBranchId' => $branch, 'FuelTypeNo' => 99991, 'FuelTypeDescription' => 'TEST-Fuel price',
                'StartDate' => $start, 'EndDate' => $end, 'CostPrice' => $cost,
                'SellingPrice' => $sell, 'Margin' => $margin, 'FleetMargin' => '0.100001',
            ]);
        }
        foreach ([
            ['2026-08-04', '2026-08-04', '12.000000', '10.123456', '1.876544'],
            ['2026-08-04', '2026-08-05', '13.000000', '11.123456', '1.876544'],
            ['2026-08-13', '2026-08-13', '13.000000', '11.123456', '1.876544'],
            ['2026-08-13', '2026-08-13', '13.100000', null, null],
        ] as [$trading, $source, $sell, $cost, $margin]) {
            DB::table('PumpIT.dbo.BRN_FuelPrice')->insert([
                'SSBranchId' => 999, 'FuelTypeNo' => 99991, 'FuelTypeDescription' => 'TEST-Fuel price',
                'Date' => $trading, 'S_Date' => $source, 'SellingPrice' => $sell,
                'CostPrice' => $cost, 'Margin' => $margin, 'IsFleetCard' => false,
                'POSType' => 'TEST-POS', 'POSIdentifier' => 'TEST-Fuel', 'EOD_CNTR' => 'TEST',
                'HandlingFee' => '0.0100',
            ]);
        }
        DB::table('PumpIT.dbo.BRN_FuelPrice')->insert([
            'SSBranchId' => 998, 'FuelTypeNo' => 99991, 'FuelTypeDescription' => 'TEST-Forbidden POS',
            'Date' => '2026-08-13', 'S_Date' => '2026-08-13', 'SellingPrice' => '99.123456',
            'CostPrice' => '90.000000', 'Margin' => '9.123456', 'IsFleetCard' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->transaction) {
            DB::rollBack();
        }
        parent::tearDown();
    }
}
