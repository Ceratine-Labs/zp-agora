<?php

namespace Tests\Feature\Utilities;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Two meters at branch 999 in the LOCAL PumpIT stub, and the readings a test
 * puts under them.
 *
 * Branch 999 is TEST-Playwright: inactive and non-trading, so nothing here
 * can be mistaken for a real site. Everything written is on that branch or
 * named TEST-, and cleanUp() removes all of it — the readings, the two meters,
 * the two type labels, and Agora's own record of any save a test made.
 *
 * The stub must have been widened by database/stubs/pumpit-utilities.sql:
 * the customer's UtilityTransactionId is an IDENTITY and the save procedure
 * inserts without one, exactly as it will in production.
 */
trait UtilitiesFixture
{
    protected const BRANCH = 999;

    protected const ELECTRIC = 'TEST-Electricity';

    protected const WATER = 'TEST-Water';

    protected function seedMeters(): void
    {
        $db = $this->db();

        // Type labels at ids far above the customer's 48-72, so a real
        // SS_UtilityType row synced into the stub still wins the TOP 1.
        $db->table('PumpIT.dbo.SS_UtilityType')->insert([
            ['UtilityTypeId' => 999049, 'Code' => 'B', 'Description' => 'TEST-Electricity Main'],
            ['UtilityTypeId' => 999066, 'Code' => 'S', 'Description' => 'TEST-Water ZP'],
        ]);

        $db->table('PumpIT.dbo.BRN_UtilityMeter')->insert([
            ['UtilityMeterId' => 999001, 'SSBranchId' => self::BRANCH, 'UtilityType' => 'B',
                'MeterNo' => self::ELECTRIC, 'Description' => 'TEST-Electricity Main'],
            ['UtilityMeterId' => 999002, 'SSBranchId' => self::BRANCH, 'UtilityType' => 'S',
                'MeterNo' => self::WATER, 'Description' => 'TEST-Water ZP'],
        ]);
    }

    /**
     * A reading as the legacy client writes one. Opening and Usage are stored
     * the way the customer's screen leaves them, which is not always right —
     * the tests that care say so.
     */
    protected function reading(string $date, float $closing, float $prepaid = 0, string $type = 'B', ?string $meter = null, float $opening = 0, float $usage = 0): int
    {
        return (int) $this->db()->table('PumpIT.dbo.BRN_UtilityTransaction')->insertGetId([
            'SSBranchId' => self::BRANCH,
            'TransactionDate' => $date.' 00:00:00',
            'UtilityType' => $type,
            'MeterNo' => $meter ?? ($type === 'B' ? self::ELECTRIC : self::WATER),
            'OpeningReading' => $opening,
            'ClosingReading' => $closing,
            'PrepaidUnitsPurchased' => $prepaid,
            'Usage' => $usage,
            'CreateDateTime' => now(),
        ], 'UtilityTransactionId');
    }

    /** @return array<int, object> */
    protected function stored(string $date, string $type = 'B'): array
    {
        return $this->db()->table('PumpIT.dbo.BRN_UtilityTransaction')
            ->where('SSBranchId', self::BRANCH)
            ->where('UtilityType', $type)
            ->where('TransactionDate', $date.' 00:00:00')
            ->orderBy('UtilityTransactionId')
            ->get()->all();
    }

    protected function cleanUpUtilities(): void
    {
        $db = $this->db();

        $db->table('agora.UtilityReadingWrite')->where('BranchId', self::BRANCH)->delete();
        $db->table('agora.UtilityReadingSave')->where('BranchId', self::BRANCH)->delete();
        $db->table('PumpIT.dbo.BRN_UtilityTransaction')->where('SSBranchId', self::BRANCH)->delete();
        $db->table('PumpIT.dbo.BRN_UtilityMeter')->where('SSBranchId', self::BRANCH)->delete();
        $db->table('PumpIT.dbo.SS_UtilityType')->where('Description', 'like', 'TEST-%')->delete();
    }

    protected function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
