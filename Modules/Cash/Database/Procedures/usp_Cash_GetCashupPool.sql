/* ============================================================================
   agora.usp_Cash_GetCashupPool

   WHAT IT IS FOR
   "Add from PumpIT" on a leg of the Daily Banking screen: the rows of that
   leg's pool — the drop safe's bags, the cash device and Smart ATM deposits,
   the ABSA mark-off file, Yumbi, Yumbi vouchers, Infinity, Zapper, the POS
   account sales and supplier paid-outs, the approved cash purchase requests —
   that nobody has banked yet, around the cashup's day, for a person to tick.

   The pools and what each leg copies are agora.vw_CashupPool's (v1__12a);
   this only chooses the rows. The pump-shortages leg (StaffShorts, T040) is
   chosen from the site's APPROVED staff shorts — dbo.sp_Load_ApprovedStaffShorts
   ported: Approved, not declined, not yet on a cashup, the person on
   BRN_Employee — read off agora.vw_CashStaffShort (v1__12d); ticking one and
   saving POSTS it. A short a cash-bag match raised is never offered: deductions
   read it directly (Ryan, PM decision 01a0e5b5). Nothing is written — ticking a row and saving
   is agora.usp_Cash_SaveCashupLeg, which copies the row onto the cashup and
   marks the pool row banked in the same transaction.

   WHICH ROWS
     · this site's, of this leg's pool
     · not marked banked in PumpIT (the pool's own IsSelectedForDailyBanking,
       IsSelectedForBanking, or Posted for a purchase request)
     · dated from @DaysBefore before the cashup's day to @DaysAfter after it —
       the application passes a window per leg from cash.cashup.pool_windows,
       measured on the customer's instance: bags, devices, Smart ATM, ABSA and
       Yumbi land on the shift's day or the next (a night shift's deposits are
       stamped after midnight); a supplier paid-out within three days either
       way; a purchase request within a month; a Yumbi voucher often weeks
       later

   Each row carries a Hint — why it is probably this cashup's — and is sorted
   by it:
     this cashier     a drop-safe bag dropped by the cashup's own cashier
     this till's EOD  a POS row from the Z-read's own day-end number
     raised for this cashup   a staff short raised in Agora for this till,
                      shift and day
     (blank)          anything else in the window
   A staff short's window is wide (cash.cashup.pool_windows): the legacy offers
   every approved short whatever its date, and a short raised in PumpIT is
   dated when it was captured, usually a day or more after its shift.
   and, for accounts and supplier paid-outs, the customer or supplier the leg
   would carry (MasterCode) or why it cannot be banked yet (Problem): a POS
   account no PumpIT master row answers to, or two that do.

   Result sets:
     1  the rows, with PoolKey — the pool row's key as JSON, which is what the
        screen posts back
     2  one row: the window, how many rows are available in it and their total

   READ-ONLY.

     EXEC agora.usp_Cash_GetCashupPool @BranchId = 6, @TransactionDate = '2026-05-22',
          @TillNo = 11, @ShiftNo = 1, @Channel = 'CashBags', @DaysBefore = 3, @DaysAfter = 3;

   Refusals:
     AGORA:CASH_CASHUP_KEY    site, day, till and shift are all required
     AGORA:CASH_POOL_CHANNEL  a leg with no pool (FNB comes from the card file;
                              the held legs are not offered)

     EXEC agora.usp_Cash_GetCashupPool @BranchId = 6, @TransactionDate = '2026-09-22',
          @TillNo = 11, @ShiftNo = 1, @Channel = 'StaffShorts', @DaysBefore = 400, @DaysAfter = 400;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GetCashupPool]
    @BranchId         INT,
    @TransactionDate  DATE,
    @TillNo           INT,
    @ShiftNo          INT,
    @Channel          NVARCHAR(20),
    @DaysBefore       INT = 3,
    @DaysAfter        INT = 3,
    @MaxRows          INT = 500
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;   -- the only write is to #gp_rows, but check-procs is right to ask

    IF @BranchId IS NULL OR @TransactionDate IS NULL OR @TillNo IS NULL OR @ShiftNo IS NULL
        THROW 51000, 'AGORA:CASH_CASHUP_KEY:A cashup is one site, one day, one till and one shift. Give all four.', 1;

    IF @Channel IS NULL OR @Channel NOT IN ('CashBags', 'CashDevices', 'SmartATM', 'ABSA', 'Yumbi', 'YumbiVoucher',
                                            'Infinity', 'Zapper', 'Debtors', 'Suppliers', 'Expenses', 'StaffShorts')
        THROW 51000, 'AGORA:CASH_POOL_CHANNEL:That leg is not chosen from a pool.', 1;

    SET @DaysBefore = CASE WHEN ISNULL(@DaysBefore, 3) BETWEEN 0 AND 400 THEN @DaysBefore ELSE 3 END;
    SET @DaysAfter  = CASE WHEN ISNULL(@DaysAfter, 3) BETWEEN 0 AND 400 THEN @DaysAfter ELSE 3 END;
    SET @MaxRows    = CASE WHEN ISNULL(@MaxRows, 500) BETWEEN 1 AND 5000 THEN @MaxRows ELSE 500 END;

    DECLARE @From DATETIME = DATEADD(day, -@DaysBefore, CONVERT(DATETIME, @TransactionDate));
    DECLARE @To   DATETIME = DATEADD(day, @DaysAfter + 1, CONVERT(DATETIME, @TransactionDate));

    /* Who the cashup is, and its Z-read's day-end number — for the hint. */
    DECLARE @Cashier NVARCHAR(22), @Eod NVARCHAR(50);

    SELECT TOP 1 @Cashier = h.EmployeeCode, @Eod = h.DayEndNo
    FROM agora.vw_Cashup h
    WHERE h.BranchId = @BranchId AND h.TillNo = @TillNo AND h.ShiftNo = @ShiftNo
      AND h.TransactionAt = CONVERT(DATETIME, @TransactionDate);

    IF @Cashier IS NULL
        SELECT TOP 1 @Cashier = z.EmployeeCode, @Eod = z.EodCounter
        FROM agora.vw_CashZread z
        WHERE z.BranchId = @BranchId AND z.ZReadAt = CONVERT(DATETIME, @TransactionDate)
          AND z.TillNo = @TillNo AND z.ShiftNo = @ShiftNo;

    SELECT
        p.Channel, p.PoolKey, p.PoolDate, p.Reference, p.Counterparty, p.Detail, p.Amount,
        p.MasterCode, p.PosEod, p.PosUserId, p.CashierCode,
        CONVERT(NVARCHAR(40), CASE
            WHEN p.Channel = 'CashBags' AND p.CashierCode = @Cashier THEN 'this cashier'
            WHEN p.Channel IN ('Debtors', 'Suppliers') AND p.PosEod = @Eod THEN 'this till''s EOD'
            END) AS Hint,
        CONVERT(NVARCHAR(200), CASE
            WHEN p.Channel = 'Debtors' AND ISNULL(p.MasterMatches, 0) = 0
                 THEN 'POS account ' + ISNULL(p.Counterparty, '') + ' is on no PumpIT customer (DebtorID)'
            WHEN p.Channel = 'Suppliers' AND ISNULL(p.MasterMatches, 0) = 0
                 THEN 'POS supplier ' + ISNULL(p.Counterparty, '') + ' is on no PumpIT supplier (SupplierID)'
            WHEN p.Channel IN ('Debtors', 'Suppliers') AND p.MasterMatches > 1
                 THEN CONVERT(NVARCHAR(10), p.MasterMatches) + ' PumpIT masters claim this POS account'
            WHEN p.Channel = 'Expenses' AND ISNULL(p.MasterMatches, 0) = 0
                 THEN 'the request''s supplier is not on PumpIT''s list'
            END) AS Problem
    INTO #gp_rows
    FROM agora.vw_CashupPool p
    WHERE p.BranchId = @BranchId
      AND p.Channel = @Channel
      AND @Channel <> 'StaffShorts'
      AND p.PoolDate >= @From AND p.PoolDate < @To
      AND p.IsBanked = 0;

    /* The approved staff shorts (T040) — dbo.sp_Load_ApprovedStaffShorts. */
    IF @Channel = 'StaffShorts'
        INSERT INTO #gp_rows (Channel, PoolKey, PoolDate, Reference, Counterparty, Detail, Amount, MasterCode,
                              PosEod, PosUserId, CashierCode, Hint, Problem)
        SELECT 'StaffShorts',
               (SELECT s.StaffShortsNo AS StaffShortsNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
               s.TransactionAt,
               CONCAT(N'Short ', s.StaffShortsNo),
               LEFT(CONCAT(ISNULL(s.EmployeeName + N' · ', N''), s.EmployeeCode), 200),
               LEFT(CONCAT(s.ReasonLabel, ISNULL(N' · ' + s.Note, N'')), 200),
               s.AmountShort,
               NULL, NULL, NULL,
               s.EmployeeCode,
               CASE WHEN s.ForTillNo = @TillNo AND s.ForShiftNo = @ShiftNo AND s.ForDate = @TransactionDate
                    THEN N'raised for this cashup' END,
               NULL
        FROM agora.vw_CashStaffShort s
        WHERE s.BranchId = @BranchId
          AND s.[Status] = 'Approved'
          AND s.OnEmployeeList = 1
          AND ISNULL(s.Source, '') <> 'recon'    -- a cash-bag match's short is never a cashup line (PM decision 01a0e5b5)
          AND s.TransactionAt >= @From AND s.TransactionAt < @To;

    SELECT TOP (@MaxRows) *
    FROM #gp_rows
    ORDER BY CASE WHEN Hint IS NULL THEN 1 ELSE 0 END,
             ABS(DATEDIFF(minute, CONVERT(DATETIME, @TransactionDate), PoolDate)),
             Reference;

    SELECT
        CONVERT(DATE, @From)                               AS WindowFrom,
        CONVERT(DATE, DATEADD(day, -1, @To))               AS WindowTo,
        COUNT(*)                                           AS Available,
        CONVERT(MONEY, ISNULL(SUM(ISNULL(Amount, 0)), 0))  AS Total,
        SUM(CASE WHEN Hint IS NOT NULL THEN 1 ELSE 0 END)  AS Hinted,
        @MaxRows                                           AS MaxRows
    FROM #gp_rows;

    DROP TABLE #gp_rows;
END
