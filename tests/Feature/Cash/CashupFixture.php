<?php

namespace Tests\Feature\Cash;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cash\Grids\CashupGrid;
use Modules\Cash\Grids\ZreadNoCashupGrid;
use Modules\Cash\Services\CashupService;
use Modules\Cash\Support\CashupScope;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserBranch;
use Modules\Core\Models\UserPermission;
use Tests\Fixtures\GrantsAccess;

/**
 * The daily-banking fixture (T037), shared by CashupTest and CashupPoolTest.
 *
 * THE CASHUPS (branch 999, 10 June 2026, all TEST- data in the LOCAL PumpIT
 * stub):
 *
 *   till 41 · day   cashier TCU01, Z-read 1 000.00 (EOD 501, log 9001, CUA),
 *                   a cashup in PumpIT with a line in every leg — including the
 *                   four quirks the legacy total has: a customer and a person
 *                   not on PumpIT's lists (not counted), a Smart ATM deposit
 *                   stamped 10:00 rather than midnight (not this cashup's at
 *                   all), and a fleet-card line with no oil amount (NULL, so
 *                   skipped). Two FNB lines, one reconciled in recon batch 777.
 *                   Actual 1 008.00, variance 8.00.
 *   till 42 · night cashier TCU02, Z-read 500.00 (EOD 501, log 9002, CUB),
 *                   allocated, and NO cashup — the "no cashup" queue's row, and
 *                   the cashup the first save creates.
 *
 * The card terminal's batch file carries, for till 41: batch 1 at 150.00 (as
 * on the leg), batch 2 at 45.00 (the leg says 40.00), batch 3 at 20.00 (not on
 * the leg), and two lines the load must ignore (no batch number, no POS
 * reference); for till 42, batch 9 at 120.00.
 *
 * THE POOLS (seedPools), one unbanked row in each, around the same day — see
 * CashupPoolTest — plus the ones that must be refused: a bag already on till
 * 41's cashup, a bag PumpIT marks banked with no cashup line, a POS account no
 * customer answers to, and an unapproved purchase request.
 *
 * Writes the LOCAL stub and Agora's database only, on branch 999, and removes
 * every row it wrote. skipUnlessLocalStub guarantees it never runs against the
 * customer's instance.
 */
trait CashupFixture
{
    use GrantsAccess;

    private const BRANCH = 999;

    private const DAY = '2026-06-10';

    private const LEGS = ['ABSA', 'CashBags', 'Cheques', 'Customers', 'Deposita', 'DirectDeposits', 'Employees', 'Expenses',
        'FleetCard', 'FNB', 'Infinity', 'ManualCard1', 'ManualCard2', 'SmartATM', 'Suppliers', 'Yumbi', 'YumbiVoucher', 'Zapper'];

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes cashups into the PumpIT stub.');

        if (! Permission::query()->withoutGlobalScopes()->where('Code', 'cash.cashup.close')->exists()) {
            $this->markTestSkipped('Run: php artisan seed:master --only=CashupPermissionSeeder');
        }

        // The lines config/grids.php carries once this lane is merged; the
        // registration itself is asserted by test_the_cashup_grids_are_registered,
        // which does NOT add them.
        foreach (['app.cash.cashups' => CashupGrid::class, 'app.cash.cashups.nocashup' => ZreadNoCashupGrid::class] as $key => $class) {
            if (! app(GridRegistry::class)->has($key)) {
                config(['grids.grids' => config('grids.grids') + [$key => $class]]);
            }
        }

        config([
            'cash.cashup.write_mode' => 'live',
            'cash.cashup.variance_tolerance' => 150.00,
        ]);

        $this->cleanUp();
        $this->seedFixture();
        $this->seedPools();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->dropTrigger();
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

    /**
     * dbo.sp_GetDailyBankingWithMOPS's EmployeeAmount for one cashup, by its
     * own joins and its own sums, over the stub.
     */
    private function legacyEmployeeAmount(int $till, int $shift): float
    {
        $sum = fn (string $sql) => "IsNull(({$sql} AND l.SSBranchId = x.SSBranchId AND l.TillNo = x.TillNo AND l.ShiftNo = x.ShiftNo),0)";

        return (float) $this->db()->selectOne("
            SELECT
              {$sum('SELECT SUM(CashBagAmount) FROM PumpIT.dbo.BRN_DailyBankingCashBags l WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(l.FuelAmount) FROM PumpIT.dbo.BRN_DailyBankingCustomers l JOIN PumpIT.dbo.BRN_Customers b ON l.SSBranchId = b.SSBranchId AND l.CustomerCode = b.CustomerCode WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(Deposited) FROM PumpIT.dbo.BRN_DailyBankingSmartATM l WHERE l.DepositDateTime = x.TransactionDate')}
            + {$sum('SELECT SUM(DepositaAmount) FROM PumpIT.dbo.BRN_DailyBankingDeposita l WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(DDAmount) FROM PumpIT.dbo.BRN_DailyBankingDirectDeposits l WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(l.AmountShort) FROM PumpIT.dbo.BRN_DailyBankingEmployees l JOIN PumpIT.dbo.BRN_Employee b ON l.SSBranchId = b.SSBranchId AND l.EmployeeCode = b.EmployeeCode WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(l.ExpenseAmount) FROM PumpIT.dbo.BRN_DailyBankingExpenses l JOIN PumpIT.dbo.BRN_Suppliers b ON l.SSBranchId = b.SSBranchId AND l.SupplierCode = b.SupplierCode WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(FleetSelling + OilAmount) FROM PumpIT.dbo.BRN_DailyBankingFleetCard l WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(l.SupplierAmount) FROM PumpIT.dbo.BRN_DailyBankingSuppliers l JOIN PumpIT.dbo.BRN_Suppliers b ON l.SSBranchId = b.SSBranchId AND l.SupplierCode = b.SupplierCode WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(TransactionAmount) FROM PumpIT.dbo.BRN_DailyBankingABSA l WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(NettTurnover) FROM PumpIT.dbo.BRN_DailyBankingZapper l WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(NetAmount) FROM PumpIT.dbo.BRN_DailyBankingYumbi l WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(RedeemedAmount) FROM PumpIT.dbo.BRN_DailyBankingYumbiVoucher l WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(Amount) FROM PumpIT.dbo.BRN_DailyBankingFNB l WHERE l.TransactionDate = x.TransactionDate')}
            + {$sum('SELECT SUM(RedeemPointsValue) FROM PumpIT.dbo.BRN_DailyBankingInfinity l WHERE l.TransactionDate = x.TransactionDate')}
            - x.ClosingBalance AS EmployeeAmount
            FROM PumpIT.dbo.BRN_DailyBanking x
            WHERE x.SSBranchId = ? AND x.TillNo = ? AND x.ShiftNo = ? AND x.TransactionDate = ?
        ", [self::BRANCH, $till, $shift, self::DAY])->EmployeeAmount;
    }

    private function seedFixture(): void
    {
        $db = $this->db();
        $pumpit = fn (string $table) => $db->table("PumpIT.dbo.{$table}");

        foreach ([41 => 'TEST-CU Till K', 42 => 'TEST-CU Till L'] as $no => $name) {
            $pumpit('BRN_Tills')->insert(['SSBranchId' => self::BRANCH, 'TillNo' => $no, 'TillDescription' => $name, 'Location' => 'WINBRANCH']);
        }
        foreach ([1 => 'TEST-CU Day', 3 => 'TEST-CU Night'] as $no => $name) {
            $pumpit('BRN_Shifts')->insert(['SSBranchId' => self::BRANCH, 'ShiftNo' => $no, 'ShiftDescription' => $name, 'IsActive' => 1]);
        }
        foreach (['TCU01' => 'Thandi', 'TCU02' => 'Sipho'] as $code => $first) {
            $pumpit('BRN_Employee')->insert(['SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'FirstName' => 'TEST-'.$first,
                'Surname' => 'Cashup', 'NickName' => null, 'IsActive' => 1, 'IsPumpAttendant' => 0, 'PostNo' => 0]);
        }
        // DebtorID / SupplierID are the POS account numbers each master row
        // answers to — how an account sale or a paid-out finds its code.
        $pumpit('BRN_Customers')->insert(['SSBranchId' => self::BRANCH, 'CustomerCode' => 'TCUC1', 'CustomerName' => 'TEST-Account customer', 'DebtorID' => 'C0005']);
        $pumpit('BRN_Suppliers')->insert(['SSBranchId' => self::BRANCH, 'SupplierCode' => 'TCUS1', 'SupplierName' => 'TEST-Supplier', 'IsCash' => 1, 'IsStock' => 0, 'isActive' => 1, 'SupplierID' => 'S0048']);

        $this->zread('501', '7', '9001', 'CUA', 41, 1, 'TCU01', 1000.00);
        $this->zread('501', '8', '9002', 'CUB', 42, 3, 'TCU02', 500.00);

        $at = self::DAY.' 00:00:00';
        $pumpit('BRN_DailyBanking')->insert([
            'SSBranchId' => self::BRANCH, 'TillNo' => 41, 'ShiftNo' => 1, 'TransactionDate' => $at, 'EmployeeCode' => 'TCU01',
            'ClosingBalance' => 1000, 'CreditCardAmount' => 0, 'CardAmount1' => 0, 'CardAmount2' => 0, 'EFuelAmount' => 0,
            'DirectDepositAmount' => 0, 'EmployeeAmount' => 8, 'Note' => null, 'DayEndNo' => '501', 'Posted' => 0,
            'Imported' => 0, 'CreateDateTime' => $at,
        ]);

        $key = ['SSBranchId' => self::BRANCH, 'TillNo' => 41, 'ShiftNo' => 1];
        $on = $key + ['TransactionDate' => $at];

        $pumpit('BRN_DailyBankingCashBags')->insert($on + ['CashBagNo' => 'TEST-BAG1', 'CashBagAmount' => 300]);
        $pumpit('BRN_DailyBankingCustomers')->insert($on + ['CustomerCode' => 'TCUC1', 'ItemNo' => 1, 'FuelAmount' => 50, 'Reference1' => 'TEST-ORD1']);
        $pumpit('BRN_DailyBankingCustomers')->insert($on + ['CustomerCode' => 'NOPE', 'ItemNo' => 1, 'FuelAmount' => 20, 'Reference1' => 'TEST-ORD2']);
        $pumpit('BRN_DailyBankingSmartATM')->insert($key + ['DepositDateTime' => $at, 'TerminalId' => 'T1', 'TraceNo' => '1', 'UniqueNo' => '1', 'Deposited' => 100]);
        $pumpit('BRN_DailyBankingSmartATM')->insert($key + ['DepositDateTime' => self::DAY.' 10:00:00', 'TerminalId' => 'T1', 'TraceNo' => '2', 'UniqueNo' => '2', 'Deposited' => 999]);
        $pumpit('BRN_DailyBankingDeposita')->insert($on + ['TerminalNo' => 'D1', 'SlipNo' => 'TEST-S1', 'DepositaAmount' => 200]);
        $pumpit('BRN_DailyBankingDirectDeposits')->insert($on + ['ItemNo' => 1, 'ItemDescription' => 'TEST-DD', 'DDAmount' => 25]);
        $pumpit('BRN_DailyBankingEmployees')->insert($on + ['EmployeeCode' => 'TCU02', 'AmountShort' => 10, 'StaffShortsNo' => 0]);
        $pumpit('BRN_DailyBankingEmployees')->insert($on + ['EmployeeCode' => 'GHOST', 'AmountShort' => 5, 'StaffShortsNo' => 0]);
        $pumpit('BRN_DailyBankingExpenses')->insert($on + ['TransactionNo' => 'CA-TEST1', 'SupplierCode' => 'TCUS1', 'ExpenseAmount' => -15]);
        $pumpit('BRN_DailyBankingFleetCard')->insert($on + ['SeqNo' => 1, 'FleetCardNo' => 'TEST-F1', 'VehicleRegNo' => 'TEST-1', 'FuelVolume' => 1, 'FleetSelling' => 40, 'OilAmount' => 5]);
        $pumpit('BRN_DailyBankingFleetCard')->insert($on + ['SeqNo' => 2, 'FleetCardNo' => 'TEST-F2', 'VehicleRegNo' => 'TEST-2', 'FuelVolume' => 1, 'FleetSelling' => 70, 'OilAmount' => null]);
        $pumpit('BRN_DailyBankingSuppliers')->insert($on + ['SupplierCode' => 'TCUS1', 'DocumentNo' => 'TEST-INV1', 'SupplierAmount' => 30]);
        $pumpit('BRN_DailyBankingABSA')->insert($on + ['RetrievalRefNumber' => 'TEST-R1', 'TransactionAuthCode' => 'A1',
            'ABSA_TransactionDate' => $at, 'ABSA_TransactionTime' => $at, 'TransactionAmount' => 60]);
        $pumpit('BRN_DailyBankingYumbi')->insert($on + ['ID' => 'TEST-Y1', 'NetAmount' => 7]);
        $pumpit('BRN_DailyBankingYumbiVoucher')->insert($on + ['RedemptionDate' => $at, 'CustomerReference' => 'TEST-V1', 'RedeemedAmount' => 3]);
        $pumpit('BRN_DailyBankingInfinity')->insert($on + ['CardHolder' => 'TEST-C', 'RewardReference' => 'R', 'RedeemReference' => 'TEST-I1',
            'RewardPurchaseValue' => 0, 'TerminalReference' => 'T', 'TerminalQuantity' => 0, 'RedeemPointsValue' => 2]);
        $pumpit('BRN_DailyBankingZapper')->insert($on + ['UniqueField' => 'TEST-Z1', 'Reference1' => 'Z', 'Status' => 'OK', 'NettTurnover' => 1]);
        $pumpit('BRN_DailyBankingFNB')->insert($on + ['BatchNo' => '0000000001', 'MerchantNo' => '100001', 'Amount' => 150, 'ReconBatchNoPumpIT' => 777]);
        $pumpit('BRN_DailyBankingFNB')->insert($on + ['BatchNo' => '0000000002', 'MerchantNo' => '100001', 'Amount' => 40, 'ReconBatchNoPumpIT' => 0]);

        // The card terminal's batch file.
        $this->card('9001', 'CUA', '7', '0000000001', 100.00, 'XXXXXXX100001', 'P1');
        $this->card('9001', 'CUA', '7', '0000000001', 50.00, 'XXXXXXX100001', 'P2');
        $this->card('9001', 'CUA', '7', '0000000002', 45.00, 'XXXXXXX100001', 'P3');
        $this->card('9001', 'CUA', '7', '0000000003', 20.00, 'XXXXXXX100001', 'P4');
        $this->card('9001', 'CUA', '7', '', 1000.00, 'XXXXXXX100001', 'P5');          // no batch: not a batch
        $this->card('9001', 'CUA', '7', '0000000004', 1000.00, 'XXXXXXX100001', '');  // no POS reference: not a sale
        $this->card('9002', 'CUB', '8', '0000000009', 120.00, 'XXXXXXX100002', 'P6');
    }

    private function zread(string $eod, string $terminal, string $log, string $user, int $till, int $shift, string $employee, float $sales): void
    {
        $this->db()->table('PumpIT.dbo.DBF_P3TRANS_ZREAD')->insert([
            'SSBranchId' => self::BRANCH, 'DATE' => self::DAY, 'EOD_CNTR' => $eod, 'TERMNUM' => $terminal, 'LOGFILE' => $log,
            'USERID' => $user, 'LOCATION' => 'WINBRANCH', 'Sales' => $sales, 'TillNo' => $till, 'ShiftNo' => $shift,
            'EmployeeCode' => $employee, 'isCaptured' => 0, 'MIST_EOD' => null,
        ]);
    }

    private function card(string $log, string $user, string $terminal, string $batch, float $amount, string $merchant, string $posRef): void
    {
        $this->db()->table('PumpIT.dbo.DBF_EFTBAT_NEWCREDITCARD')->insert([
            'SSBranchId' => self::BRANCH, 'TRCOD' => 'SALE', 'EOD_CNTR' => '501', 'CARD' => 'TEST-'.Str::random(8),
            'AMOUNT' => $amount, 'POSREF' => $posRef, 'TRANDATE' => self::DAY, 'TRANTIME' => '10:00:00', 'MERCHID' => $merchant,
            'USERID' => $user, 'TERMNUM' => $terminal, 'LOGFILE' => $log, 'BATCHNO' => $batch,
        ]);
    }

    /**
     * One unbanked row in every pool around the day, and the rows the save
     * must refuse. The drop-safe bag TEST-BAG1 is already on till 41's cashup
     * (seedFixture) and marked; TEST-BAG3 is marked banked with no cashup line.
     */
    private function seedPools(): void
    {
        $pumpit = fn (string $table) => $this->db()->table("PumpIT.dbo.{$table}");
        $day = self::DAY.' 00:00:00';

        foreach ([['TEST-BAG1', 300, 1], ['TEST-BAG2', 150, 0], ['TEST-BAG3', 60, 1]] as [$bag, $amount, $banked]) {
            $pumpit('BRN_DropSafe')->insert(['SSBranchId' => self::BRANCH, 'BagNo' => $bag, 'DropDate' => $day, 'DropTime' => '21:15:00',
                'Amount' => $amount, 'ReasonForManualBagId' => 0, 'CashierCode' => 'TCU01', 'ManagerCode' => 'TCU02',
                'IsSelectedForDailyBanking' => $banked, 'CollectionId' => 0, 'CreateDateTime' => $day]);
        }

        $pumpit('BRN_CashDevices')->insert(['SSBranchId' => self::BRANCH, 'TransactionDateTime' => self::DAY.' 21:00:00',
            'SafeId' => 'TSAFE', 'TransactionNo' => 'TEST-S2', 'TransactionValue' => 90, 'User' => 'TEST', 'TransactionType' => 'Deposit',
            'AccountNo' => 'TEST', 'IsSelectedForDailyBanking' => 0, 'CreateDateTime' => $day]);

        $pumpit('BRN_SmartATM')->insert(['SSBranchId' => self::BRANCH, 'DepositDateTime' => '2026-06-11 01:26:00', 'TerminalId' => 'TATM1',
            'TraceNo' => '3', 'UniqueNo' => '3', 'DepositorName' => 'TEST-Depositor', 'Deposited' => 70, 'Settled' => 70,
            'IsSelectedForDailyBanking' => 0, 'CreateDateTime' => $day, 'SettlementDate' => '20260611', 'SubTerminalId' => 'TSUB1',
            'SiteName' => 'TEST']);

        $pumpit('BRN_ABSAMarkoff')->insert(['SSBranchId' => self::BRANCH, 'Dtl5_TransactionDate' => $day,
            'Dtl8_TransactionTime' => '1900-01-01 14:08:13', 'Dtl9_TransactionAmount' => 55, 'Dtl23_RetrievalRefNumber' => 'TEST-R2',
            'Dtl12_TransactionAuthCode' => 'A2', 'Dtl22BatchNumber' => 7, 'Dtl3_MerchantNumber' => 'TM1', 'Dtl2_TerminalId' => 'TT1',
            'IsSelectedForDailyBanking' => 0]);

        $pumpit('BRN_Yumbi')->insert(['SSBranchId' => self::BRANCH, 'ID' => 'TEST-Y2', 'TransactionDate' => $day, 'NetAmount' => 12,
            'DiscountAmount' => 1, 'IsSelectedForDailyBanking' => null]);

        $pumpit('BRN_YumbiVoucher')->insert(['SSBranchId' => self::BRANCH, 'RedemptionDate' => '2026-05-20 10:00:00.123',
            'CustomerReference' => 'TEST-V2', 'RedeemedAmount' => 4, 'CustomerName' => 'TEST-Voucher', 'VoucherType' => 'TEST',
            'IsSelectedForDailyBanking' => 0]);

        $pumpit('BRN_Infinity')->insert(['SSBranchId' => self::BRANCH, 'TransactionDate' => $day, 'Cardholder' => 'TEST-C2',
            'RewardPurchaseValue' => 12.5, 'RewardReference' => 'R2', 'RedeemReference' => 'TEST-I2', 'TerminalReference' => 'T',
            'TerminalQuantity' => 1.25, 'RedeemPointsValue' => 2.5, 'IsSelectedForDailyBanking' => null]);

        $pumpit('BRN_Zapper')->insert(['SSBranchId' => self::BRANCH, 'TransactionDate' => $day, 'UniqueField' => 'TEST-Z2',
            'Reference1' => 'Z', 'Status' => 'OK', 'NettTurnover' => 6, 'IsSelectedForDailyBanking' => 0]);

        foreach ([['  TEST01', 'C0005', 80], ['  TEST03', 'CASH', 11]] as [$ref, $account, $total]) {
            $pumpit('DBF_INVHISTH_CUSTOMER')->insert(['SSBranchId' => self::BRANCH, 'DRC' => 'D', 'LOGFILE' => '9001', 'TYPE' => 'I',
                'REF' => $ref, 'DATE' => $day, 'CNUM' => $account, 'LOCATION' => 'WINBRANCH', 'EOD_CNTR' => '501',
                'DESC' => 'TL# 7 #1', 'CNAM' => 'TEST-POS ACCOUNT', 'TOTAL' => $total, 'USERID' => 'CUA', 'IsSelectedForBanking' => 0]);
        }

        $pumpit('DBF_POSTR_SUPPLIER')->insert(['SSBranchId' => self::BRANCH, 'DATE' => $day, 'DESC' => 'TEST-INV2', 'REF' => '  TEST02',
            'LOCATION' => 'WINBRANCH', 'EOD_CNTR' => '501', 'CNUM' => 'S0048', 'CNAM' => 'TEST-POS SUPPLIER', 'TOTAL' => 33,
            'TRCOD' => 'I', 'USERID' => 'CUA', 'IsSelectedForBanking' => 0]);

        foreach ([['CA-TEST2', 1], ['CA-TEST3', 0]] as [$request, $approved]) {
            $pumpit('BRN_Transaction')->insert(['SSBranchId' => self::BRANCH, 'TransactionNo' => $request, 'TypeCode' => '01',
                'PurchaseRequestDate' => $day, 'SupplierCode' => 'TCUS1', 'QuoteNo' => '', 'Posted' => 0, 'ShiftNo' => null,
                'IsDeclined' => 0, 'Approved' => $approved, 'Posted_WINCASHUP' => 0, 'CreateDateTime' => $day]);

            foreach ([[1, 20], [2, 5]] as [$item, $amount]) {
                $pumpit('BRN_TransactionLine')->insert(['SSBranchId' => self::BRANCH, 'TransactionNo' => $request, 'TypeCode' => '01',
                    'ItemNo' => $item, 'ExpenseNo' => 1, 'PurchaseAmount' => $amount, 'Posted' => 0, 'Imported' => 0,
                    'PastelPosted' => 0, 'IsEmailSent' => 0, 'IsApprovedEmailSent' => 0]);
            }
        }
    }

    private function cleanUp(): void
    {
        $db = $this->db();

        $db->table('agora.CashupEvent')->where('BranchId', self::BRANCH)->delete();
        $db->table('agora.Cashup')->where('BranchId', self::BRANCH)->delete();

        $db->table('PumpIT.dbo.BRN_DailyBanking')->where('SSBranchId', self::BRANCH)->delete();
        foreach (self::LEGS as $leg) {
            $db->table("PumpIT.dbo.BRN_DailyBanking{$leg}")->where('SSBranchId', self::BRANCH)->delete();
        }
        $db->table('PumpIT.dbo.DBF_EFTBAT_NEWCREDITCARD')->where('SSBranchId', self::BRANCH)->delete();
        $db->table('PumpIT.dbo.DBF_P3TRANS_ZREAD')->where('SSBranchId', self::BRANCH)->whereIn('USERID', ['CUA', 'CUB'])->delete();
        $db->table('PumpIT.dbo.BRN_Tills')->where('SSBranchId', self::BRANCH)->where('TillDescription', 'like', 'TEST-CU%')->delete();
        $db->table('PumpIT.dbo.BRN_Shifts')->where('SSBranchId', self::BRANCH)->where('ShiftDescription', 'like', 'TEST-CU%')->delete();
        $db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)->whereIn('EmployeeCode', ['TCU01', 'TCU02'])->delete();
        $db->table('PumpIT.dbo.BRN_Customers')->where('SSBranchId', self::BRANCH)->where('CustomerCode', 'TCUC1')->delete();
        $db->table('PumpIT.dbo.BRN_Suppliers')->where('SSBranchId', self::BRANCH)->where('SupplierCode', 'TCUS1')->delete();

        // The pools: only this fixture's rows — other fixtures use branch 999 too.
        $db->table('PumpIT.dbo.BRN_DropSafe')->where('SSBranchId', self::BRANCH)->where('BagNo', 'like', 'TEST-BAG%')->delete();
        $db->table('PumpIT.dbo.BRN_CashDevices')->where('SSBranchId', self::BRANCH)->where('TransactionNo', 'like', 'TEST-%')->delete();
        $db->table('PumpIT.dbo.BRN_SmartATM')->where('SSBranchId', self::BRANCH)->where('TerminalId', 'TATM1')->delete();
        $db->table('PumpIT.dbo.BRN_ABSAMarkoff')->where('SSBranchId', self::BRANCH)->where('Dtl23_RetrievalRefNumber', 'like', 'TEST-%')->delete();
        $db->table('PumpIT.dbo.BRN_Yumbi')->where('SSBranchId', self::BRANCH)->where('ID', 'like', 'TEST-%')->delete();
        $db->table('PumpIT.dbo.BRN_YumbiVoucher')->where('SSBranchId', self::BRANCH)->where('CustomerReference', 'like', 'TEST-%')->delete();
        $db->table('PumpIT.dbo.BRN_Infinity')->where('SSBranchId', self::BRANCH)->where('RedeemReference', 'like', 'TEST-%')->delete();
        $db->table('PumpIT.dbo.BRN_Zapper')->where('SSBranchId', self::BRANCH)->where('UniqueField', 'like', 'TEST-%')->delete();
        $db->table('PumpIT.dbo.DBF_INVHISTH_CUSTOMER')->where('SSBranchId', self::BRANCH)->where('REF', 'like', '%TEST0%')->delete();
        $db->table('PumpIT.dbo.DBF_POSTR_SUPPLIER')->where('SSBranchId', self::BRANCH)->where('REF', 'like', '%TEST0%')->delete();
        $db->table('PumpIT.dbo.BRN_TransactionLine')->where('SSBranchId', self::BRANCH)->where('TransactionNo', 'like', 'CA-TEST%')->delete();
        $db->table('PumpIT.dbo.BRN_Transaction')->where('SSBranchId', self::BRANCH)->where('TransactionNo', 'like', 'CA-TEST%')->delete();
    }

    /**
     * The pool list for one leg of a cashup, as the screen shows it.
     *
     * @return Collection<int, object>
     */
    private function pool(string $channel, int $till = 41, int $shift = 1): Collection
    {
        return $this->service()->pool($this->scope($till, $shift), $channel)['rows'];
    }

    /** The PoolKey of the pool row whose Reference is given. */
    private function poolKey(string $channel, string $reference, int $till = 41, int $shift = 1): string
    {
        $row = $this->pool($channel, $till, $shift)->first(fn ($r) => $r->Reference === $reference);
        $this->assertNotNull($row, "{$reference} is not offered in the {$channel} pool.");

        return (string) $row->PoolKey;
    }

    private function dropTrigger(): void
    {
        DB::connection(config('agora.connections.app'))->unprepared(
            "IF OBJECT_ID('PumpIT.dbo.TEST_trg_CashupSync') IS NOT NULL EXEC PumpIT.sys.sp_executesql N'DROP TRIGGER dbo.TEST_trg_CashupSync'"
        );
    }

    private function assertRefused(string $code, callable $press): void
    {
        try {
            $press();
            $this->fail("Expected the refusal {$code}.");
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), $e->getMessage());
        }
    }

    private function scope(int $till, int $shift): CashupScope
    {
        return new CashupScope(self::BRANCH, self::DAY, $till, $shift);
    }

    /** @return array<string, int|string> */
    private function key(int $till, int $shift): array
    {
        return ['BranchId' => self::BRANCH, 'TransactionDate' => self::DAY, 'TillNo' => $till, 'ShiftNo' => $shift];
    }

    private function one(int $till, int $shift): object
    {
        return $this->procedures()->callSets('usp_Cash_GetCashup', [
            'BranchId' => self::BRANCH, 'DateFrom' => self::DAY, 'TillNo' => $till, 'ShiftNo' => $shift,
        ])[0]->sole();
    }

    private function header(int $till, int $shift): object
    {
        return $this->db()->table('PumpIT.dbo.BRN_DailyBanking')
            ->where('SSBranchId', self::BRANCH)->where('TillNo', $till)->where('ShiftNo', $shift)->sole();
    }

    private function state(int $till, int $shift): object
    {
        return $this->db()->table('agora.Cashup')->where('BranchId', self::BRANCH)->where('TillNo', $till)->where('ShiftNo', $shift)->sole();
    }

    /** @return array<int, Collection<int, object>> */
    private function noCashup(): array
    {
        return $this->procedures()->callSets('usp_Cash_GridZreadsNoCashup', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY,
        ]);
    }

    private function person(string $profile): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-t037-'.uniqid().'@agora.invalid',
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

    private function service(): CashupService
    {
        return app(CashupService::class);
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
