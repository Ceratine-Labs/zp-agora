<?php

namespace Tests\Feature\Assets;

use App\Exceptions\AgoraProcException;
use Tests\TestCase;

/**
 * The register and its movements (T057): numbering, what each movement type
 * does to an asset, the order they must come in, and that a reversal puts back
 * exactly what was there.
 *
 * Against the local stub, branch 999 only.
 */
class AssetMovementTest extends TestCase
{
    use AssetsFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes assets and movements.');
        $this->cleanUpAssets();
        $this->seedAssetMasters();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUpAssets();
        }

        parent::tearDown();
    }

    public function test_asset_numbers_run_per_site_and_group_and_are_never_reused(): void
    {
        $a = $this->registerAsset();
        $b = $this->registerAsset(['AssetTypeCode' => 'TSTA02']);
        $c = $this->registerAsset(['AssetGroupCode' => self::GROUP_SIX, 'AssetTypeCode' => 'TSTC01']);

        $this->assertSame('TSTA0001-999', $this->asset($a)->AssetNo);
        $this->assertSame('TSTA0002-999', $this->asset($b)->AssetNo);
        $this->assertSame('TSTC0001-999', $this->asset($c)->AssetNo, 'A sequence per group.');

        $this->procs()->write('agora.usp_Assets_DeleteAsset', [
            'BranchId' => self::BRANCH, 'AssetId' => $b, 'Reason' => 'TEST-registered in error', 'AllowDelete' => 1,
        ]);

        $d = $this->registerAsset();
        $this->assertSame('TSTA0003-999', $this->asset($d)->AssetNo, 'A deleted asset\'s number is not issued again.');
    }

    public function test_the_same_make_and_serial_cannot_be_on_two_registers(): void
    {
        $this->registerAsset(['Make' => 'TEST-Brother', 'SerialNo' => 'TEST-E74264M5L586285']);

        $this->assertRefused('DUPLICATE_SERIAL', fn () => $this->registerAsset(['Make' => 'TEST-Brother', 'SerialNo' => 'TEST-E74264M5L586285']));

        // A different maker may share the serial.
        $this->assertGreaterThan(0, $this->registerAsset(['Make' => 'TEST-HP', 'SerialNo' => 'TEST-E74264M5L586285']));
    }

    public function test_a_change_needs_the_edit_permission_and_a_reason(): void
    {
        $asset = $this->registerAsset();
        $change = ['BranchId' => self::BRANCH, 'AssetId' => $asset, 'AssetGroupCode' => self::GROUP, 'AssetTypeCode' => 'TSTA01',
            'Make' => 'TEST-Make', 'DatePurchased' => '2026-01-15', 'Cost' => 30000, 'Condition' => 'FAIR'];

        $this->assertRefused('EDIT_NOT_ALLOWED', fn () => $this->procs()->write('agora.usp_Assets_SaveAsset', $change + ['AllowEdit' => 0, 'Reason' => 'TEST']));
        $this->assertRefused('REASON_REQUIRED', fn () => $this->procs()->write('agora.usp_Assets_SaveAsset', $change + ['AllowEdit' => 1]));

        $this->procs()->write('agora.usp_Assets_SaveAsset', $change + ['AllowEdit' => 1, 'Reason' => 'TEST-invoice said 30 000']);

        $row = $this->asset($asset);
        $this->assertEqualsWithDelta(30000.0, (float) $row->Cost, 0.001);
        $this->assertSame('FAIR', $row->Condition);
        $this->assertSame('TSTA0001-999', $row->AssetNo, 'A change never renumbers.');
    }

    public function test_a_transfer_moves_the_asset_but_it_stays_on_its_own_sites_register(): void
    {
        $asset = $this->registerAsset();
        $elsewhere = $this->otherBranch();

        $this->move($asset, 'MBS', '2026-08-01', ['ToBranchId' => $elsewhere]);

        $row = $this->asset($asset);
        $this->assertSame($elsewhere, (int) $row->LocatedBranchId);
        $this->assertSame(self::BRANCH, (int) $row->BranchId);

        $register = $this->procs()->callSets('agora.usp_Assets_GridRegister', [
            'BranchIds' => (string) $elsewhere, 'RatesJson' => $this->ratesJson(), 'PageSize' => 500,
        ])[0]->firstWhere('AssetId', $asset);
        $this->assertNotNull($register, 'It is on the list of the site it is standing at, too.');
        $this->assertSame('Away from its site', $register->Check);

        $this->assertRefused('SAME_SITE', fn () => $this->move($asset, 'MBS', '2026-08-02', ['ToBranchId' => $elsewhere]));
        $this->assertRefused('DESTINATION_REQUIRED', fn () => $this->move($asset, 'MBS', '2026-08-02'));
    }

    public function test_movements_are_refused_out_of_date_order(): void
    {
        $asset = $this->registerAsset();
        $this->move($asset, 'MBS', '2026-08-10', ['ToBranchId' => $this->otherBranch()]);

        $this->assertRefused('OUT_OF_ORDER', fn () => $this->move($asset, 'RM', '2026-08-01'));
        $this->assertRefused('BEFORE_PURCHASE', fn () => $this->move($this->registerAsset(['DatePurchased' => '2026-05-01']), 'RM', '2026-04-30'));
    }

    public function test_back_from_repair_closes_the_open_job_card_and_a_reversal_reopens_it(): void
    {
        $asset = $this->registerAsset();
        $card = $this->raiseRepair($asset, '2026-08-01');

        $this->assertRefused('NOT_AT_REPAIR', fn () => $this->move($asset, 'RR', '2026-08-02'));

        $this->move($asset, 'RM', '2026-08-01');
        $this->assertSame('AT_REPAIR', $this->asset($asset)->Status);
        $this->assertRefused('NOT_IN_SERVICE', fn () => $this->move($asset, 'MBS', '2026-08-02', ['ToBranchId' => $this->otherBranch()]));

        $back = $this->move($asset, 'RR', '2026-08-09');
        $this->assertSame('IN_SERVICE', $this->asset($asset)->Status);
        $this->assertSame('2026-08-09', substr((string) $this->card($card)->DateActualReturn, 0, 10));

        $this->procs()->write('agora.usp_Assets_ReverseMovement', [
            'BranchId' => self::BRANCH, 'MovementId' => $back, 'AssetId' => $asset, 'Reason' => 'TEST-not back yet', 'AllowReverse' => 1,
        ]);

        $this->assertSame('AT_REPAIR', $this->asset($asset)->Status);
        $this->assertNull($this->card($card)->DateActualReturn, 'The card is open again, exactly as it was.');
        $this->assertNotNull($this->db()->table('agora.AssetMovement')->where('BranchId', self::BRANCH)->where('Id', $back)->value('ReversedAt'),
            'The reversed movement stays, marked.');
    }

    public function test_a_disposal_or_write_off_takes_the_asset_off_the_register_and_a_reversal_puts_it_back(): void
    {
        $asset = $this->registerAsset();

        $this->assertRefused('REASON_REQUIRED', fn () => $this->move($asset, 'WO', '2026-08-01'));
        $this->assertRefused('BAD_PROCEEDS', fn () => $this->move($asset, 'WO', '2026-08-01', ['Reason' => 'TEST', 'Proceeds' => 100]));

        $off = $this->move($asset, 'WO', '2026-08-01', ['Reason' => 'TEST-burnt out']);
        $row = $this->asset($asset);
        $this->assertSame('WRITTEN_OFF', $row->Status);
        $this->assertSame('2026-08-01', substr((string) $row->DisposedOn, 0, 10));

        $this->assertRefused('ALREADY_GONE', fn () => $this->move($asset, 'S', '2026-08-02', ['Reason' => 'TEST']));
        $this->assertRefused('ASSET_GONE', fn () => $this->raiseRepair($asset, '2026-08-02'));

        $this->procs()->write('agora.usp_Assets_ReverseMovement', [
            'BranchId' => self::BRANCH, 'MovementId' => $off, 'Reason' => 'TEST-wrong asset', 'AllowReverse' => 1,
        ]);

        $row = $this->asset($asset);
        $this->assertSame('IN_SERVICE', $row->Status);
        $this->assertNull($row->DisposedOn);
    }

    public function test_only_the_latest_live_movement_may_be_reversed_and_only_once(): void
    {
        $asset = $this->registerAsset();
        $first = $this->move($asset, 'MBS', '2026-08-01', ['ToBranchId' => $this->otherBranch()]);
        $second = $this->move($asset, 'MBS', '2026-08-05', ['ToBranchId' => self::BRANCH]);

        $reverse = fn (int $id) => $this->procs()->write('agora.usp_Assets_ReverseMovement', [
            'BranchId' => self::BRANCH, 'MovementId' => $id, 'Reason' => 'TEST', 'AllowReverse' => 1,
        ]);

        $this->assertRefused('NOT_LATEST', fn () => $reverse($first));
        $reverse($second);
        $this->assertRefused('ALREADY_REVERSED', fn () => $reverse($second));
        $reverse($first);

        $this->assertSame(self::BRANCH, (int) $this->asset($asset)->LocatedBranchId, 'Both undone, newest first: back home.');
        $this->assertRefused('EDIT_NOT_ALLOWED', fn () => $this->procs()->write('agora.usp_Assets_ReverseMovement', [
            'BranchId' => self::BRANCH, 'MovementId' => $first, 'Reason' => 'TEST', 'AllowReverse' => 0,
        ]));
    }

    public function test_an_asset_with_history_cannot_be_deleted(): void
    {
        $asset = $this->registerAsset();
        $this->raiseRepair($asset, '2026-08-01');

        $this->assertRefused('HAS_HISTORY', fn () => $this->procs()->write('agora.usp_Assets_DeleteAsset', [
            'BranchId' => self::BRANCH, 'AssetId' => $asset, 'Reason' => 'TEST', 'AllowDelete' => 1,
        ]));
        $this->assertRefused('DELETE_NOT_ALLOWED', fn () => $this->procs()->write('agora.usp_Assets_DeleteAsset', [
            'BranchId' => self::BRANCH, 'AssetId' => $this->registerAsset(), 'Reason' => 'TEST', 'AllowDelete' => 0,
        ]));
    }

    public function test_a_movement_type_nobody_has_described_is_refused(): void
    {
        $asset = $this->registerAsset();

        $this->assertRefused('UNKNOWN_MOVEMENT_TYPE', fn () => $this->move($asset, 'ZZ', '2026-08-01'));
    }

    /* ------------------------------------------------------------------ */

    /** @param  array<string, mixed>  $extra */
    private function move(int $assetId, string $type, string $date, array $extra = []): int
    {
        return (int) $this->procs()->write('agora.usp_Assets_Move', $extra + [
            'BranchId' => self::BRANCH, 'AssetId' => $assetId, 'MovementType' => $type, 'MovementDate' => $date,
        ])->Id;
    }

    private function asset(int $id): object
    {
        return $this->db()->table('agora.Asset')->where('BranchId', self::BRANCH)->where('Id', $id)->first();
    }

    private function card(int $id): object
    {
        return $this->db()->table('agora.AssetRepair')->where('BranchId', self::BRANCH)->where('Id', $id)->first();
    }

    private function assertRefused(string $code, callable $call): void
    {
        try {
            $call();
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), $e->getMessage());

            return;
        }

        $this->fail("Expected the procedure to refuse with {$code}; it accepted.");
    }
}
