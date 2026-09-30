<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Cash — daily banking and cashups (slot 12a, T037).
 *
 * A cashup is one till's shift on one trading day: what the Z-read says the
 * till took, against what the cashier declared it as — cash bags, cards, cash
 * devices, account sales, expenses paid from the till, pump shortages. The
 * difference is the variance.
 *
 * THE LEGACY SHAPE, measured on the customer's instance on 27 Sep 2026:
 *
 *   BRN_DailyBanking         the header, 167,240 rows, one per
 *                            (SSBranchId, TillNo, ShiftNo, TransactionDate) —
 *                            its primary key. ClosingBalance is the Z-read the
 *                            cashup was balanced against; EmployeeAmount is the
 *                            variance as the client last stored it. It has NO
 *                            status, confirm or close column: `Posted` is 0 on
 *                            every row, and whether a DAY is finished lives in
 *                            RCN_ReconImports (DayBalance).
 *   BRN_DailyBanking{Leg}    18 leg tables, every one keyed by that same tuple
 *                            first. Fourteen are live; Cheques, ManualCard1 and
 *                            ManualCard2 hold no rows and Zapper one (2023).
 *                            Not one leg row in any of them lacks a header at
 *                            its exact tuple (0 orphans, 2026).
 *
 * What dbo.sp_GetDailyBankingWithMOPS (the legacy Daily Banking grid) calls
 * the variance — and what the client stores as EmployeeAmount, on 4,397 of
 * 4,439 August 2026 cashups — is the sum of fifteen legs less ClosingBalance.
 * These views compute exactly that, once, so the Daily Banking screen, the
 * Cashups grid, the manager's view and the two Reports procedures cannot
 * disagree about it.
 *
 * TWO OBJECTS OF AGORA'S OWN, because the legacy has nowhere to put them:
 *
 *   agora.Cashup       a cashup's life in Agora — open, confirmed (the figures
 *                      a person agreed to), closed (a manager's sign-off). One
 *                      row per cashup, updated in place as it moves.
 *   agora.CashupEvent  every press, never deleted: who, when, the figures at
 *                      that moment, and — for a save — exactly what was
 *                      written to PumpIT with the values it replaced.
 *
 * AND FOUR VIEWS, which read the legacy estate by three-part name:
 *
 *   agora.vw_Cashup        the header, with Agora's state laid over it
 *   agora.vw_CashupLeg     every leg row of all eighteen tables, one shape
 *   agora.vw_CashupTotals  one row per cashup: the per-leg sums, ActualTotal,
 *                          Variance, the Z-read side (agora.vw_CashZread, the
 *                          effective allocation T036 maintains) and one
 *                          status word
 *   agora.vw_CashupCardBatch  the card terminal's batches, as the card load
 *                          sums them
 *   agora.vw_CashupPool    the pools the other legs are chosen from, one shape
 *
 * This migration writes nothing to the customer's databases. The procedures
 * that do are listed in their own headers and in CLAUDE.md rule 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = (string) config('agora.source_databases.erp');

        /*
         * A cashup's life in Agora. The cashup itself — its Z-read and its
         * legs — is PumpIT's; this is what Agora adds to it.
         *
         *   open       nothing confirmed, or a confirmation a later save
         *              withdrew (the figures it agreed to are gone)
         *   confirmed  a person agreed to these figures; Confirmed* keeps them
         *   closed     a manager signed it off. Nothing moves it again — a
         *              closed cashup refuses saves and confirmations — until a
         *              manager reopens it, with a reason, which puts it back
         *              to open.
         */
        MigrationHelper::table('Cashup', function (Blueprint $table) {
            $table->date('TransactionDate');
            $table->integer('TillNo');
            $table->integer('ShiftNo');
            $table->string('EmployeeCode', 11)->nullable();
            $table->string('Status', 12)->default('open');

            $table->dateTime('ConfirmedAt', 0)->nullable();
            $table->integer('ConfirmedBy')->nullable();
            MigrationHelper::money($table, 'ConfirmedZRead', nullable: true);
            MigrationHelper::money($table, 'ConfirmedActual', nullable: true);
            MigrationHelper::money($table, 'ConfirmedVariance', nullable: true);
            $table->string('ConfirmNote', 400)->nullable();

            $table->dateTime('ClosedAt', 0)->nullable();
            $table->integer('ClosedBy')->nullable();
            $table->string('CloseNote', 400)->nullable();

            // A manager may reopen a closed cashup (Ryan, 27 Sep 2026: whoever
            // holds cash.cashup.close, with a reason). The last reopening is
            // kept here; every one is an agora.CashupEvent.
            $table->dateTime('ReopenedAt', 0)->nullable();
            $table->integer('ReopenedBy')->nullable();
            $table->string('ReopenReason', 400)->nullable();

            $table->dateTime('LastSavedAt', 0)->nullable();
            $table->integer('LastSavedBy')->nullable();
        });
        MigrationHelper::rowVersion('Cashup');
        MigrationHelper::naturalKey('Cashup', ['BranchId', 'TransactionDate', 'TillNo', 'ShiftNo']);

        /*
         * Every press on a cashup, in order. Never updated, never deleted.
         *
         *   created      the PumpIT header was INSERTed by a save
         *   saved        legs (and the header's totals) were written
         *   confirmed    a person agreed to the figures
         *   unconfirmed  a save changed a confirmed cashup, so its
         *                confirmation no longer holds
         *   closed       a manager signed it off
         *   reopened     a manager reopened a closed cashup, with the reason
         *
         * WritesJson is the PumpIT side of a save, row by row:
         *   {"header": {"action": "insert|update", "prior": {...}, "new": {...}},
         *    "lines":  [{"action": "insert|update", "key": {...},
         *                "prior": {...}, "new": {...}}, ...]}
         * so what any save replaced can be read back without a restore.
         */
        MigrationHelper::table('CashupEvent', function (Blueprint $table) {
            $table->unsignedBigInteger('CashupId');
            $table->date('TransactionDate');
            $table->integer('TillNo');
            $table->integer('ShiftNo');
            $table->string('Event', 20);
            $table->string('Channel', 20)->nullable();
            $table->integer('Lines')->nullable();
            MigrationHelper::money($table, 'ZRead', nullable: true);
            MigrationHelper::money($table, 'ActualTotal', nullable: true);
            MigrationHelper::money($table, 'Variance', nullable: true);
            $table->string('WriteMode', 10)->nullable();
            $table->uuid('SaveRef')->nullable();
            $table->text('WritesJson')->nullable();
            $table->string('Note', 400)->nullable();
        });

        foreach ([
            "CREATE INDEX [IX_Cashup_Scope] ON [{$schema}].[Cashup] ([BranchId], [Status], [TransactionDate]);",
            "CREATE INDEX [IX_CashupEvent_Cashup] ON [{$schema}].[CashupEvent] ([BranchId], [CashupId], [Id]);",
            "ALTER TABLE [{$schema}].[Cashup] ADD CONSTRAINT [CK_Cashup_Status]
                CHECK ([Status] IN ('open', 'confirmed', 'closed'));",
            "ALTER TABLE [{$schema}].[CashupEvent] ADD CONSTRAINT [CK_CashupEvent_Event]
                CHECK ([Event] IN ('created', 'saved', 'confirmed', 'unconfirmed', 'closed', 'reopened'));",
            "ALTER TABLE [{$schema}].[CashupEvent] ADD CONSTRAINT [CK_CashupEvent_WriteMode]
                CHECK ([WriteMode] IS NULL OR [WriteMode] = 'live');",
            "ALTER TABLE [{$schema}].[CashupEvent] ADD CONSTRAINT [CK_CashupEvent_WritesJson]
                CHECK ([WritesJson] IS NULL OR ISJSON([WritesJson]) = 1);",
        ] as $statement) {
            DB::statement($statement);
        }

        $this->views($schema, $erp);

        MigrationHelper::recordVersion(
            '1.13',
            'Cash: daily banking — the cashup header and its eighteen legs read as one shape, the legacy variance '
            .'computed once, and agora.Cashup / agora.CashupEvent for what Agora adds: confirm, close and the trail.'
        );
    }

    private function views(string $schema, string $erp): void
    {
        /*
         * The header, as Cash reads it, with Agora's state laid over it.
         *
         * TransactionAt stays the legacy DATETIME so every join to a leg is on
         * the exact value, as the legacy's are; TransactionDate is the same
         * day as a DATE. `Note` is ntext on the customer's instance.
         *
         * `Posted` is deliberately not here: 0 on every row, it means nothing
         * and has been mistaken for "closed" before (Reports, 4 Sep 2026).
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_Cashup] AS
            SELECT
                h.SSBranchId                                  AS BranchId,
                h.TillNo,
                h.ShiftNo,
                h.TransactionDate                             AS TransactionAt,
                CONVERT(date, h.TransactionDate)              AS TransactionDate,
                LTRIM(RTRIM(h.EmployeeCode))                  AS EmployeeCode,
                LTRIM(RTRIM(h.DayEndNo))                      AS DayEndNo,
                CONVERT(money, h.ClosingBalance)              AS ClosingBalance,
                CONVERT(money, h.CreditCardAmount)            AS CreditCardAmount,
                CONVERT(money, h.CardAmount1)                 AS CardAmount1,
                CONVERT(money, h.CardAmount2)                 AS CardAmount2,
                CONVERT(money, h.EFuelAmount)                 AS EFuelAmount,
                CONVERT(money, h.DirectDepositAmount)         AS DirectDepositAmount,
                CONVERT(money, h.EmployeeAmount)              AS StoredVariance,
                CONVERT(nvarchar(max), h.Note)                AS Note,
                h.CreateDateTime                              AS CapturedAt,
                h.MIST_EOD                                    AS MistEod,
                c.Id                                          AS CashupId,
                ISNULL(c.[Status], 'open')                    AS AgoraStatus,
                c.ConfirmedAt, c.ConfirmedBy, c.ConfirmedZRead, c.ConfirmedActual, c.ConfirmedVariance, c.ConfirmNote,
                c.ClosedAt, c.ClosedBy, c.CloseNote,
                c.ReopenedAt, c.ReopenedBy, c.ReopenReason,
                c.LastSavedAt, c.LastSavedBy
            FROM [{$erp}].dbo.BRN_DailyBanking h
            OUTER APPLY (
                SELECT TOP 1 x.Id, x.[Status], x.ConfirmedAt, x.ConfirmedBy, x.ConfirmedZRead, x.ConfirmedActual,
                       x.ConfirmedVariance, x.ConfirmNote, x.ClosedAt, x.ClosedBy, x.CloseNote,
                       x.ReopenedAt, x.ReopenedBy, x.ReopenReason,
                       x.LastSavedAt, x.LastSavedBy
                FROM [{$schema}].[Cashup] x
                WHERE x.BranchId = h.SSBranchId
                  AND x.TransactionDate = CONVERT(date, h.TransactionDate)
                  AND x.TillNo = h.TillNo
                  AND x.ShiftNo = h.ShiftNo
                ORDER BY x.Id DESC
            ) c;
        ");

        /*
         * Every leg row, one shape. The legacy getter's figures, channel by
         * channel (dbo.sp_GetDailyBankingWithMOPS, read 27 Sep 2026):
         *
         *   CashBags        CashBagAmount
         *   Debtors         FuelAmount        BRN_DailyBankingCustomers, only
         *                                     where the customer is on
         *                                     BRN_Customers (an inner join there)
         *   SmartATM        Deposited         matched on DepositDateTime, NOT
         *                                     TransactionDate — the only leg that is
         *   CashDevices     DepositaAmount    BRN_DailyBankingDeposita
         *   DirectDeposits  DDAmount
         *   StaffShorts     AmountShort       BRN_DailyBankingEmployees — the pump
         *                                     shortages — only where the person
         *                                     is on BRN_Employee
         *   Expenses        ExpenseAmount     only where the supplier is on
         *                                     BRN_Suppliers
         *   FleetCard       FleetSelling + OilAmount (NULL if either is — the
         *                                     getter's SUM skips those rows)
         *   Suppliers       SupplierAmount    only where on BRN_Suppliers
         *   ABSA            TransactionAmount
         *   Zapper          NettTurnover
         *   Yumbi           NetAmount
         *   YumbiVoucher    RedeemedAmount
         *   FNB             Amount            (the getter's FNBDBF)
         *   Infinity        RedeemPointsValue
         *
         * Cheques, ManualCard1 and ManualCard2 are not in the getter's sum and
         * hold no rows; they are here, InActualTotal = 0, so a row arriving in
         * one is at least visible.
         *
         * A leg whose code is not on its master is still LISTED — with
         * InActualTotal = 0 — because the legacy total silently drops it, and
         * a line that counts for nothing should say so rather than vanish.
         *
         * ReconBatchNo is ReconBatchNoPumpIT, the live recon stamp. The plain
         * ReconBatchNo column is 0 on every row and is ignored. NULL here means
         * the leg has no stamp column at all (Customers, Employees, Expenses,
         * Suppliers, Zapper and the dead three).
         *
         * Every lookup is OUTER APPLY TOP 1 on the master's full key, so a
         * label can never change a row count.
         */
        $legs = [
            ['CashBags', 'BRN_DailyBankingCashBags', 'l.TransactionDate',
                'LTRIM(RTRIM(l.CashBagNo))', 'NULL', 'NULL', 'l.CashBagAmount', '1',
                'l.ReconBatchNoPumpIT', 'l.AdjustedBy', '', 'LTRIM(RTRIM(l.CashBagNo))', 'NULL'],
            ['Debtors', 'BRN_DailyBankingCustomers', 'l.TransactionDate',
                "LTRIM(RTRIM(l.CustomerCode)) + ' / ' + CONVERT(nvarchar(10), l.ItemNo)",
                'm.CustomerName', "LTRIM(RTRIM(ISNULL(l.Reference1, '') + ' ' + ISNULL(l.Reference2, '')))",
                'l.FuelAmount', 'CASE WHEN m.CustomerCode IS NULL THEN 0 ELSE 1 END', 'NULL', 'NULL',
                "OUTER APPLY (SELECT TOP 1 x.CustomerCode, x.CustomerName FROM [{$erp}].dbo.BRN_Customers x
                              WHERE x.SSBranchId = l.SSBranchId AND x.CustomerCode = l.CustomerCode) m", 'LTRIM(RTRIM(l.CustomerCode))', 'CONVERT(nvarchar(10), l.ItemNo)'],
            ['SmartATM', 'BRN_DailyBankingSmartATM', 'l.DepositDateTime',
                "LTRIM(RTRIM(l.TerminalId)) + ' / ' + LTRIM(RTRIM(l.TraceNo))", 'NULL', 'NULL',
                'l.Deposited', '1', 'l.ReconBatchNoPumpIT', 'l.AdjustedBy', '', 'LTRIM(RTRIM(l.TerminalId))', 'LTRIM(RTRIM(l.TraceNo))'],
            ['CashDevices', 'BRN_DailyBankingDeposita', 'l.TransactionDate',
                "LTRIM(RTRIM(l.TerminalNo)) + ' / ' + LTRIM(RTRIM(l.SlipNo))", 'NULL', 'NULL',
                'l.DepositaAmount', '1', 'l.ReconBatchNoPumpIT', 'l.AdjustedBy', '', 'LTRIM(RTRIM(l.TerminalNo))', 'LTRIM(RTRIM(l.SlipNo))'],
            ['DirectDeposits', 'BRN_DailyBankingDirectDeposits', 'l.TransactionDate',
                'CONVERT(nvarchar(10), l.ItemNo)', 'NULL', 'l.ItemDescription',
                'l.DDAmount', '1', 'l.ReconBatchNoPumpIT', 'l.AdjustedBy', '', 'CONVERT(nvarchar(10), l.ItemNo)', 'NULL'],
            ['StaffShorts', 'BRN_DailyBankingEmployees', 'l.TransactionDate',
                'LTRIM(RTRIM(l.EmployeeCode))',
                "LTRIM(RTRIM(ISNULL(m.FirstName, '') + ' ' + ISNULL(m.Surname, '')))",
                'LEFT(CONVERT(nvarchar(max), l.Reason), 200)',
                'l.AmountShort', 'CASE WHEN m.EmployeeCode IS NULL THEN 0 ELSE 1 END', 'NULL', 'NULL',
                "OUTER APPLY (SELECT TOP 1 x.EmployeeCode, x.FirstName, x.Surname FROM [{$erp}].dbo.BRN_Employee x
                              WHERE x.SSBranchId = l.SSBranchId AND x.EmployeeCode = l.EmployeeCode) m", 'LTRIM(RTRIM(l.EmployeeCode))', 'CONVERT(nvarchar(10), l.StaffShortsNo)'],
            ['Expenses', 'BRN_DailyBankingExpenses', 'l.TransactionDate',
                'LTRIM(RTRIM(l.TransactionNo))', 'm.SupplierName', 'l.ExpenseDesc',
                'l.ExpenseAmount', 'CASE WHEN m.SupplierCode IS NULL THEN 0 ELSE 1 END', 'NULL', 'NULL',
                "OUTER APPLY (SELECT TOP 1 x.SupplierCode, x.SupplierName FROM [{$erp}].dbo.BRN_Suppliers x
                              WHERE x.SSBranchId = l.SSBranchId AND x.SupplierCode = l.SupplierCode) m", 'LTRIM(RTRIM(l.TransactionNo))', 'LTRIM(RTRIM(l.SupplierCode))'],
            ['FleetCard', 'BRN_DailyBankingFleetCard', 'l.TransactionDate',
                'CONVERT(nvarchar(10), l.SeqNo)', 'LTRIM(RTRIM(l.FleetCardNo))', 'LTRIM(RTRIM(l.VehicleRegNo))',
                'l.FleetSelling + l.OilAmount', '1', 'l.ReconBatchNoPumpIT', 'NULL', '', 'CONVERT(nvarchar(10), l.SeqNo)', 'NULL'],
            ['Suppliers', 'BRN_DailyBankingSuppliers', 'l.TransactionDate',
                "LTRIM(RTRIM(l.SupplierCode)) + ' / ' + LTRIM(RTRIM(l.DocumentNo))", 'm.SupplierName', 'l.Reference',
                'l.SupplierAmount', 'CASE WHEN m.SupplierCode IS NULL THEN 0 ELSE 1 END', 'NULL', 'NULL',
                "OUTER APPLY (SELECT TOP 1 x.SupplierCode, x.SupplierName FROM [{$erp}].dbo.BRN_Suppliers x
                              WHERE x.SSBranchId = l.SSBranchId AND x.SupplierCode = l.SupplierCode) m", 'LTRIM(RTRIM(l.SupplierCode))', 'LTRIM(RTRIM(l.DocumentNo))'],
            ['ABSA', 'BRN_DailyBankingABSA', 'l.TransactionDate',
                'LTRIM(RTRIM(l.RetrievalRefNumber))', 'NULL', 'l.TransactionAuthCode',
                'l.TransactionAmount', '1', 'l.ReconBatchNoPumpIT', 'l.AdjustedBy', '', 'LTRIM(RTRIM(l.RetrievalRefNumber))', 'NULL'],
            ['Zapper', 'BRN_DailyBankingZapper', 'l.TransactionDate',
                'LTRIM(RTRIM(l.UniqueField))', 'NULL', 'l.Reference1',
                'l.NettTurnover', '1', 'NULL', 'NULL', '', 'LTRIM(RTRIM(l.UniqueField))', 'NULL'],
            ['Yumbi', 'BRN_DailyBankingYumbi', 'l.TransactionDate',
                'LTRIM(RTRIM(l.ID))', 'NULL', 'NULL',
                'l.NetAmount', '1', 'l.ReconBatchNopumpIT', 'l.AdjustedBy', '', 'LTRIM(RTRIM(l.ID))', 'NULL'],
            ['YumbiVoucher', 'BRN_DailyBankingYumbiVoucher', 'l.TransactionDate',
                'LTRIM(RTRIM(l.CustomerReference))', 'l.CustomerName', 'l.VoucherType',
                'l.RedeemedAmount', '1', 'l.ReconBatchNoPumpIT', 'l.AdjustedBy', '', 'LTRIM(RTRIM(l.CustomerReference))', 'NULL'],
            ['FNB', 'BRN_DailyBankingFNB', 'l.TransactionDate',
                "LTRIM(RTRIM(l.BatchNo)) + ' / ' + LTRIM(RTRIM(l.MerchantNo))", 'NULL', 'NULL',
                'l.Amount', '1', 'l.ReconBatchNoPumpIT', 'l.AdjustedBy', '', 'LTRIM(RTRIM(l.BatchNo))', 'LTRIM(RTRIM(l.MerchantNo))'],
            ['Infinity', 'BRN_DailyBankingInfinity', 'l.TransactionDate',
                'LTRIM(RTRIM(l.RedeemReference))', 'LTRIM(RTRIM(l.CardHolder))', 'LTRIM(RTRIM(l.RewardReference))',
                'l.RedeemPointsValue', '1', 'l.ReconBatchNopumpIT', 'l.AdjustedBy', '', 'LTRIM(RTRIM(l.RedeemReference))', 'NULL'],
            ['Cheques', 'BRN_DailyBankingCheques', 'l.TransactionDate',
                'LTRIM(RTRIM(l.ChequeNo))', 'NULL', 'l.Reference',
                'l.ChequeAmount', '0', 'NULL', 'NULL', '', 'LTRIM(RTRIM(l.ChequeNo))', 'NULL'],
            ['ManualCard1', 'BRN_DailyBankingManualCard1', 'l.TransactionDate',
                'LTRIM(RTRIM(l.BatchNo))', 'NULL', 'NULL',
                'l.Amount', '0', 'NULL', 'NULL', '', 'LTRIM(RTRIM(l.BatchNo))', 'NULL'],
            ['ManualCard2', 'BRN_DailyBankingManualCard2', 'l.TransactionDate',
                'LTRIM(RTRIM(l.BatchNo))', 'NULL', 'NULL',
                'l.Amount', '0', 'NULL', 'NULL', '', 'LTRIM(RTRIM(l.BatchNo))', 'NULL'],
        ];

        $branches = [];

        foreach ($legs as [$channel, $table, $at, $ref, $party, $detail, $amount, $counted, $stamp, $adjusted, $apply, $key1, $key2]) {
            $branches[] = "
                SELECT
                    l.SSBranchId                                  AS BranchId,
                    l.TillNo,
                    l.ShiftNo,
                    {$at}                                         AS TransactionAt,
                    CONVERT(date, {$at})                          AS TransactionDate,
                    CONVERT(varchar(20), '{$channel}')            AS Channel,
                    CONVERT(nvarchar(120), {$ref})                AS LineRef,
                    CONVERT(nvarchar(50), {$key1})                AS Key1,
                    CONVERT(nvarchar(50), {$key2})                AS Key2,
                    CONVERT(nvarchar(200), {$party})              AS Counterparty,
                    CONVERT(nvarchar(200), {$detail})             AS Detail,
                    CONVERT(money, {$amount})                     AS Amount,
                    CONVERT(bit, {$counted})                      AS InActualTotal,
                    CONVERT(int, {$stamp})                        AS ReconBatchNo,
                    CONVERT(money, {$adjusted})                   AS AdjustedBy,
                    l.CreateDateTime                              AS CreatedAt
                FROM [{$erp}].dbo.{$table} l
                {$apply}";
        }

        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_CashupLeg] AS\n".implode("\n                UNION ALL", $branches).';');

        /*
         * One row per cashup, with its figures.
         *
         *   ZRead         the header's ClosingBalance — the Z-read the cashup
         *                 was balanced against, and the one the legacy variance
         *                 is computed from
         *   ActualTotal   the fifteen legs, as the legacy getter adds them
         *   Variance      ActualTotal - ZRead: EXACTLY sp_GetDailyBankingWithMOPS's
         *                 EmployeeAmount
         *   StoredVariance  what the client last wrote to EmployeeAmount; it
         *                 differs from Variance when a leg moved after the save
         *
         * THE Z-READ SIDE is agora.vw_CashZread — the effective allocation
         * T036 maintains, not the raw PumpIT columns — joined as the legacy
         * getter joins it: the same site, till and shift, on the exact
         * TransactionDate. ZreadCheck says, in words, whether it agrees:
         *
         *   No Z-read        nothing is allocated to this till and shift that day
         *   Z-read differs   the allocated Z-reads' sales are not ClosingBalance
         *                    (more than a cent either way — the legacy
         *                    'ZReadings Discrepancies' report's own tolerance)
         *   Other cashier    allocated, but to another person or another day-end
         *                    number, so the legacy Daily Banking grid (an inner
         *                    join on both) would not show this cashup at all
         *   Agrees
         *
         * STATUS, one word, in this order:
         *   Closed        closed by a manager in Agora
         *   Confirmed     confirmed in Agora, not closed
         *   Day balanced  no Agora state, and PumpIT's day is balanced
         *                 (RCN_ReconImports.DayBalance) — the rule
         *                 usp_Reports_GridOpenCashups has used since 4 Sep 2026
         *   Open
         *
         * Every conditional sum is written ISNULL(Amount, 0) … ELSE 0 rather
         * than letting SUM skip NULLs. The figure is the same — the legacy's
         * SUM skips a NULL row, which is adding nought — but a skipped NULL
         * raises SQL Server's "Null value is eliminated by an aggregate"
         * warning, and the sqlsrv driver turns a warning ahead of a writer's
         * status row into an exception.
         *
         * IsOpen is the "Open cashups" rule, stated once for the Cashups grid
         * and the Reports queue: Open, or Confirmed on a day PumpIT has not
         * balanced — waiting on a manager. For a cashup Agora has never touched
         * that is exactly the rule usp_Reports_GridOpenCashups has used since
         * 4 Sep 2026 (the day is not balanced).
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_CashupTotals] AS
            SELECT
                h.BranchId, h.TillNo, h.ShiftNo, h.TransactionAt, h.TransactionDate,
                h.EmployeeCode, h.DayEndNo,
                h.ClosingBalance                                            AS ZRead,
                ISNULL(s.CashBags, 0)                                       AS CashBags,
                ISNULL(s.Debtors, 0)                                        AS Debtors,
                ISNULL(s.SmartATM, 0)                                       AS SmartATM,
                ISNULL(s.CashDevices, 0)                                    AS CashDevices,
                ISNULL(s.DirectDeposits, 0)                                 AS DirectDeposits,
                ISNULL(s.StaffShorts, 0)                                    AS StaffShorts,
                ISNULL(s.Expenses, 0)                                       AS Expenses,
                ISNULL(s.FleetCard, 0)                                      AS FleetCard,
                ISNULL(s.Suppliers, 0)                                      AS Suppliers,
                ISNULL(s.ABSA, 0)                                           AS ABSA,
                ISNULL(s.Zapper, 0)                                         AS Zapper,
                ISNULL(s.Yumbi, 0)                                          AS Yumbi,
                ISNULL(s.YumbiVoucher, 0)                                   AS YumbiVoucher,
                ISNULL(s.FNB, 0)                                            AS FNB,
                ISNULL(s.Infinity, 0)                                       AS Infinity,
                ISNULL(s.ABSA, 0) + ISNULL(s.FNB, 0)                        AS Cards,
                ISNULL(s.ActualTotal, 0)                                    AS ActualTotal,
                ISNULL(s.ActualTotal, 0) - ISNULL(h.ClosingBalance, 0)      AS Variance,
                h.StoredVariance,
                h.CreditCardAmount,
                ISNULL(s.Lines, 0)                                          AS LegLines,
                ISNULL(s.Reconciled, 0)                                     AS ReconciledLines,
                ISNULL(s.NotCounted, 0)                                     AS NotCountedLines,
                ISNULL(z.Zreads, 0)                                         AS Zreads,
                z.ZreadSales,
                z.ZreadEmployeeCode,
                z.ZreadEodCounter,
                CONVERT(varchar(20), CASE
                    WHEN ISNULL(z.Zreads, 0) = 0 THEN 'No Z-read'
                    WHEN ABS(ISNULL(z.ZreadSales, 0) - ISNULL(h.ClosingBalance, 0)) > 0.01 THEN 'Z-read differs'
                    WHEN ISNULL(z.Matching, 0) = 0 THEN 'Other cashier'
                    ELSE 'Agrees' END)                                      AS ZreadCheck,
                CONVERT(bit, CASE WHEN ISNULL(z.Matching, 0) > 0 THEN 1 ELSE 0 END) AS LegacyGridShows,
                CONVERT(bit, CASE WHEN r.DayBalance IS NULL THEN 0 ELSE 1 END) AS DayRecorded,
                CONVERT(bit, ISNULL(r.DayBalance, 0))                       AS DayBalanced,
                CONVERT(bit, ISNULL(r.ShortsConfirmed, 0))                  AS ShortsConfirmed,
                h.AgoraStatus,
                CONVERT(varchar(20), CASE
                    WHEN h.AgoraStatus = 'closed'    THEN 'Closed'
                    WHEN h.AgoraStatus = 'confirmed' THEN 'Confirmed'
                    WHEN ISNULL(r.DayBalance, 0) = 1 THEN 'Day balanced'
                    ELSE 'Open' END)                                        AS [Status],
                CONVERT(bit, CASE
                    WHEN h.AgoraStatus = 'closed' THEN 0
                    WHEN h.AgoraStatus = 'confirmed' THEN CASE WHEN ISNULL(r.DayBalance, 0) = 1 THEN 0 ELSE 1 END
                    WHEN ISNULL(r.DayBalance, 0) = 1 THEN 0
                    ELSE 1 END)                                             AS IsOpen,
                h.CashupId, h.ConfirmedAt, h.ConfirmedBy, h.ConfirmedZRead, h.ConfirmedActual, h.ConfirmedVariance,
                h.ConfirmNote, h.ClosedAt, h.ClosedBy, h.CloseNote,
                h.ReopenedAt, h.ReopenedBy, h.ReopenReason, h.Note, h.CapturedAt
            FROM [{$schema}].[vw_Cashup] h
            OUTER APPLY (
                SELECT
                    SUM(CASE WHEN g.Channel = 'CashBags' THEN ISNULL(g.Amount, 0) ELSE 0 END) AS CashBags,
                    SUM(CASE WHEN g.Channel = 'Debtors' AND g.InActualTotal = 1 THEN ISNULL(g.Amount, 0) ELSE 0 END) AS Debtors,
                    SUM(CASE WHEN g.Channel = 'SmartATM' THEN ISNULL(g.Amount, 0) ELSE 0 END) AS SmartATM,
                    SUM(CASE WHEN g.Channel = 'CashDevices' THEN ISNULL(g.Amount, 0) ELSE 0 END) AS CashDevices,
                    SUM(CASE WHEN g.Channel = 'DirectDeposits' THEN ISNULL(g.Amount, 0) ELSE 0 END) AS DirectDeposits,
                    SUM(CASE WHEN g.Channel = 'StaffShorts' AND g.InActualTotal = 1 THEN ISNULL(g.Amount, 0) ELSE 0 END) AS StaffShorts,
                    SUM(CASE WHEN g.Channel = 'Expenses' AND g.InActualTotal = 1 THEN ISNULL(g.Amount, 0) ELSE 0 END) AS Expenses,
                    SUM(CASE WHEN g.Channel = 'FleetCard' THEN ISNULL(g.Amount, 0) ELSE 0 END) AS FleetCard,
                    SUM(CASE WHEN g.Channel = 'Suppliers' AND g.InActualTotal = 1 THEN ISNULL(g.Amount, 0) ELSE 0 END) AS Suppliers,
                    SUM(CASE WHEN g.Channel = 'ABSA' THEN ISNULL(g.Amount, 0) ELSE 0 END) AS ABSA,
                    SUM(CASE WHEN g.Channel = 'Zapper' THEN ISNULL(g.Amount, 0) ELSE 0 END) AS Zapper,
                    SUM(CASE WHEN g.Channel = 'Yumbi' THEN ISNULL(g.Amount, 0) ELSE 0 END) AS Yumbi,
                    SUM(CASE WHEN g.Channel = 'YumbiVoucher' THEN ISNULL(g.Amount, 0) ELSE 0 END) AS YumbiVoucher,
                    SUM(CASE WHEN g.Channel = 'FNB' THEN ISNULL(g.Amount, 0) ELSE 0 END) AS FNB,
                    SUM(CASE WHEN g.Channel = 'Infinity' THEN ISNULL(g.Amount, 0) ELSE 0 END) AS Infinity,
                    SUM(CASE WHEN g.InActualTotal = 1 THEN ISNULL(g.Amount, 0) ELSE 0 END) AS ActualTotal,
                    COUNT(*)                                                      AS Lines,
                    SUM(CASE WHEN ISNULL(g.ReconBatchNo, 0) <> 0 THEN 1 ELSE 0 END) AS Reconciled,
                    SUM(CASE WHEN g.InActualTotal = 0 AND g.Channel NOT IN ('Cheques', 'ManualCard1', 'ManualCard2')
                             THEN 1 ELSE 0 END)                                   AS NotCounted
                FROM [{$schema}].[vw_CashupLeg] g
                WHERE g.BranchId = h.BranchId
                  AND g.TillNo = h.TillNo
                  AND g.ShiftNo = h.ShiftNo
                  AND g.TransactionAt = h.TransactionAt
            ) s
            OUTER APPLY (
                SELECT
                    COUNT(*)                                   AS Zreads,
                    CONVERT(money, SUM(ISNULL(q.Sales, 0))) AS ZreadSales,
                    MAX(q.EmployeeCode)                        AS ZreadEmployeeCode,
                    MAX(q.EodCounter)                          AS ZreadEodCounter,
                    SUM(q.IsMatch)                             AS Matching
                FROM (
                    SELECT x.Sales, x.EmployeeCode, x.EodCounter,
                           CASE WHEN x.EmployeeCode = h.EmployeeCode AND x.EodCounter = h.DayEndNo
                                THEN 1 ELSE 0 END AS IsMatch
                    FROM [{$schema}].[vw_CashZread] x
                    WHERE x.BranchId = h.BranchId
                      AND x.ZReadAt = h.TransactionAt
                      AND x.TillNo = h.TillNo
                      AND x.ShiftNo = h.ShiftNo
                ) q
            ) z
            OUTER APPLY (
                SELECT TOP 1 ri.DayBalance, ri.ShortsConfirmed
                FROM [{$erp}].dbo.RCN_ReconImports ri
                WHERE ri.SSBranchId = h.BranchId AND ri.ReconDate = h.TransactionAt
            ) r;
        ");

        /*
         * The card terminal's batches, as dbo.sp_Load_BranchCreditCardAmount
         * sums them: one row per (EOD, log file, operator, batch, terminal,
         * merchant), over the lines that carry a batch number and a POS
         * reference. MerchantNo is characters 8–13 of MERCHID, exactly as the
         * legacy cuts it — that is what BRN_DailyBankingFNB.MerchantNo holds.
         *
         * 8.5 million lines on the customer's instance: read it with the site,
         * and the EOD and log file of the Z-read, or not at all.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_CashupCardBatch] AS
            SELECT
                c.SSBranchId                           AS BranchId,
                LTRIM(RTRIM(c.EOD_CNTR))               AS EodCounter,
                LTRIM(RTRIM(c.LOGFILE))                AS LogFile,
                LTRIM(RTRIM(c.USERID))                 AS PosUserId,
                LTRIM(RTRIM(c.TERMNUM))                AS TerminalNo,
                LTRIM(RTRIM(c.BATCHNO))                AS BatchNo,
                SUBSTRING(c.MERCHID, 8, 6)             AS MerchantNo,
                SUM(ISNULL(c.AMOUNT, 0))               AS Amount,
                COUNT(*)                               AS CardLines
            FROM [{$erp}].dbo.DBF_EFTBAT_NEWCREDITCARD c
            WHERE c.BATCHNO <> '' AND c.POSREF <> ''
            GROUP BY c.SSBranchId, LTRIM(RTRIM(c.EOD_CNTR)), LTRIM(RTRIM(c.LOGFILE)), LTRIM(RTRIM(c.USERID)),
                     LTRIM(RTRIM(c.TERMNUM)), LTRIM(RTRIM(c.BATCHNO)), SUBSTRING(c.MERCHID, 8, 6);
        ");

        $this->poolView($schema, $erp);
    }

    /**
     * The pools a cashup's legs are chosen from, one shape (Ryan's Q1, 27 Sep
     * 2026: the leg and the pool row's banked mark together).
     *
     * In the legacy a leg is not typed. The cashier picks rows from a pool —
     * the drop safe's bags, the cash device and Smart ATM feeds, the ABSA
     * mark-off file, Yumbi, Yumbi vouchers, Infinity, Zapper, the POS account
     * sales and supplier paid-outs, the approved cash purchase requests — the
     * row is copied onto the cashup, and the pool row is marked as banked so
     * it cannot be banked twice. dbo.sp_IsSelectedForDailyBanking_DailyBanking_Update
     * states which pool row answers to which leg row, pool by pool; the
     * columns copied were measured on the customer's instance (27 Sep 2026,
     * every leg row of August 2026 against its pool row):
     *
     *   CashBags      BRN_DropSafe           CashBagAmount = Amount (349 of 349)
     *   CashDevices   BRN_CashDevices        DepositaAmount = TransactionValue (4,502 of 4,502)
     *   SmartATM      BRN_SmartATM           Deposited, SettlementDate, SubTerminalId (11,093 of 11,093)
     *   ABSA          BRN_ABSAMarkoff        Dtl9 amount, Dtl22 batch, Dtl3 merchant, Dtl5/Dtl8/Dtl12/Dtl23 (22,212 of 22,212)
     *   Yumbi         BRN_Yumbi              NetAmount, DiscountAmount (498 of 498)
     *   YumbiVoucher  BRN_YumbiVoucher       RedeemedAmount, CustomerName, VoucherType (149 of 149)
     *   Infinity      BRN_Infinity           RedeemPointsValue and the six key columns (312 of 312)
     *   Zapper        BRN_Zapper             the same-named columns (one row, 2023)
     *   Debtors       DBF_INVHISTH_CUSTOMER  FuelAmount = TOTAL; the customer is the BRN_Customers
     *                                        row whose DebtorID is the POS account (CNUM)
     *   Suppliers     DBF_POSTR_SUPPLIER     SupplierAmount = TOTAL; the supplier is the BRN_Suppliers
     *                                        row whose SupplierID is the POS account (88 of 88)
     *   Expenses      BRN_Transaction        approved cash requests (TypeCode 01, not declined);
     *                                        ExpenseAmount = the sum of the lines (495 of 495)
     *
     * PoolKey is the pool row's primary key as a JSON object — dates in ISO 8601
     * (style 126), money and float as text so they come back exact —
     * which is what the screen posts and agora.usp_Cash_SaveCashupLeg parses.
     *
     * IsBanked is the pool's own mark. MasterCode is the customer or supplier
     * code the leg would carry, and MasterMatches how many master rows claim
     * the POS account (anything but one is refused by the save).
     */
    private function poolView(string $schema, string $erp): void
    {
        $iso = fn (string $column) => "CONVERT(varchar(23), {$column}, 126)";

        $pools = [
            ['CashBags', "[{$erp}].dbo.BRN_DropSafe p", 'p.DropDate', 'LTRIM(RTRIM(p.BagNo))',
                'LTRIM(RTRIM(p.CashierCode))', "CONCAT('dropped ', LTRIM(RTRIM(p.DropTime)))", 'p.Amount',
                'p.IsSelectedForDailyBanking', 'p.BagNo AS BagNo',
                'NULL', 'NULL', 'LTRIM(RTRIM(p.CashierCode))', 'NULL', 'NULL', ''],
            ['CashDevices', "[{$erp}].dbo.BRN_CashDevices p", 'p.TransactionDateTime',
                "LTRIM(RTRIM(p.SafeId)) + ' / ' + LTRIM(RTRIM(p.TransactionNo))", 'LTRIM(RTRIM(p.[User]))',
                'p.TransactionType', 'p.TransactionValue', 'p.IsSelectedForDailyBanking',
                $iso('p.TransactionDateTime').' AS TransactionDateTime, p.SafeId AS SafeId, p.TransactionNo AS TransactionNo',
                'NULL', 'NULL', 'NULL', 'NULL', 'NULL', ''],
            ['SmartATM', "[{$erp}].dbo.BRN_SmartATM p", 'p.DepositDateTime',
                "LTRIM(RTRIM(p.TerminalId)) + ' / ' + LTRIM(RTRIM(p.TraceNo))", 'p.DepositorName',
                "CONCAT('settles ', p.SettlementDate)", 'p.Deposited', 'p.IsSelectedForDailyBanking',
                $iso('p.DepositDateTime').' AS DepositDateTime, p.TraceNo AS TraceNo, p.TerminalId AS TerminalId',
                'NULL', 'NULL', 'NULL', 'NULL', 'NULL', ''],
            ['ABSA', "[{$erp}].dbo.BRN_ABSAMarkoff p", 'p.Dtl5_TransactionDate',
                'LTRIM(RTRIM(p.Dtl23_RetrievalRefNumber))', "CONCAT('terminal ', p.Dtl2_TerminalId)",
                "CONCAT('auth ', p.Dtl12_TransactionAuthCode, ' · batch ', p.Dtl22BatchNumber)", 'p.Dtl9_TransactionAmount',
                'p.IsSelectedForDailyBanking',
                $iso('p.Dtl5_TransactionDate').' AS TransactionDate, '.$iso('p.Dtl8_TransactionTime').' AS TransactionTime, '
                .'CONVERT(varchar(30), p.Dtl9_TransactionAmount, 2) AS Amount, p.Dtl23_RetrievalRefNumber AS RetrievalRefNumber',
                'NULL', 'NULL', 'NULL', 'NULL', 'NULL', ''],
            ['Yumbi', "[{$erp}].dbo.BRN_Yumbi p", 'p.TransactionDate', 'LTRIM(RTRIM(p.ID))',
                "LTRIM(RTRIM(ISNULL(p.FirstName, '') + ' ' + ISNULL(p.LastName, '')))",
                "CONCAT(p.Channel, ' · ', p.OrderType)", 'p.NetAmount', 'ISNULL(p.IsSelectedForDailyBanking, 0)',
                'p.ID AS ID', 'NULL', 'NULL', 'NULL', 'NULL', 'NULL', ''],
            ['YumbiVoucher', "[{$erp}].dbo.BRN_YumbiVoucher p", 'p.RedemptionDate', 'LTRIM(RTRIM(p.CustomerReference))',
                'p.CustomerName', 'p.VoucherType', 'p.RedeemedAmount', 'p.IsSelectedForDailyBanking',
                $iso('p.RedemptionDate').' AS RedemptionDate, p.CustomerReference AS CustomerReference',
                'NULL', 'NULL', 'NULL', 'NULL', 'NULL', ''],
            ['Infinity', "[{$erp}].dbo.BRN_Infinity p", 'p.TransactionDate', 'LTRIM(RTRIM(p.RedeemReference))',
                'LTRIM(RTRIM(p.Cardholder))', 'LTRIM(RTRIM(p.RewardReference))', 'CONVERT(money, p.RedeemPointsValue)',
                'ISNULL(p.IsSelectedForDailyBanking, 0)',
                $iso('p.TransactionDate').' AS TransactionDate, p.Cardholder AS Cardholder, '
                .'CONVERT(varchar(40), p.RewardPurchaseValue, 3) AS RewardPurchaseValue, p.RewardReference AS RewardReference, '
                .'p.RedeemReference AS RedeemReference, p.TerminalReference AS TerminalReference, '
                .'CONVERT(varchar(40), p.TerminalQuantity, 3) AS TerminalQuantity',
                'NULL', 'NULL', 'NULL', 'NULL', 'NULL', ''],
            ['Zapper', "[{$erp}].dbo.BRN_Zapper p", 'p.TransactionDate', 'LTRIM(RTRIM(p.UniqueField))',
                'p.CustomerName', 'p.Reference1', 'p.NettTurnover', 'p.IsSelectedForDailyBanking',
                $iso('p.TransactionDate').' AS TransactionDate, p.UniqueField AS UniqueField',
                'NULL', 'NULL', 'NULL', 'NULL', 'NULL', ''],
            ['Debtors', "[{$erp}].dbo.DBF_INVHISTH_CUSTOMER p", 'p.[DATE]', 'LTRIM(RTRIM(p.REF))',
                'LTRIM(RTRIM(p.CNAM))', 'LTRIM(RTRIM(p.[DESC]))', 'CONVERT(money, p.TOTAL)', 'p.IsSelectedForBanking',
                'p.DRC AS DRC, p.LOGFILE AS LOGFILE, p.[TYPE] AS [TYPE], p.REF AS REF, '.$iso('p.[DATE]').' AS [DATE], '
                .'p.CNUM AS CNUM, p.[LOCATION] AS [LOCATION], p.EOD_CNTR AS EOD_CNTR',
                'LTRIM(RTRIM(p.EOD_CNTR))', 'LTRIM(RTRIM(p.USERID))', 'NULL', 'm.Code', 'm.Matches',
                "OUTER APPLY (SELECT MIN(x.CustomerCode) AS Code, COUNT(*) AS Matches FROM [{$erp}].dbo.BRN_Customers x
                              WHERE x.SSBranchId = p.SSBranchId AND LTRIM(RTRIM(x.DebtorID)) = LTRIM(RTRIM(p.CNUM))) m"],
            ['Suppliers', "[{$erp}].dbo.DBF_POSTR_SUPPLIER p", 'p.[DATE]', 'LTRIM(RTRIM(p.REF))',
                'LTRIM(RTRIM(p.CNAM))', 'LTRIM(RTRIM(p.[DESC]))', 'CONVERT(money, p.TOTAL)', 'p.IsSelectedForBanking',
                $iso('p.[DATE]').' AS [DATE], p.[DESC] AS [DESC], p.REF AS REF, p.[LOCATION] AS [LOCATION], p.EOD_CNTR AS EOD_CNTR',
                'LTRIM(RTRIM(p.EOD_CNTR))', 'LTRIM(RTRIM(p.USERID))', 'NULL', 'm.Code', 'm.Matches',
                "OUTER APPLY (SELECT MIN(x.SupplierCode) AS Code, COUNT(*) AS Matches FROM [{$erp}].dbo.BRN_Suppliers x
                              WHERE x.SSBranchId = p.SSBranchId AND LTRIM(RTRIM(x.SupplierID)) = LTRIM(RTRIM(p.CNUM))) m"],
            ['Expenses', "[{$erp}].dbo.BRN_Transaction p", 'p.PurchaseRequestDate', 'LTRIM(RTRIM(p.TransactionNo))',
                's.SupplierName', 'd.Description', 'd.Amount', 'p.Posted',
                'p.TransactionNo AS TransactionNo, p.TypeCode AS TypeCode',
                'NULL', 'NULL', 'NULL', 'p.SupplierCode', 'CASE WHEN s.SupplierName IS NULL THEN 0 ELSE 1 END',
                "OUTER APPLY (SELECT TOP 1 x.SupplierName FROM [{$erp}].dbo.BRN_Suppliers x
                              WHERE x.SSBranchId = p.SSBranchId AND x.SupplierCode = p.SupplierCode) s
                OUTER APPLY (SELECT CONVERT(money, SUM(ISNULL(y.PurchaseAmount, 0))) AS Amount,
                                    LEFT(MIN(CONVERT(nvarchar(200), y.PurchaseRequestDescription)), 200) AS Description
                             FROM [{$erp}].dbo.BRN_TransactionLine y
                             WHERE y.SSBranchId = p.SSBranchId AND y.TransactionNo = p.TransactionNo AND y.TypeCode = p.TypeCode) d",
                "p.TypeCode = '01' AND p.Approved = 1 AND p.IsDeclined = 0"],
        ];

        $branches = [];

        foreach ($pools as $pool) {
            [$channel, $from, $date, $ref, $party, $detail, $amount, $banked, $key, $eod, $user, $cashier, $master, $matches, $apply] = $pool;
            $where = $pool[15] ?? null;

            $branches[] = "
                SELECT
                    p.SSBranchId                                   AS BranchId,
                    CONVERT(varchar(20), '{$channel}')             AS Channel,
                    {$date}                                        AS PoolDate,
                    CONVERT(nvarchar(120), {$ref})                 AS Reference,
                    CONVERT(nvarchar(200), {$party})               AS Counterparty,
                    CONVERT(nvarchar(200), {$detail})              AS Detail,
                    CONVERT(money, {$amount})                      AS Amount,
                    CONVERT(bit, ISNULL({$banked}, 0))             AS IsBanked,
                    CONVERT(nvarchar(20), {$eod})                  AS PosEod,
                    CONVERT(nvarchar(20), {$user})                 AS PosUserId,
                    CONVERT(nvarchar(20), {$cashier})              AS CashierCode,
                    CONVERT(nvarchar(20), {$master})               AS MasterCode,
                    CONVERT(int, {$matches})                       AS MasterMatches,
                    k.PoolKey
                FROM {$from}
                {$apply}
                CROSS APPLY (SELECT (SELECT {$key} FOR JSON PATH, WITHOUT_ARRAY_WRAPPER) AS PoolKey) k"
                .($where !== null ? "\n                WHERE {$where}" : '');
        }

        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_CashupPool] AS\n".implode("\n                UNION ALL", $branches).';');
    }

    /**
     * Live and staging are forward-only; this runs against the local sandbox
     * only. Never rely on it to undo something in production.
     */
    public function down(): void
    {
        $schema = config('agora.schema');

        foreach (['vw_CashupPool', 'vw_CashupCardBatch', 'vw_CashupTotals', 'vw_CashupLeg', 'vw_Cashup'] as $view) {
            DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[{$view}];");
        }

        MigrationHelper::drop('CashupEvent');
        MigrationHelper::drop('Cashup');
    }
};
