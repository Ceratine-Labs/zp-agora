<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reports — the legacy estate, as Agora is allowed to see it (slot 22).
 *
 * This module owns no tables. Every figure on a Today screen already exists in
 * the customer's databases; what was missing was a way to read it that does not
 * write, does not fan out, and does not depend on a PumpIT procedure we cannot
 * change. So the module is a set of views and a set of procedures over them.
 *
 * WHY VIEWS AT ALL, when the procedure could name the table directly:
 *
 *  1. `SSBranchId` becomes `BranchId`. One name, everywhere, so BranchScope and
 *     every procedure signature agree without a translation step.
 *  2. `ntext` is converted once. `BRN_StaffShorts.Reason`, `.Note` and the two
 *     purchase-request narrative columns are ntext, which cannot be compared,
 *     sorted or grouped — a grid's `@Search` parameter over a raw ntext column
 *     is a runtime error, not a slow query.
 *  3. Reserved-word columns are bracketed once. `DBF_P3TRANS_ZREAD.[DATE]` is a
 *     syntax error unbracketed and there are five procedures that read it.
 *  4. The columns are ENUMERATED, never `SELECT *`. A view over a star binds its
 *     column list at creation and does not notice an ALTER TABLE, so a legacy
 *     table gaining a column silently produces a view that is wrong about its
 *     own shape. Enumerating is also what keeps `SS_Users.Password` — a
 *     plaintext varchar(50) on 100-odd rows — out of Agora entirely.
 *
 * The legacy database is NAMED from config (`PumpIT.dbo.…`), because Agora's
 * objects do not live inside it any more and a restore is commonly called
 * something else.
 *
 * WHAT IS DELIBERATELY NOT HIDDEN. A view that filters rows is a view that
 * makes a total unexplainable. Every "only the open ones" rule lives in the
 * procedure, where the customer can read it and change it, not in the view.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        foreach ($this->views($erp) as $name => $body) {
            DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[{$name}] AS".$body.';');
        }

        MigrationHelper::recordVersion(
            '1.2',
            'Reports: the legacy estate behind the Today screens — thirty views over PumpIT '
            .'and fifteen grid procedures, all read-only.'
        );
    }

    /**
     * @return array<string, string>
     */
    protected function views(string $erp): array
    {
        return [

            /* ---------------------------------------------------------------
             | The day
             --------------------------------------------------------------- */

            // The day-close ledger: one row per branch per trading day, with a
            // bit per step. Which bits are ALIVE was measured on 4 Sep 2026
            // over 4,201 branch-days in 2026, and it matters, because a report
            // that filters on a dead bit returns nothing and says nothing:
            //
            //   DayBalance          3 428  the day balanced          LIVE
            //   ShortsConfirmed     2 113  shorts signed off         LIVE
            //   CBRecon             1 834  cash bags reconciled      LIVE
            //   DirectDepositsRecon 1 828  direct deposits           LIVE
            //   SmartATMRecon       1 821  Smart ATM                 LIVE
            //   DepositaRecon       1 817  cash machine              LIVE
            //   ABSARecon           1 726  ABSA card settlement      LIVE
            //   FNBRecon               90  FNB card settlement       BARELY
            //   HOImport               87  head-office import        DYING
            //   SiteClosedOff           0  (13 rows ever, all 2023)  DEAD
            //   ReadyToImport           0                            DEAD
            //   PastelImport            0                            DEAD
            //
            // FNBRecon's 90 is the same population as the six zero-match FNB
            // branches in the recon findings — it is not a reporting fault.
            // The dead four are exposed anyway, because the day someone asks
            // "why does nothing say closed off" the answer must be visible in
            // the data rather than in a migration comment.
            'vw_ReconImports' => "
                SELECT r.SSBranchId          AS BranchId,
                       r.ReconDate,
                       r.DayBalance,
                       r.ShortsConfirmed,
                       r.ABSARecon,
                       r.CBRecon,
                       r.FNBRecon,
                       r.DepositaRecon,
                       r.SmartATMRecon,
                       r.DirectDepositsRecon,
                       r.SiteClosedOff,
                       r.HOImport,
                       r.PastelImport,
                       r.ReadyToImport,
                       r.CashierShort,
                       r.PumpShort,
                       r.ZReading,
                       r.TOIncl,
                       r.TotalAmount,
                       r.EODNo,
                       r.CreateDateTime
                FROM [{$erp}].dbo.RCN_ReconImports r",

            'vw_DayEnd' => "
                SELECT d.SSBranchId          AS BranchId,
                       d.DayEndDate,
                       d.DayEndTypeNo,
                       d.DayEndNo,
                       d.FuelTO,
                       d.QShopTO,
                       d.LottoTO,
                       d.VVTO,
                       d.OilTO,
                       d.FastFoodTO,
                       d.CarWashTO,
                       d.OtherTO,
                       d.DayEndTO,
                       d.Imported,
                       d.CreateDateTime
                FROM [{$erp}].dbo.BRN_DayEnd d",

            'vw_DayEndType' => "
                SELECT t.SSBranchId          AS BranchId,
                       t.DayEndTypeNo,
                       t.DayEndTypeDescription
                FROM [{$erp}].dbo.BRN_DayEndType t",

            // Till readings as the POS loader landed them. `DATE` is a reserved
            // word and is the whole reason this view exists; `EOD_CNTR`,
            // `TERMNUM`, `LOGFILE` and `USERID` are fixed-width CHAR columns
            // carrying trailing spaces, so they are trimmed here — comparing an
            // untrimmed CHAR(8) against a parameter is the classic silent
            // no-match on this table.
            'vw_ZRead' => "
                SELECT z.SSBranchId          AS BranchId,
                       z.[DATE]              AS ZReadDate,
                       LTRIM(RTRIM(z.EOD_CNTR)) AS EodCounter,
                       LTRIM(RTRIM(z.TERMNUM))  AS TerminalNo,
                       LTRIM(RTRIM(z.LOGFILE))  AS LogFile,
                       LTRIM(RTRIM(z.USERID))   AS PosUserId,
                       z.Sales,
                       z.TillNo,
                       z.ShiftNo,
                       z.EmployeeCode,
                       z.isCaptured          AS IsCaptured,
                       z.MIST_EOD            AS MistEod
                FROM [{$erp}].dbo.DBF_P3TRANS_ZREAD z",

            /* ---------------------------------------------------------------
             | Waiting on a decision
             --------------------------------------------------------------- */

            // The purchase-request HEADER. One row per request; the money is on
            // the lines and is aggregated there before anything joins to it.
            'vw_PurchaseRequest' => "
                SELECT t.SSBranchId          AS BranchId,
                       t.TransactionNo,
                       t.TypeCode,
                       t.SupplierCode,
                       t.PurchaseRequestDate,
                       t.QuoteNo,
                       t.ShiftNo,
                       t.EmailAddress,
                       t.Approved,
                       t.ApprovedByUserId,
                       t.ApprovedDate,
                       t.IsDeclined,
                       t.DeclinedByUserId,
                       t.DeclinedDate,
                       t.DeclinedReason,
                       t.Posted,
                       t.CreateDateTime
                FROM [{$erp}].dbo.BRN_Transaction t",

            // The lines. Both narrative columns are ntext and are converted
            // here: NVARCHAR(MAX) sorts, compares and takes a LIKE, and ntext
            // does none of the three.
            'vw_PurchaseRequestLine' => "
                SELECT l.SSBranchId          AS BranchId,
                       l.TransactionNo,
                       l.TypeCode,
                       l.ItemNo,
                       l.ExpenseNo,
                       l.PurchaseAmount,
                       CONVERT(nvarchar(max), l.PurchaseRequestDescription)   AS Description,
                       CONVERT(nvarchar(max), l.PurchaseRequestJustification) AS Justification,
                       l.InvoiceNo,
                       l.InvoiceDate,
                       l.IsEmailSent,
                       l.IsApprovedEmailSent,
                       l.Posted,
                       l.VATInd
                FROM [{$erp}].dbo.BRN_TransactionLine l",

            'vw_TransactionType' => "
                SELECT y.SSBranchId          AS BranchId,
                       y.TypeCode,
                       y.TypeDescription
                FROM [{$erp}].dbo.BRN_TransactionType y",

            'vw_Supplier' => "
                SELECT s.SSBranchId          AS BranchId,
                       s.SupplierCode,
                       s.SupplierName,
                       s.IsCash,
                       s.IsStock,
                       s.isActive            AS IsActive
                FROM [{$erp}].dbo.BRN_Suppliers s",

            // The expense code carries the APPROVER. That is the join that
            // decides whose queue a purchase request lands in, and it is on the
            // line's expense rather than on the request.
            'vw_Expense' => "
                SELECT e.SSBranchId          AS BranchId,
                       e.ExpenseNo,
                       e.ExpenseDescription,
                       e.GLCode,
                       e.ApproverUserId,
                       e.IsAsset,
                       e.IsActive,
                       e.Vat
                FROM [{$erp}].dbo.BRN_Expenses e",

            // Not branch-scoped in the legacy estate — five rows, group-wide.
            // The group branch id is supplied so that everything Agora reads
            // still carries a BranchId and can be scoped like everything else.
            'vw_ApproverLevel' => '
                SELECT CONVERT(int, '.(int) config('agora.group_branch_id').") AS BranchId,
                       a.ApproverUserlevelId AS ApproverUserLevelId,
                       a.ApproverUserLevel
                FROM [{$erp}].dbo.BRN_ApproverUserlevel a",

            // The legacy operator accounts, for attribution only — who approved,
            // who declined, who captured. `Password` (plaintext, varchar(50)) is
            // not enumerated and therefore cannot be read through Agora at all.
            'vw_ErpUser' => '
                SELECT CONVERT(int, '.(int) config('agora.group_branch_id').") AS BranchId,
                       u.Autoidx             AS ErpUserId,
                       u.UserName,
                       u.UserEmailAddress,
                       u.UserTypeId,
                       u.isLocked            AS IsLocked
                FROM [{$erp}].dbo.SS_Users u",

            'vw_StaffShort' => "
                SELECT s.SSBranchId          AS BranchId,
                       s.StaffShortsNo,
                       s.EmployeeCode,
                       s.TransactionDate,
                       s.AmountShort,
                       CONVERT(nvarchar(max), s.Reason) AS Reason,
                       CONVERT(nvarchar(max), s.Note)   AS Note,
                       s.CreatedByUserId,
                       s.Approved,
                       s.ApprovedByUserId,
                       s.ApprovedDate,
                       s.IsDeclined,
                       s.DeclinedReason,
                       s.DeclinedByUserId,
                       s.DeclinedDate,
                       s.CreateDateTime
                FROM [{$erp}].dbo.BRN_StaffShorts s",

            // The drop safe, at bag grain.
            //
            // OVERLAP, ON PURPOSE, AND IT SHOULD NOT SURVIVE: the Recon module
            // already publishes agora.vw_DropSafe and agora.vw_DropSafeCollection
            // over these same two tables, exposing three columns each — all its
            // procedures need to resolve a bag to its collection's reference.
            // The collections REPORT needs the amounts, the times, the manager,
            // the security company and the manual-bag reason, so it cannot use
            // them, and widening a view that another module's already-applied
            // migration owns would leave two files defining one object.
            //
            // Two views over one table is the lesser wrong of the two, but it is
            // still wrong: whichever module lands second should fold these into
            // one pair. Flagged for Ryan rather than decided here.
            'vw_DropSafeBag' => "
                SELECT d.SSBranchId          AS BranchId,
                       d.BagNo,
                       d.CollectionId,
                       d.Amount,
                       d.DropDate,
                       d.DropTime,
                       d.CashierCode,
                       d.ManagerCode,
                       d.ReasonForManualBagId,
                       d.ReasonIfAmountMoreThanR2000,
                       d.RefNoFaultReported,
                       d.IsSelectedForDailyBanking,
                       d.CreateDateTime
                FROM [{$erp}].dbo.BRN_DropSafe d",

            'vw_DropSafeCollectionHeader' => "
                SELECT c.SSBranchId          AS BranchId,
                       c.CollectionId,
                       c.DBagNo,
                       c.CollectionDate,
                       c.CollectionTime,
                       c.ManagerCode,
                       c.SecurityName,
                       c.TotalAmount,
                       c.TotalNoOfBags,
                       c.CreateDateTime
                FROM [{$erp}].dbo.BRN_DropSafe_Collection c",

            // Group-wide reference data, six rows.
            'vw_DropSafeReason' => '
                SELECT CONVERT(int, '.(int) config('agora.group_branch_id').") AS BranchId,
                       r.ReasonForManualBagId,
                       r.ReasonForManualBag,
                       r.IsRefNoRequired,
                       r.IsActive
                FROM [{$erp}].dbo.BRN_DropSafe_ReasonForManualBag r",

            /* ---------------------------------------------------------------
             | Capture
             --------------------------------------------------------------- */

            'vw_PumpReading' => "
                SELECT p.SSBranchId          AS BranchId,
                       p.PumpNo,
                       p.ReadingDate,
                       p.OpenReading,
                       p.CloseReading,
                       p.OpenReadingElectronic,
                       p.CloseReadingElectronic,
                       p.POSSales,
                       p.IsClocked,
                       p.Imported,
                       p.CreateDateTime
                FROM [{$erp}].dbo.BRN_PumpReadings p",

            'vw_Pump' => "
                SELECT p.SSBranchId          AS BranchId,
                       p.PumpNo,
                       p.FuelTypeNo,
                       p.TankNo,
                       p.IsActive
                FROM [{$erp}].dbo.BRN_Pump p",

            'vw_FuelInput' => "
                SELECT f.SSBranchId          AS BranchId,
                       f.FuelTypeNo,
                       f.FuelDate,
                       f.FuelVolume,
                       f.FuelDip,
                       f.FuelDelivery,
                       f.Imported,
                       f.CreateDateTime
                FROM [{$erp}].dbo.BRN_FuelInput f",

            // The price IN FORCE on a day, which is not on the fuel type.
            //
            // BRN_FuelType.CostPrice and .SellingPrice are 0 on all 41 rows,
            // measured 4 Sep 2026 — they are a shape nobody fills in. The real
            // prices are BRN_FuelPrice, one row per price CHANGE per branch and
            // fuel type, so the price on a given day is the latest row on or
            // before it. A report that reads the fuel type instead values every
            // litre at zero and says nothing while doing it.
            'vw_FuelPrice' => "
                SELECT p.SSBranchId          AS BranchId,
                       p.FuelTypeNo,
                       p.[Date]              AS EffectiveFrom,
                       p.CostPrice,
                       p.SellingPrice,
                       p.Margin,
                       p.SellingPriceMatrix,
                       p.IsFleetCard
                FROM [{$erp}].dbo.BRN_FuelPrice p",

            'vw_FuelType' => "
                SELECT t.SSBranchId          AS BranchId,
                       t.FuelTypeNo,
                       t.FuelTypeDescription,
                       t.CostPrice,
                       t.SellingPrice,
                       t.IsFleetCard
                FROM [{$erp}].dbo.BRN_FuelType t",

            // The cashup as the branch captured it. `Note` is ntext.
            //
            // `Posted` is exposed and MUST NOT be used as "this cashup is
            // closed": it is 0 on all 164,019 rows, measured 4 Sep 2026. Whether
            // a day is finished is answered by vw_ReconImports.DayBalance.
            'vw_DailyBanking' => "
                SELECT b.SSBranchId          AS BranchId,
                       b.TransactionDate,
                       b.ShiftNo,
                       b.TillNo,
                       b.EmployeeCode,
                       b.ClosingBalance,
                       b.CreditCardAmount,
                       b.CardAmount1,
                       b.CardAmount2,
                       b.EFuelAmount,
                       b.DirectDepositAmount,
                       b.EmployeeAmount,
                       CONVERT(nvarchar(max), b.Note) AS Note,
                       b.DayEndNo,
                       b.Posted,
                       b.Imported,
                       b.CreateDateTime
                FROM [{$erp}].dbo.BRN_DailyBanking b",

            // The pump attendant's short, which is a different row from the
            // cashier's variance on vw_DailyBanking and is often confused with
            // it. StaffShortsNo links it to an approved deduction.
            'vw_DailyBankingEmployee' => "
                SELECT e.SSBranchId          AS BranchId,
                       e.TransactionDate,
                       e.ShiftNo,
                       e.TillNo,
                       e.EmployeeCode,
                       e.AmountShort,
                       CONVERT(nvarchar(max), e.Reason) AS Reason,
                       CONVERT(nvarchar(max), e.Note)   AS Note,
                       e.StaffShortsNo
                FROM [{$erp}].dbo.BRN_DailyBankingEmployees e",

            'vw_Till' => "
                SELECT t.SSBranchId          AS BranchId,
                       t.TillNo,
                       t.TillDescription,
                       t.[Location]          AS PosLocation
                FROM [{$erp}].dbo.BRN_Tills t",

            'vw_Shift' => "
                SELECT s.SSBranchId          AS BranchId,
                       s.ShiftNo,
                       s.ShiftDescription,
                       s.IsActive
                FROM [{$erp}].dbo.BRN_Shifts s",

            // People, for naming a row. Nothing here is payroll: the legacy
            // table carries bank accounts, ID numbers, tax numbers and a
            // photograph, and none of them are enumerated.
            'vw_Employee' => "
                SELECT e.SSBranchId          AS BranchId,
                       e.EmployeeCode,
                       LTRIM(RTRIM(e.FirstName)) AS FirstName,
                       LTRIM(RTRIM(e.Surname))   AS Surname,
                       LTRIM(RTRIM(ISNULL(e.Surname, '') + ' ' + ISNULL(e.FirstName, ''))) AS EmployeeName,
                       e.NickName,
                       e.PostNo,
                       e.IsPumpAttendant,
                       e.IsActive
                FROM [{$erp}].dbo.BRN_Employee e",

            // The count header — which branch, day, shift and area were counted.
            'vw_StockRecon' => "
                SELECT s.SSBranchId          AS BranchId,
                       s.TransactionDate,
                       s.ShiftNo,
                       s.AreaNo,
                       s.Imported,
                       s.CreateDateTime
                FROM [{$erp}].dbo.STK_StockRecon s",

            // The count itself, 7.1 million lines.
            //
            // BOTH the plain and the `_Original` columns are exposed, and the
            // procedure reads `_Original`. The plain ones are what balancing
            // has amended since; the originals are what was counted. The legacy
            // capture screen loads the originals, and a variance computed from
            // a mixture of the two is a number that belongs to neither.
            'vw_StockReconLine' => "
                SELECT l.SSBranchId          AS BranchId,
                       l.TransactionDate,
                       l.ShiftNo,
                       l.AreaNo,
                       l.StockItemNo,
                       l.SellPrice,
                       l.QtyOpen_Original,
                       l.QtyIssued_Original,
                       l.QtyClose_Original,
                       l.QtyComputer_Original,
                       l.QtyOpen,
                       l.QtyIssued,
                       l.QtyClose,
                       l.QtyComputer
                FROM [{$erp}].dbo.STK_StockReconLine l",

            'vw_StockMaster' => "
                SELECT m.SSBranchId          AS BranchId,
                       m.StockItemNo,
                       m.StockItemDescription,
                       m.[Location]          AS StockLocation,
                       m.UOMCode,
                       m.SellingPrice,
                       m.AreaNo,
                       m.QtyVarAllowance,
                       m.isMonitoredItem     AS IsMonitoredItem,
                       m.POSCode
                FROM [{$erp}].dbo.STK_StockMaster m",

            // Counting areas, and which shifts each is counted on. The three
            // shift bits plus ShowReport are how the legacy 'missing count'
            // report knows what SHOULD have been counted.
            'vw_StockArea' => "
                SELECT a.SSBranchId          AS BranchId,
                       a.AreaNo,
                       a.AreaDescription,
                       a.AreaGroup,
                       a.DayShift,
                       a.AfternoonShift,
                       a.NightShift,
                       a.ShowReport,
                       a.IsCaptureWaste
                FROM [{$erp}].dbo.STK_Area a",

            'vw_StockWasteLine' => "
                SELECT w.SSBranchId          AS BranchId,
                       w.TransactionDate,
                       w.ShiftNo,
                       w.AreaNo,
                       w.StockItemNo,
                       w.QtyGoodWaste,
                       w.QtyBadWaste,
                       w.CreateDateTime
                FROM [{$erp}].dbo.STK_StockWasteLine w",

            // Meter readings. `Usage` and `OpeningReading` are STORED, and the
            // legacy select procedure recalculates them with an UPDATE every
            // time it is run — which is why the stored values cannot be trusted
            // to be current and why Agora, which will never issue that UPDATE,
            // derives both in the procedure instead. Both are exposed so the
            // derived figure can be shown against the stored one.
            'vw_UtilityTransaction' => "
                SELECT t.UtilityTransactionId,
                       t.SSBranchId          AS BranchId,
                       t.TransactionDate,
                       t.UtilityType,
                       t.MeterNo,
                       t.OpeningReading      AS StoredOpeningReading,
                       t.ClosingReading,
                       t.PrepaidUnitsPurchased,
                       t.[Usage]             AS StoredUsage,
                       t.CreateDateTime
                FROM [{$erp}].dbo.BRN_UtilityTransaction t",

            'vw_UtilityMeter' => "
                SELECT m.UtilityMeterId,
                       m.SSBranchId          AS BranchId,
                       m.UtilityType,
                       m.MeterNo,
                       m.[Description]       AS MeterDescription
                FROM [{$erp}].dbo.BRN_UtilityMeter m",

            'vw_UtilityType' => '
                SELECT CONVERT(int, '.(int) config('agora.group_branch_id').") AS BranchId,
                       u.UtilityTypeId,
                       u.Code                AS UtilityType,
                       u.[Description]       AS UtilityTypeDescription
                FROM [{$erp}].dbo.SS_UtilityType u",

            // What each branch is configured to import, and from where.
            'vw_PosImportSelection' => "
                SELECT s.SSBranchId          AS BranchId,
                       s.POSType,
                       s.POSImportType,
                       s.ImportFromDrive,
                       s.MIST_EOD            AS MistEod,
                       s.CreateDateTime
                FROM [{$erp}].dbo.BRN_POSImportSelection s",

            // The loader's own account of itself: four per-feed high-water
            // marks kept on the branch row. They say when a feed last ran, not
            // whether last night's run was complete — that question is answered
            // by counting what landed, which is what usp_Reports_GridOvernightLoads
            // does. Both halves are needed: a feed can stamp a date and load
            // nothing.
            'vw_BranchImportStatus' => "
                SELECT b.SSBranchId          AS BranchId,
                       b.BranchName,
                       b.POSType,
                       b.IsPOSImport,
                       b.IsARCHImport,
                       b.IsMSACCESSImport,
                       b.IsActive,
                       b.BRN_LastImportDateStamp   AS BranchLastImportAt,
                       b.STK_LastImportDateStamp   AS StockLastImportAt,
                       b.RCN_LastImportDateStamp   AS ReconLastImportAt,
                       b.NAMOS_LastImportDateStamp AS NamosLastImportAt
                FROM [{$erp}].dbo.SS_Branch b",
        ];
    }

    /**
     * Live and staging are forward-only; this runs against the local sandbox
     * only. Never rely on it to undo something in production.
     */
    public function down(): void
    {
        $schema = config('agora.schema');

        foreach (array_keys($this->views(config('agora.source_databases.erp'))) as $view) {
            DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[{$view}];");
        }
    }
};
