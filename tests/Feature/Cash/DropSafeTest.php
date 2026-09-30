<?php

namespace Tests\Feature\Cash;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Support\Badges\BadgeRegistry;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cash\Grids\DropSafeGrid;
use Modules\Cash\Services\DropSafeService;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserBranch;
use Modules\Core\Models\UserPermission;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

/**
 * The drop safe (T039): a bag dropped into PumpIT's safe as its own client
 * writes it, the R2,000 rule and the manager on every bag (never the cashier),
 * a correction or a void while the bag is still free to change, the collection
 * that takes bags out of the safe (and so clears the console step) under a
 * security-company bag used once per site, the screens, and the bags grid.
 *
 * THE FIXTURE (branch 999, all TEST- data in the LOCAL PumpIT stub):
 *
 *   employees   TDS01 cashier, TDS02 and TDS03 managers, TDS01234567 a manager
 *               whose code is 11 characters — one too many for a collection
 *   reasons     9901 "TEST-Coinage"; 9902 "TEST-Smart ATM Broken", which needs
 *               a fault reference; 9903 "TEST-Retired", no longer offered
 *   over R2,000 "TEST-Notes rejected by Electronic safe"
 *
 * Every bag is TEST-DS-…, every collection's bag TEST-DSC-…. Writes the LOCAL
 * stub and Agora's database only, on branch 999, and removes every row it
 * wrote. skipUnlessLocalStub guarantees it never runs against the customer's
 * instance.
 *
 * Other suites also park bags in branch 999's safe while they run (the cashup
 * fixture's TEST-BAG1..3), so the figures for the whole safe are asserted as
 * movements from where this test found them, never as absolutes.
 */
class DropSafeTest extends TestCase
{
    use GrantsAccess;

    private const BRANCH = 999;

    private const DAY = '2026-09-20';

    private const COLLECTED = '2026-09-21';

    private const EMPLOYEES = [
        ['TDS01', 'Thandi', 'TEST-Dropsafe'],
        ['TDS02', 'Musa', 'TEST-Manager'],
        ['TDS03', 'Lerato', 'TEST-Manager'],
        ['TDS01234567', 'Long', 'TEST-Code'],
    ];

    private const REASONS = [
        [9901, 'TEST-Coinage', 0, 1],
        [9902, 'TEST-Smart ATM Broken', 1, 1],
        [9903, 'TEST-Retired', 0, 0],
    ];

    private const LIMIT_REASON = 'TEST-Notes rejected by Electronic safe';

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes bags and collections into the PumpIT stub.');

        if (! Permission::query()->withoutGlobalScopes()->where('Code', 'cash.dropsafe.save')->exists()) {
            $this->markTestSkipped('Run: php artisan seed:master --only=DropSafePermissionSeeder');
        }

        if (DB::connection(config('agora.connections.app'))->selectOne(
            "SELECT OBJECT_ID('PumpIT.dbo.BRN_DropSafe_ReasonAmountMoreThanR2000') AS id"
        )->id === null) {
            $this->markTestSkipped('The stub has no BRN_DropSafe_ReasonAmountMoreThanR2000: apply database/stubs/pumpit-cash.sql (section 6).');
        }

        // The line config/grids.php carries once this lane is merged; the
        // registration itself is asserted by test_the_bags_grid_is_registered,
        // which does NOT add it.
        if (! app(GridRegistry::class)->has('app.cash.dropsafe.bags')) {
            config(['grids.grids' => config('grids.grids') + ['app.cash.dropsafe.bags' => DropSafeGrid::class]]);
        }

        config([
            'cash.dropsafe.write_mode' => 'live',
            'cash.dropsafe.reason_threshold' => 2000.00,
        ]);

        $this->cleanUp();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->db()->unprepared("IF OBJECT_ID('PumpIT.dbo.TEST_trg_DropSafe') IS NOT NULL EXEC PumpIT.sys.sp_executesql N'DROP TRIGGER dbo.TEST_trg_DropSafe';");
            $this->cleanUp();

            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                UserBranch::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                $this->db()->table('agora.UserGridColumn')->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ the drop */

    public function test_a_bag_is_dropped_into_pumpits_safe_as_its_own_client_writes_it(): void
    {
        $user = $this->person('admin');
        $before = $this->inSafe();

        $status = $this->drop($user, 'TEST-DS-1', ['amount' => '2500.00', 'time' => '13:30', 'limitReason' => self::LIMIT_REASON],
            note: 'TEST-dropped at the till');

        $this->assertSame('CASH_DROP_SAVED', $status->Code);
        $this->assertStringContainsString('Bag TEST-DS-1 for R2,500.00 is in PumpIT\'s drop safe', (string) $status->Message);
        $this->assertSame($before['bags'] + 1, (int) $status->InSafeBags, 'The status carries the site\'s safe after the press.');

        $row = $this->bag('TEST-DS-1');
        $this->assertSame(self::DAY.' 00:00:00', substr((string) $row->DropDate, 0, 19), 'The day, at midnight, as PumpIT keeps it.');
        $this->assertSame('13:30:00', $row->DropTime, 'hh:mm:ss text, as on every customer row.');
        $this->assertEqualsWithDelta(2500.00, (float) $row->Amount, 0.00001);
        $this->assertSame([9901, self::LIMIT_REASON, '', 'TDS01', 'TDS02'],
            [(int) $row->ReasonForManualBagId, $row->ReasonIfAmountMoreThanR2000, $row->RefNoFaultReported, $row->CashierCode, $row->ManagerCode],
            'A blank fault reference is stored as \'\', never NULL — as PumpIT stores it.');
        $this->assertSame([0, 0], [(int) $row->IsSelectedForDailyBanking, (int) $row->CollectionId], 'In the safe, and on no cashup.');
        $this->assertNotNull($row->CreateDateTime);
        $this->assertNull($row->MIST_EOD);

        $event = $this->events('TEST-DS-1')->sole();
        $this->assertSame(['dropped', 'live', (int) $user->Id, 'TEST-dropped at the till'], [$event->Event, $event->WriteMode, (int) $event->CreatedBy, $event->Note]);
        $writes = json_decode((string) $event->WritesJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($writes);
        $this->assertSame(['insert', 'BRN_DropSafe', self::BRANCH, 'TEST-DS-1'],
            [$writes['rows'][0]['action'], $writes['rows'][0]['table'], $writes['rows'][0]['key']['SSBranchId'], $writes['rows'][0]['key']['BagNo']]);
        $this->assertEquals(2500, $writes['rows'][0]['new']['Amount']);

        $after = $this->inSafe();
        $this->assertSame([$before['bags'] + 1, round($before['amount'] + 2500.00, 2)], [$after['bags'], round($after['amount'], 2)]);

        // A bag dropped here is in the pool a cashup's cash bags are chosen
        // from, not yet banked — usp_Cash_SaveCashupLeg marks it when it is.
        $pool = collect($this->db()->select("SELECT IsBanked, Amount FROM agora.vw_CashupPool WHERE BranchId = ? AND Channel = 'CashBags' AND Reference = 'TEST-DS-1'", [self::BRANCH]))->sole();
        $this->assertSame(0, (int) $pool->IsBanked);
        $this->assertEqualsWithDelta(2500.00, (float) $pool->Amount, 0.00001);
    }

    public function test_the_threshold_rule_and_the_manager_on_every_bag(): void
    {
        $user = $this->person('admin');

        // THE ACCEPTANCE: a R2,500 bag without a manager is refused, by code.
        $this->assertRefused('CASH_DROP_MANAGER', fn () => $this->drop($user, 'TEST-DS-2', [
            'amount' => '2500.00', 'limitReason' => self::LIMIT_REASON, 'manager' => null,
        ]), 'A bag over R2,000.00 needs the manager who witnessed it named — this one is R2,500.00');

        $this->assertRefused('CASH_DROP_LIMIT_REASON', fn () => $this->drop($user, 'TEST-DS-2', [
            'amount' => '2500.00', 'limitReason' => null,
        ]), 'A bag over R2,000.00 needs a reason — this one is R2,500.00');

        // PumpIT keeps a manager on every bag (NOT NULL, and its own screens
        // inner-join it), so a small bag needs one too.
        $this->assertRefused('CASH_DROP_MANAGER', fn () => $this->drop($user, 'TEST-DS-2', [
            'amount' => '150.00', 'manager' => null,
        ]), 'PumpIT keeps one on every bag');

        $this->assertNull($this->bag('TEST-DS-2'), 'Nothing was written by any refusal.');
        $this->assertCount(0, $this->events('TEST-DS-2'));

        // Exactly R2,000.00 is not over it (1,637 such bags on the customer's
        // instance carry no reason).
        $this->assertSame('CASH_DROP_SAVED', $this->drop($user, 'TEST-DS-2', ['amount' => '2000.00', 'limitReason' => null])->Code);

        // The threshold is the setting's, not a literal in the procedure.
        config(['cash.dropsafe.reason_threshold' => 1000.00]);
        $this->assertRefused('CASH_DROP_LIMIT_REASON', fn () => $this->drop($user, 'TEST-DS-3', ['amount' => '1500.00', 'limitReason' => null]),
            'A bag over R1,000.00 needs a reason');
    }

    public function test_every_refusal_is_named_and_writes_nothing(): void
    {
        $user = $this->person('admin');
        $this->drop($user, 'TEST-DS-TAKEN', []);

        $cases = [
            'CASH_DROP_BAG' => ['TEST-DS-'.str_repeat('9', 13), []],
            'CASH_DROP_DATE' => ['TEST-DS-R1', ['date' => now()->addDays(2)->toDateString()]],
            'CASH_DROP_TIME' => ['TEST-DS-R1', ['time' => '25:61']],
            'CASH_DROP_AMOUNT' => ['TEST-DS-R1', ['amount' => '0']],
            'CASH_DROP_REASON' => ['TEST-DS-R1', ['reason' => 424242]],
            'CASH_DROP_REF' => ['TEST-DS-R1', ['reason' => 9902, 'ref' => null]],
            'CASH_DROP_CASHIER' => ['TEST-DS-R1', ['cashier' => 'TNOBODY']],
            'CASH_DROP_MANAGER_UNKNOWN' => ['TEST-DS-R1', ['manager' => 'TNOBODY']],
            'CASH_DROP_BAG_TAKEN' => ['TEST-DS-TAKEN', []],
        ];

        foreach ($cases as $code => [$bagNo, $overrides]) {
            $this->assertRefused($code, fn () => $this->drop($user, $bagNo, $overrides));
        }

        // A reason PumpIT no longer offers is not offered for a new bag.
        $this->assertRefused('CASH_DROP_REASON', fn () => $this->drop($user, 'TEST-DS-R1', ['reason' => 9903]), 'no longer offered');

        // A number already on a cashup — even with no drop-safe row — is taken.
        $this->leg('TEST-DS-ONLEG', 80);
        $this->assertRefused('CASH_DROP_BAG_TAKEN', fn () => $this->drop($user, 'TEST-DS-ONLEG', []), 'already on the cashup');

        // The switch is the second lock: the procedure refuses on its own.
        config(['cash.dropsafe.write_mode' => 'off']);
        $this->assertRefused('CASH_WRITES_OFF', fn () => $this->drop($user, 'TEST-DS-R1', []));
        config(['cash.dropsafe.write_mode' => 'live']);

        // Agora does not write through a trigger on the customer's table.
        $this->db()->unprepared("EXEC PumpIT.sys.sp_executesql N'CREATE TRIGGER dbo.TEST_trg_DropSafe ON dbo.BRN_DropSafe AFTER INSERT AS SET NOCOUNT ON;'");
        try {
            $this->assertRefused('CASH_PUMPIT_TRIGGER', fn () => $this->drop($user, 'TEST-DS-R1', []));
        } finally {
            $this->db()->unprepared("EXEC PumpIT.sys.sp_executesql N'DROP TRIGGER dbo.TEST_trg_DropSafe';");
        }

        $this->assertSame(['TEST-DS-TAKEN'], $this->db()->table('PumpIT.dbo.BRN_DropSafe')->where('SSBranchId', self::BRANCH)
            ->where('BagNo', 'like', 'TEST-DS-%')->pluck('BagNo')->all(), 'No refusal wrote a bag.');
        $this->assertSame(1, $this->db()->table('agora.DropSafeEvent')->where('BranchId', self::BRANCH)->where('BagNo', 'like', 'TEST-DS-%')->count());
    }

    /* ----------------------------------------------------------- the correction */

    public function test_a_bag_still_in_the_safe_and_on_no_cashup_is_corrected_and_what_it_replaced_is_kept(): void
    {
        $user = $this->person('admin');
        $this->drop($user, 'TEST-DS-TYPO', ['amount' => '1200.00']);

        $status = $this->edit($user, 'TEST-DS-TYPO', 'TEST-DS-5', ['amount' => '1250.50', 'manager' => 'TDS03'], note: 'TEST-mistyped');

        $this->assertSame('CASH_DROP_EDITED', $status->Code);
        $this->assertStringContainsString('number TEST-DS-TYPO → TEST-DS-5', (string) $status->Message);
        $this->assertStringContainsString('amount R1,200.00 → R1,250.50', (string) $status->Message);
        $this->assertStringContainsString('manager', (string) $status->Message);

        $this->assertNull($this->bag('TEST-DS-TYPO'), 'The mistyped number is gone — the row was corrected, not copied.');
        $row = $this->bag('TEST-DS-5');
        $this->assertEqualsWithDelta(1250.50, (float) $row->Amount, 0.00001);
        $this->assertSame(['TDS03', 0, 0], [$row->ManagerCode, (int) $row->CollectionId, (int) $row->IsSelectedForDailyBanking]);

        $event = $this->db()->table('agora.DropSafeEvent')->where('BranchId', self::BRANCH)->where('Event', 'edited')->sole();
        $this->assertSame(['TEST-DS-5', 'TEST-DS-TYPO', 'TEST-mistyped'], [$event->BagNo, $event->PriorBagNo, $event->Note]);
        $writes = json_decode((string) $event->WritesJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($writes);
        $this->assertSame(['update', 'TEST-DS-TYPO', 'TEST-DS-TYPO', 'TDS02', 'TEST-DS-5', 'TDS03'], [
            $writes['rows'][0]['action'], $writes['rows'][0]['key']['BagNo'],
            $writes['rows'][0]['prior']['BagNo'], $writes['rows'][0]['prior']['ManagerCode'],
            $writes['rows'][0]['new']['BagNo'], $writes['rows'][0]['new']['ManagerCode'],
        ], 'What the correction replaced is kept beside what it wrote.');
        $this->assertEquals(1200, $writes['rows'][0]['prior']['Amount']);

        $this->assertRefused('CASH_DROP_UNCHANGED', fn () => $this->edit($user, 'TEST-DS-5', null, ['amount' => '1250.50', 'manager' => 'TDS03']));
        $this->assertRefused('CASH_DROP_NO_BAG', fn () => $this->edit($user, 'TEST-DS-NONE', null, []));

        $this->drop($user, 'TEST-DS-6', []);
        $this->assertRefused('CASH_DROP_BAG_TAKEN', fn () => $this->edit($user, 'TEST-DS-5', 'TEST-DS-6', []),
            'already used at this site');
    }

    public function test_a_bag_on_a_cashup_or_collected_is_not_changed(): void
    {
        $user = $this->person('admin');

        // On a cashup the way usp_Cash_SaveCashupLeg leaves it: a cash-bags leg
        // carrying the bag, and the bag marked banked.
        $this->drop($user, 'TEST-DS-BANKED', ['amount' => '300.00']);
        $this->leg('TEST-DS-BANKED', 300);
        $this->db()->table('PumpIT.dbo.BRN_DropSafe')->where('SSBranchId', self::BRANCH)->where('BagNo', 'TEST-DS-BANKED')
            ->update(['IsSelectedForDailyBanking' => 1]);

        $this->assertRefused('CASH_DROP_ON_CASHUP', fn () => $this->edit($user, 'TEST-DS-BANKED', null, ['amount' => '310.00']),
            'is on the cashup for till 71, shift 1 on '.self::DAY);

        // The leg alone is enough — the bag's amount is on it.
        $this->drop($user, 'TEST-DS-LEGONLY', ['amount' => '40.00']);
        $this->leg('TEST-DS-LEGONLY', 40);
        $this->assertRefused('CASH_DROP_ON_CASHUP', fn () => $this->edit($user, 'TEST-DS-LEGONLY', null, ['amount' => '45.00']));

        // Collected: the collection's total counts it.
        $this->drop($user, 'TEST-DS-GONE', ['amount' => '70.00']);
        $this->collect($user, ['TEST-DS-GONE'], 'TEST-DSC-1');
        $this->assertRefused('CASH_DROP_COLLECTED', fn () => $this->edit($user, 'TEST-DS-GONE', null, ['amount' => '75.00']),
            'was collected in TEST-DSC-1 on '.self::COLLECTED);

        $this->assertEqualsWithDelta(300.00, (float) $this->bag('TEST-DS-BANKED')->Amount, 0.00001);
        $this->assertSame(1, (int) $this->bag('TEST-DS-BANKED')->IsSelectedForDailyBanking, 'The banked mark the cashup set is untouched.');
        $this->assertEqualsWithDelta(40.00, (float) $this->bag('TEST-DS-LEGONLY')->Amount, 0.00001);
        $this->assertEqualsWithDelta(70.00, (float) $this->bag('TEST-DS-GONE')->Amount, 0.00001);
        $this->assertSame(0, $this->db()->table('agora.DropSafeEvent')->where('BranchId', self::BRANCH)->where('Event', 'edited')->count());
    }

    public function test_a_cashier_cannot_witness_their_own_bag(): void
    {
        $user = $this->person('admin');

        $this->assertRefused('CASH_DROP_SELF_WITNESS', fn () => $this->drop($user, 'TEST-DS-SELF', ['cashier' => 'TDS02', 'manager' => 'TDS02']),
            'TDS02 dropped the bag and cannot also be the manager who witnessed it');
        $this->assertNull($this->bag('TEST-DS-SELF'));

        // Nor by a correction.
        $this->drop($user, 'TEST-DS-SELF', []);
        $this->assertRefused('CASH_DROP_SELF_WITNESS', fn () => $this->edit($user, 'TEST-DS-SELF', null, ['manager' => 'TDS01']));
        $this->assertSame('TDS02', $this->bag('TEST-DS-SELF')->ManagerCode);
    }

    /* ------------------------------------------------------------------ the void */

    public function test_a_bag_captured_in_error_is_voided_and_kept_whole_in_agora(): void
    {
        $user = $this->person('admin');
        $this->drop($user, 'TEST-DS-VOID', ['amount' => '2345.60', 'reason' => 9902, 'ref' => 'TEST-FAULT-9', 'limitReason' => self::LIMIT_REASON]);
        $before = $this->inSafe();
        $row = (array) $this->bag('TEST-DS-VOID');

        $status = $this->void($user, 'TEST-DS-VOID', 'TEST-captured twice');

        $this->assertSame('CASH_DROP_VOIDED', $status->Code);
        $this->assertStringContainsString('Bag TEST-DS-VOID for R2,345.60 voided', (string) $status->Message);
        $this->assertSame($before['bags'] - 1, (int) $status->InSafeBags);
        $this->assertNull($this->bag('TEST-DS-VOID'), 'The row is gone from PumpIT.');

        $event = $this->db()->table('agora.DropSafeEvent')->where('BranchId', self::BRANCH)->where('Event', 'voided')->sole();
        $this->assertSame(['TEST-DS-VOID', 'TEST-captured twice', 'live', (int) $user->Id], [$event->BagNo, $event->Note, $event->WriteMode, (int) $event->CreatedBy]);
        $this->assertEqualsWithDelta(2345.60, (float) $event->Amount, 0.00001);

        $writes = json_decode((string) $event->WritesJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($writes);
        $this->assertSame(['delete', 'BRN_DropSafe', self::BRANCH, 'TEST-DS-VOID'],
            [$writes['rows'][0]['action'], $writes['rows'][0]['table'], $writes['rows'][0]['key']['SSBranchId'], $writes['rows'][0]['key']['BagNo']]);

        // EVERY column the row had, with its value — the only copy there is now.
        $prior = $writes['rows'][0]['prior'];
        $this->assertSame(array_keys($row), array_keys($prior), 'Every column of the deleted row is kept, in the table\'s order.');
        $this->assertSame(['TEST-DS-VOID', 'TEST-FAULT-9', self::LIMIT_REASON, 'TDS01', 'TDS02', 9902, 0, 0],
            [$prior['BagNo'], $prior['RefNoFaultReported'], $prior['ReasonIfAmountMoreThanR2000'], $prior['CashierCode'],
                $prior['ManagerCode'], $prior['ReasonForManualBagId'], (int) $prior['IsSelectedForDailyBanking'], (int) $prior['CollectionId']]);
        $this->assertEquals(2345.6, $prior['Amount']);
        $this->assertStringStartsWith(self::DAY, (string) $prior['DropDate']);
        $this->assertNotEmpty($prior['CreateDateTime']);

        // The number is free again: a bag that never was does not hold it.
        $this->assertSame('CASH_DROP_SAVED', $this->drop($user, 'TEST-DS-VOID', ['amount' => '12.00'])->Code);
    }

    public function test_a_void_is_refused_unless_the_bag_is_free_and_a_reason_is_given(): void
    {
        $user = $this->person('admin');
        $this->drop($user, 'TEST-DS-V1', []);

        $this->assertRefused('CASH_VOID_REASON', fn () => $this->void($user, 'TEST-DS-V1', ''));
        $this->assertRefused('CASH_VOID_NO_BAG', fn () => $this->void($user, 'TEST-DS-NONE', 'TEST-why'));

        // Marked banked by a cashup.
        $this->drop($user, 'TEST-DS-V2', []);
        $this->db()->table('PumpIT.dbo.BRN_DropSafe')->where('SSBranchId', self::BRANCH)->where('BagNo', 'TEST-DS-V2')
            ->update(['IsSelectedForDailyBanking' => 1]);
        $this->assertRefused('CASH_VOID_ON_CASHUP', fn () => $this->void($user, 'TEST-DS-V2', 'TEST-why'));

        // On a cash-bags leg, even unmarked.
        $this->drop($user, 'TEST-DS-V3', []);
        $this->leg('TEST-DS-V3', 150);
        $this->assertRefused('CASH_VOID_ON_CASHUP', fn () => $this->void($user, 'TEST-DS-V3', 'TEST-why'), 'for till 71, shift 1 on '.self::DAY);

        // Collected.
        $this->drop($user, 'TEST-DS-V4', []);
        $this->collect($user, ['TEST-DS-V4'], 'TEST-DSC-V');
        $this->assertRefused('CASH_VOID_COLLECTED', fn () => $this->void($user, 'TEST-DS-V4', 'TEST-why'), 'in TEST-DSC-V on '.self::COLLECTED);

        config(['cash.dropsafe.write_mode' => 'off']);
        $this->assertRefused('CASH_WRITES_OFF', fn () => $this->void($user, 'TEST-DS-V1', 'TEST-why'));
        config(['cash.dropsafe.write_mode' => 'live']);

        // Agora does not delete through a trigger on the customer's table.
        $this->db()->unprepared("EXEC PumpIT.sys.sp_executesql N'CREATE TRIGGER dbo.TEST_trg_DropSafe ON dbo.BRN_DropSafe AFTER DELETE AS SET NOCOUNT ON;'");
        try {
            $this->assertRefused('CASH_PUMPIT_TRIGGER', fn () => $this->void($user, 'TEST-DS-V1', 'TEST-why'));
        } finally {
            $this->db()->unprepared("EXEC PumpIT.sys.sp_executesql N'DROP TRIGGER dbo.TEST_trg_DropSafe';");
        }

        foreach (['TEST-DS-V1', 'TEST-DS-V2', 'TEST-DS-V3', 'TEST-DS-V4'] as $bagNo) {
            $this->assertNotNull($this->bag($bagNo), "{$bagNo} is still in PumpIT.");
        }
        $this->assertSame(0, $this->db()->table('agora.DropSafeEvent')->where('BranchId', self::BRANCH)->where('Event', 'voided')->count(),
            'No refused void left a record: the record and the delete are one transaction.');
    }

    /* ------------------------------------------------------------ the collection */

    public function test_a_collection_takes_the_bags_out_of_the_safe_and_clears_the_console_step(): void
    {
        $user = $this->person('admin');
        $service = app(DropSafeService::class);
        $baseline = $service->consoleStep(self::BRANCH)['bags'];

        $this->drop($user, 'TEST-DS-C1', ['amount' => '2100.00', 'limitReason' => self::LIMIT_REASON]);
        $this->drop($user, 'TEST-DS-C2', ['amount' => '65.40', 'reason' => 9902, 'ref' => 'TEST-FAULT-7']);
        $this->drop($user, 'TEST-DS-C3', ['amount' => '10.00']);
        // A bag already on a cashup is collected like any other — PumpIT does it daily.
        $this->leg('TEST-DS-C3', 10);
        $this->db()->table('PumpIT.dbo.BRN_DropSafe')->where('SSBranchId', self::BRANCH)->where('BagNo', 'TEST-DS-C3')
            ->update(['IsSelectedForDailyBanking' => 1]);

        $step = $service->consoleStep(self::BRANCH);
        $this->assertSame([$baseline + 3, false], [$step['bags'], $step['done']]);
        $this->assertSame(($baseline + 3).' bags not collected', $step['detail']);

        $status = $this->collect($user, ['TEST-DS-C1', 'TEST-DS-C2', 'TEST-DS-C3', 'TEST-DS-C1'], 'TEST-DSC-2', time: '10:15', note: 'TEST-Tuesday run');

        $this->assertSame('CASH_COLLECTED', $status->Code);
        $this->assertSame(3, (int) $status->Bags, 'A bag ticked twice is taken once.');
        $this->assertEqualsWithDelta(2175.40, (float) $status->Amount, 0.00001);
        $this->assertSame($baseline, (int) $status->InSafeBags);
        $this->assertStringContainsString('3 bags for R2,175.40 collected in TEST-DSC-2', (string) $status->Message);

        $collection = $this->db()->table('PumpIT.dbo.BRN_DropSafe_Collection')->where('SSBranchId', self::BRANCH)->where('DBagNo', 'TEST-DSC-2')->sole();
        $this->assertSame((int) $status->CollectionId, (int) $collection->CollectionId, 'The id is the table\'s own, read back.');
        $this->assertSame(['TDS02', 'TEST guard', '10:15:00', self::COLLECTED.' 00:00:00'],
            [$collection->ManagerCode, $collection->SecurityName, $collection->CollectionTime, substr((string) $collection->CollectionDate, 0, 19)]);
        $this->assertSame([3.0, 2175.40], [(float) $collection->TotalNoOfBags, round((float) $collection->TotalAmount, 2)],
            'Counted and added up from the bags, never typed.');
        $this->assertNotNull($collection->CreateDateTime);

        foreach (['TEST-DS-C1', 'TEST-DS-C2', 'TEST-DS-C3'] as $bagNo) {
            $this->assertSame((int) $collection->CollectionId, (int) $this->bag($bagNo)->CollectionId, "{$bagNo} names the collection.");
        }
        $this->assertSame(1, (int) $this->bag('TEST-DS-C3')->IsSelectedForDailyBanking, 'Only CollectionId moves.');

        // THE ACCEPTANCE: the collection clears the console step.
        $step = $service->consoleStep(self::BRANCH);
        $this->assertSame($baseline, $step['bags']);
        $this->assertSame($baseline === 0, $step['done'], 'Done exactly when nothing is left in the safe.');
        if ($baseline === 0) {
            $this->assertSame('All bags collected', $step['detail']);
        }

        $event = $this->db()->table('agora.DropSafeEvent')->where('BranchId', self::BRANCH)->where('Event', 'collected')->sole();
        $this->assertSame([(int) $collection->CollectionId, 'TEST-DSC-2', 3, 'TEST-Tuesday run'],
            [(int) $event->CollectionId, $event->DBagNo, (int) $event->Bags, $event->Note]);
        $writes = json_decode((string) $event->WritesJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($writes);
        $this->assertSame(['insert', 'BRN_DropSafe_Collection'], [$writes['collection']['action'], $writes['collection']['table']]);
        $this->assertCount(3, $writes['rows']);
        $this->assertSame([0, (int) $collection->CollectionId], [$writes['rows'][0]['prior']['CollectionId'], $writes['rows'][0]['new']['CollectionId']]);

        // A bag leaves the safe once.
        $this->assertRefused('CASH_COLLECT_COLLECTED', fn () => $this->collect($user, ['TEST-DS-C1'], 'TEST-DSC-3'),
            'has already left the safe in TEST-DSC-2');
        $this->assertSame(1, $this->db()->table('PumpIT.dbo.BRN_DropSafe_Collection')->where('SSBranchId', self::BRANCH)->where('DBagNo', 'like', 'TEST-DSC-%')->count(),
            'The refused collection wrote no row: the transaction rolled back whole.');
    }

    public function test_a_security_company_bag_is_used_once_at_a_site(): void
    {
        $user = $this->person('admin');
        $this->drop($user, 'TEST-DS-D1', []);
        $this->drop($user, 'TEST-DS-D2', []);

        $first = $this->collect($user, ['TEST-DS-D1'], 'TEST-DSC-D');

        $this->assertRefused('CASH_COLLECT_DBAG_TAKEN', fn () => $this->collect($user, ['TEST-DS-D2'], 'TEST-DSC-D'),
            'Security company bag TEST-DSC-D was already used at this site — collection '.$first->CollectionId.' on '.self::COLLECTED);
        $this->assertSame(0, (int) $this->bag('TEST-DS-D2')->CollectionId, 'Still in the safe.');
        $this->assertSame(1, $this->db()->table('PumpIT.dbo.BRN_DropSafe_Collection')->where('SSBranchId', self::BRANCH)->where('DBagNo', 'TEST-DSC-D')->count());

        // A different security-company bag takes it.
        $this->assertSame('CASH_COLLECTED', $this->collect($user, ['TEST-DS-D2'], 'TEST-DSC-D2')->Code);
    }

    public function test_every_collection_refusal_is_named(): void
    {
        $user = $this->person('admin');
        $this->drop($user, 'TEST-DS-K1', []);

        $this->assertRefused('CASH_COLLECT_BAGS', fn () => $this->collect($user, [], 'TEST-DSC-9'));
        $this->assertRefused('CASH_COLLECT_NO_BAG', fn () => $this->collect($user, ['TEST-DS-K1', 'TEST-DS-NOPE'], 'TEST-DSC-9'), 'TEST-DS-NOPE');
        $this->assertRefused('CASH_COLLECT_DBAG', fn () => $this->collect($user, ['TEST-DS-K1'], ''));
        $this->assertRefused('CASH_COLLECT_MANAGER', fn () => $this->collect($user, ['TEST-DS-K1'], 'TEST-DSC-9', manager: 'TDS01234567'),
            'in 10 characters');
        $this->assertRefused('CASH_COLLECT_MANAGER', fn () => $this->collect($user, ['TEST-DS-K1'], 'TEST-DSC-9', manager: 'TNOBODY'));
        $this->assertRefused('CASH_COLLECT_SECURITY', fn () => $this->collect($user, ['TEST-DS-K1'], 'TEST-DSC-9', guard: ''));
        $this->assertRefused('CASH_COLLECT_DATE', fn () => $this->collect($user, ['TEST-DS-K1'], 'TEST-DSC-9', date: now()->addDays(2)->toDateString()));

        config(['cash.dropsafe.write_mode' => 'off']);
        $this->assertRefused('CASH_WRITES_OFF', fn () => $this->collect($user, ['TEST-DS-K1'], 'TEST-DSC-9'));
        config(['cash.dropsafe.write_mode' => 'live']);

        $this->assertSame(0, (int) $this->bag('TEST-DS-K1')->CollectionId);
        $this->assertSame(0, $this->db()->table('PumpIT.dbo.BRN_DropSafe_Collection')->where('SSBranchId', self::BRANCH)->where('DBagNo', 'like', 'TEST-DSC-%')->count());
    }

    /* ------------------------------------------------------------- the screens */

    public function test_a_refusal_reaches_the_screen_as_a_toast_carrying_its_agora_code(): void
    {
        $admin = $this->person('admin');
        $scope = ['branch_id' => self::BRANCH];
        $bag = $scope + [
            'bag_no' => 'TEST-DS-TOAST', 'drop_date' => self::DAY, 'drop_time' => '09:05', 'amount' => '2500.00',
            'reason' => 9901, 'limit_reason' => self::LIMIT_REASON, 'cashier' => 'TDS01', 'manager' => '',
        ];

        foreach (['desk' => 'app.cash.dropsafe', 'phone' => 'app.cash.dropsafe.phone'] as $from => $screen) {
            $response = $this->actingAs($admin)->post(route('app.cash.dropsafe.store'), $bag + ['from' => $from]);

            $response->assertSessionHas('dropSafeCode', 'CASH_DROP_MANAGER');
            $this->assertStringStartsWith(route($screen), (string) $response->headers->get('Location'), "Back to the {$from} screen.");

            $this->actingAs($admin)->get((string) $response->headers->get('Location'))
                ->assertOk()
                ->assertSee('data-toast-code="CASH_DROP_MANAGER"', false)
                ->assertSee('data-toast-icon="error"', false)
                ->assertSee('window.Agora.notify.toast', false)
                ->assertSee('A bag over R2,000.00 needs the manager who witnessed it named');
        }

        $this->assertNull($this->bag('TEST-DS-TOAST'), 'Refused: nothing was written.');

        // The same press with the manager goes through, and says so.
        $this->actingAs($admin)->post(route('app.cash.dropsafe.store'), array_merge($bag, ['manager' => 'TDS02', 'from' => 'phone']))
            ->assertRedirect(route('app.cash.dropsafe.phone', $scope + $this->period()))
            ->assertSessionHas('dropSafeCode', 'CASH_DROP_SAVED');
        $this->assertNotNull($this->bag('TEST-DS-TOAST'));

        // A malformed amount is the form's to refuse, not the procedure's.
        $this->actingAs($admin)->post(route('app.cash.dropsafe.store'), array_merge($bag, ['bag_no' => 'TEST-DS-X', 'amount' => '-3']))
            ->assertSessionHasErrors(['amount']);
    }

    public function test_the_screens_show_the_safe_and_offer_each_press_only_to_whoever_may_make_it(): void
    {
        $admin = $this->person('admin');
        $this->drop($admin, 'TEST-DS-S1', ['amount' => '2400.00', 'limitReason' => self::LIMIT_REASON]);
        $scope = ['branch_id' => self::BRANCH];

        $this->actingAs($admin)->get(route('app.cash.dropsafe', $scope))
            ->assertOk()
            ->assertSee('agora.usp_Cash_GetDropSafe')
            ->assertSee('In the safe:')
            ->assertSee('TEST-DS-S1')
            ->assertSee('Dropping, correcting and collecting write to PumpIT')
            ->assertSee('Drop the bag')
            ->assertSee('Collect the ticked bags')
            ->assertSee(self::LIMIT_REASON);

        $this->actingAs($admin)->get(route('app.cash.dropsafe', $scope + ['bag' => 'TEST-DS-S1']))
            ->assertOk()
            ->assertSee('Correct bag TEST-DS-S1')
            ->assertSee('Save the correction')
            ->assertSee('Void bag TEST-DS-S1')
            ->assertSee('id="dropsafe-void"', false);

        // Void through the screen: no reason is the procedure's refusal, with its code.
        $this->drop($admin, 'TEST-DS-S2', []);
        $this->actingAs($admin)->post(route('app.cash.dropsafe.void'), $scope + ['bag_no' => 'TEST-DS-S2', 'void_reason' => ''])
            ->assertRedirect()->assertSessionHas('dropSafeCode', 'CASH_VOID_REASON');
        $this->actingAs($admin)->post(route('app.cash.dropsafe.void'), $scope + ['bag_no' => 'TEST-DS-S2', 'void_reason' => 'TEST-never dropped'])
            ->assertRedirect(route('app.cash.dropsafe', $scope + $this->period()))
            ->assertSessionHas('dropSafeCode', 'CASH_DROP_VOIDED');
        $this->assertNull($this->bag('TEST-DS-S2'));

        $this->actingAs($admin)->get(route('app.cash.dropsafe.phone', $scope))
            ->assertOk()
            ->assertSee('Drop a bag')
            ->assertSee('Bag TEST-DS-S1')
            ->assertSee('In the safe:');

        // Collect through the screen.
        $this->actingAs($admin)->post(route('app.cash.dropsafe.collect'), $scope + [
            'bags' => ['TEST-DS-S1'], 'dbag_no' => 'TEST-DSC-S', 'handed_by' => 'TDS02', 'security_name' => 'TEST guard',
            'collection_date' => self::COLLECTED, 'collection_time' => '11:00',
        ])->assertRedirect()->assertSessionHas('dropSafeCode', 'CASH_COLLECTED');
        $this->assertNotSame(0, (int) $this->bag('TEST-DS-S1')->CollectionId);

        $auditor = $this->person('auditor');

        $this->actingAs($auditor)->get(route('app.cash.dropsafe', $scope))
            ->assertOk()
            ->assertSee('agora.usp_Cash_GetDropSafe')
            ->assertDontSee('id="dropsafe-drop-desk"', false)
            ->assertDontSee('id="dropsafe-collect"', false)
            ->assertDontSee('Drop the bag');

        $this->actingAs($auditor)->post(route('app.cash.dropsafe.store'), $scope + [
            'bag_no' => 'TEST-DS-A', 'drop_date' => self::DAY, 'amount' => '10', 'reason' => 9901, 'cashier' => 'TDS01', 'manager' => 'TDS02',
        ])->assertForbidden();
        $this->actingAs($auditor)->post(route('app.cash.dropsafe.update'), $scope + [
            'bag_no' => 'TEST-DS-S1', 'drop_date' => self::DAY, 'amount' => '10', 'reason' => 9901, 'cashier' => 'TDS01', 'manager' => 'TDS02',
        ])->assertForbidden();
        $this->actingAs($auditor)->post(route('app.cash.dropsafe.collect'), $scope + ['bags' => ['TEST-DS-S1']])->assertForbidden();
        $this->actingAs($auditor)->post(route('app.cash.dropsafe.void'), $scope + ['bag_no' => 'TEST-DS-S1', 'void_reason' => 'TEST'])->assertForbidden();
        $this->actingAs($auditor)->get(route('app.cash.dropsafe', $scope + ['bag' => 'TEST-DS-S1']))
            ->assertOk()->assertDontSee('id="dropsafe-void"', false);
        $this->assertNull($this->bag('TEST-DS-A'));

        // Switched off: the screens say so and no press is reachable.
        config(['cash.dropsafe.write_mode' => 'off']);
        $this->actingAs($admin)->get(route('app.cash.dropsafe', $scope))
            ->assertOk()
            ->assertSee('Writing to PumpIT is off')
            ->assertDontSee('Drop the bag');
        $this->actingAs($admin)->post(route('app.cash.dropsafe.store'), $scope + [
            'bag_no' => 'TEST-DS-OFF', 'drop_date' => self::DAY, 'amount' => '10', 'reason' => 9901, 'cashier' => 'TDS01', 'manager' => 'TDS02',
        ])->assertForbidden();
        $this->assertNull($this->bag('TEST-DS-OFF'));
    }

    /* ------------------------------------------------------ the grid and the badge */

    public function test_the_bags_grid_lists_every_bag_with_its_chip(): void
    {
        $admin = $this->person('admin');
        $this->drop($admin, 'TEST-DS-G1', ['amount' => '2200.00', 'limitReason' => self::LIMIT_REASON]);
        $this->drop($admin, 'TEST-DS-G2', ['amount' => '30.00']);
        $this->collect($admin, ['TEST-DS-G2'], 'TEST-DSC-G');

        $page = $this->procedures()->callSets('usp_Cash_GridDropSafe', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY, 'Search' => 'TEST-DS-G', 'PageSize' => 100,
        ]);
        $rows = $page[0]->keyBy('BagNo');

        $this->assertSame(['In the safe', 'Collected'], [$rows['TEST-DS-G1']->Status, $rows['TEST-DS-G2']->Status]);
        $this->assertSame('TEST-DSC-G', $rows['TEST-DS-G2']->DBagNo);
        $this->assertSame(['Agora', 'TEST-Dropsafe Thandi', 'TEST-Manager Musa'],
            [$rows['TEST-DS-G1']->CapturedIn, $rows['TEST-DS-G1']->CashierName, $rows['TEST-DS-G1']->ManagerName]);
        $this->assertSame(2, (int) $page[1]->first()->TotalRows);
        $this->assertSame('TEST-DS-G1', $page[0]->first()->BagNo, 'In the safe first.');

        // The set filter on the chip narrows the rows.
        $inSafe = $this->procedures()->callSets('usp_Cash_GridDropSafe', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY, 'Search' => 'TEST-DS-G',
            'FiltersJson' => json_encode([['column' => 'Status', 'type' => 'set', 'in' => ['Collected']]]),
        ]);
        $this->assertSame(['TEST-DS-G2'], $inSafe[0]->pluck('BagNo')->all());

        $this->actingAs($admin)->get(route('app.cash.dropsafe.bags', ['branches' => self::BRANCH, 'from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()
            ->assertSee('agora.usp_Cash_GridDropSafe')
            ->assertSee('TEST-DS-G1');
    }

    public function test_the_badge_counts_the_safe_and_links_to_the_screen(): void
    {
        $admin = $this->person('admin');
        $context = (new BranchContext)->setWorkspace('branch')->set(self::BRANCH);
        $registry = app(BadgeRegistry::class);

        $this->assertTrue($registry->has(DropSafeService::BADGE), 'Cash registers the console\'s drop safe figure.');

        $registry->forget(DropSafeService::BADGE, $context);
        $before = (int) $registry->badge(DropSafeService::BADGE, $context)?->count;

        $this->drop($admin, 'TEST-DS-B1', []);
        $registry->forget(DropSafeService::BADGE, $context);
        $badge = $registry->badge(DropSafeService::BADGE, $context);

        $this->assertNotNull($badge);
        $this->assertSame([$before + 1, 'warn', 'app.cash.dropsafe'], [$badge->count, $badge->tone, $badge->route]);
        $this->assertSame(($before + 1).(($before + 1) === 1 ? ' bag' : ' bags').' not collected', $badge->label);
    }

    public function test_the_bags_grid_is_registered(): void
    {
        // Fails until config/grids.php carries
        //   'app.cash.dropsafe.bags' => Modules\Cash\Grids\DropSafeGrid::class,
        // — the extract and the saved column layout both find a grid by key.
        $grids = (require base_path('config/grids.php'))['grids'];

        $this->assertSame(DropSafeGrid::class, $grids['app.cash.dropsafe.bags'] ?? null);
    }

    /* ---------------------------------------------------------------- fixture */

    private function seedFixture(): void
    {
        foreach (self::EMPLOYEES as [$code, $first, $surname]) {
            $this->db()->table('PumpIT.dbo.BRN_Employee')->insert([
                'SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'FirstName' => $first, 'Surname' => $surname,
                'IsActive' => 1, 'IsPumpAttendant' => 0, 'PostNo' => 0,
            ]);
        }

        foreach (self::REASONS as [$id, $text, $ref, $active]) {
            $this->db()->table('PumpIT.dbo.BRN_DropSafe_ReasonForManualBag')->insert([
                'ReasonForManualBagId' => $id, 'ReasonForManualBag' => $text, 'IsRefNoRequired' => $ref, 'IsActive' => $active,
            ]);
        }

        $this->db()->table('PumpIT.dbo.BRN_DropSafe_ReasonAmountMoreThanR2000')->insert([
            'ReasonAmountMoreThanR2000' => self::LIMIT_REASON, 'IsActive' => 1,
        ]);
    }

    private function cleanUp(): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.BRN_DropSafe')->where('SSBranchId', self::BRANCH)->where('BagNo', 'like', 'TEST-DS-%')->delete();
        $db->table('PumpIT.dbo.BRN_DropSafe_Collection')->where('SSBranchId', self::BRANCH)->where('DBagNo', 'like', 'TEST-DSC-%')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingCashBags')->where('SSBranchId', self::BRANCH)->where('CashBagNo', 'like', 'TEST-DS-%')->delete();
        $db->table('agora.DropSafeEvent')->where('BranchId', self::BRANCH)
            ->where(fn ($q) => $q->where('BagNo', 'like', 'TEST-DS-%')->orWhere('DBagNo', 'like', 'TEST-DSC-%'))->delete();
        $db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)->whereIn('EmployeeCode', array_column(self::EMPLOYEES, 0))->delete();
        $db->table('PumpIT.dbo.BRN_DropSafe_ReasonForManualBag')->whereIn('ReasonForManualBagId', array_column(self::REASONS, 0))->delete();
        $db->table('PumpIT.dbo.BRN_DropSafe_ReasonAmountMoreThanR2000')->where('ReasonAmountMoreThanR2000', 'like', 'TEST-%')->delete();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function drop(User $user, string $bagNo, array $overrides, ?string $note = null): object
    {
        return app(DropSafeService::class)->drop(self::BRANCH, $bagNo, $this->bagArgs($overrides), (int) $user->Id, $note);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function edit(User $user, string $bagNo, ?string $newBagNo, array $overrides, ?string $note = null): object
    {
        $current = $this->bag($bagNo);
        $base = $current === null ? [] : [
            'date' => substr((string) $current->DropDate, 0, 10), 'time' => $current->DropTime, 'amount' => (string) round((float) $current->Amount, 2),
            'reason' => (int) $current->ReasonForManualBagId, 'ref' => $current->RefNoFaultReported ?: null,
            'limitReason' => $current->ReasonIfAmountMoreThanR2000 ?: null, 'cashier' => $current->CashierCode, 'manager' => $current->ManagerCode,
        ];

        return app(DropSafeService::class)->edit(self::BRANCH, $bagNo, $newBagNo, $this->bagArgs($overrides + $base), (int) $user->Id, $note);
    }

    private function void(User $user, string $bagNo, ?string $reason): object
    {
        return app(DropSafeService::class)->void(self::BRANCH, $bagNo, $reason, (int) $user->Id);
    }

    /**
     * @param  array<int, string>  $bags
     */
    private function collect(User $user, array $bags, string $dbag, string $manager = 'TDS02', string $guard = 'TEST guard',
        string $date = self::COLLECTED, ?string $time = '09:00', ?string $note = null): object
    {
        return app(DropSafeService::class)->collect(self::BRANCH, $bags, [
            'dbag' => $dbag, 'manager' => $manager, 'security' => $guard, 'date' => $date, 'time' => $time,
        ], (int) $user->Id, $note);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{date: string, time: ?string, amount: string, reason: int, ref: ?string, limitReason: ?string, cashier: string, manager: ?string}
     */
    private function bagArgs(array $overrides): array
    {
        $bag = $overrides + [
            'date' => self::DAY, 'time' => '21:15:00', 'amount' => '150.00', 'reason' => 9901, 'ref' => null,
            'limitReason' => null, 'cashier' => 'TDS01', 'manager' => 'TDS02',
        ];

        return [
            'date' => (string) $bag['date'],
            'time' => $bag['time'] === null ? null : (string) $bag['time'],
            'amount' => (string) $bag['amount'],
            'reason' => (int) $bag['reason'],
            'ref' => $bag['ref'] === null ? null : (string) $bag['ref'],
            'limitReason' => $bag['limitReason'] === null ? null : (string) $bag['limitReason'],
            'cashier' => (string) $bag['cashier'],
            'manager' => $bag['manager'] === null ? null : (string) $bag['manager'],
        ];
    }

    private function leg(string $bagNo, float $amount): void
    {
        $this->db()->table('PumpIT.dbo.BRN_DailyBankingCashBags')->insert([
            'SSBranchId' => self::BRANCH, 'TillNo' => 71, 'ShiftNo' => 1, 'TransactionDate' => self::DAY.' 00:00:00',
            'CashBagNo' => $bagNo, 'CashBagAmount' => $amount, 'ReconBatchNoPumpIT' => 0,
        ]);
    }

    private function bag(string $bagNo): ?object
    {
        return $this->db()->table('PumpIT.dbo.BRN_DropSafe')->where('SSBranchId', self::BRANCH)->where('BagNo', $bagNo)->first();
    }

    /** @return Collection<int, \stdClass> */
    private function events(string $bagNo): Collection
    {
        return $this->db()->table('agora.DropSafeEvent')->where('BranchId', self::BRANCH)->where('BagNo', $bagNo)->get();
    }

    /** @return array{bags: int, amount: float} */
    private function inSafe(): array
    {
        $row = app(DropSafeService::class)->inSafe([self::BRANCH])->first();

        return ['bags' => (int) ($row->Bags ?? 0), 'amount' => (float) ($row->Amount ?? 0)];
    }

    /** @return array{date_from: string, date_to: string} */
    private function period(): array
    {
        $days = (int) config('cash.dropsafe.default_days', 14);

        return ['date_from' => now()->subDays($days - 1)->toDateString(), 'date_to' => now()->toDateString()];
    }

    private function assertRefused(string $code, callable $press, ?string $says = null): void
    {
        try {
            $press();
            $this->fail("Expected the refusal {$code}.");
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), $e->getMessage());
            if ($says !== null) {
                $this->assertStringContainsString($says, $e->getMessage());
            }
        }
    }

    private function person(string $profile): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-t039-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-'.$profile,
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        $this->grantProfile($user, $profile);
        $this->made[] = $user;

        return $user;
    }

    private function procedures(): ProcedureService
    {
        return new ProcedureService;
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
