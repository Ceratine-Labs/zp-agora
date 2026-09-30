/* ============================================================================
   agora.usp_Cash_GetCashup

   WHAT IT IS FOR
   The Daily Banking screen: one site's cashups for a day — or, as the legacy
   grid allows, a period — each with its Z-read, its legs, what it actually
   totals and its variance; and, for ONE cashup, its leg lines, the Z-reads
   behind it and every press made on it.

   THE PORT. dbo.sp_GetDailyBankingWithMOPS is the legacy Daily Banking grid.
   Its figures, per cashup:

     fifteen leg sums   CashBags, Debtors, SmartATM, CashDevices, DirectDeposits,
                        StaffShorts, Expenses, FleetCard, Suppliers, ABSA, Zapper,
                        Yumbi, YumbiVoucher, FNB (its FNBDBF), Infinity
     EmployeeAmount     the sum of the fifteen less ClosingBalance

   Here those are the columns of the same names, ActualTotal is the sum and
   Variance is its EmployeeAmount — computed once, in agora.vw_CashupLeg and
   agora.vw_CashupTotals, so nothing else re-derives them. ZRead is the
   header's ClosingBalance, which is the figure the legacy variance is taken
   from.

   WHAT IT DOES DIFFERENTLY, deliberately:
     · The legacy grid INNER JOINs the Z-read on the cashier and the day-end
       number, so a cashup whose Z-read was allocated to somebody else — or
       never allocated — is not on its screen at all (33 of 4,439 in August
       2026). Here every cashup is listed, and ZreadCheck says which it is.
     · The Z-read side is agora.vw_CashZread, the effective allocation T036
       keeps, not the raw PumpIT columns.
     · The legacy date filter compares a formatted string; this is a range on
       the DATETIME.

   Arguments, as the legacy's (an empty string there is NULL here):
     @BranchId      the site — required
     @DateFrom      the day, or the first day
     @DateTo        the last day; the same day when NULL
     @TillNo, @ShiftNo, @DayEndNo, @EmployeeCode   optional narrowing

   Result sets, always all four, in this order:
     1  the cashups — one row per BRN_DailyBanking header
     2  the leg lines of ONE cashup — returned only when the day is one day and
        both @TillNo and @ShiftNo are given; empty otherwise
     3  the Z-reads at the site on that one day, allocated or not, with the
        cashup each belongs to (HasCashup) — the "not captured yet" list;
        empty for a period
     4  the presses made on that one cashup, newest first (agora.CashupEvent)

   READ-ONLY.

     EXEC agora.usp_Cash_GetCashup @BranchId = 6, @DateFrom = '2026-05-22';
     EXEC agora.usp_Cash_GetCashup @BranchId = 6, @DateFrom = '2026-05-22', @TillNo = 11, @ShiftNo = 1;

   Refusals:
     AGORA:CASH_CASHUP_SITE   no site
     AGORA:CASH_CASHUP_RANGE  no day, or a period that ends before it starts
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GetCashup]
    @BranchId      INT,
    @DateFrom      DATE,
    @DateTo        DATE          = NULL,
    @TillNo        INT           = NULL,
    @ShiftNo       INT           = NULL,
    @DayEndNo      NVARCHAR(50)  = NULL,
    @EmployeeCode  NVARCHAR(11)  = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @BranchId IS NULL
        THROW 51000, 'AGORA:CASH_CASHUP_SITE:Choose a site. A cashup belongs to one site.', 1;

    SET @DateTo = ISNULL(@DateTo, @DateFrom);

    IF @DateFrom IS NULL OR @DateTo < @DateFrom
        THROW 51000, 'AGORA:CASH_CASHUP_RANGE:Give the day, or a period that ends on or after it starts.', 1;

    SET @DayEndNo     = NULLIF(LTRIM(RTRIM(@DayEndNo)), '');
    SET @EmployeeCode = NULLIF(LTRIM(RTRIM(@EmployeeCode)), '');

    DECLARE @From DATETIME = @DateFrom, @To DATETIME = DATEADD(day, 1, CONVERT(DATETIME, @DateTo));
    DECLARE @One BIT = CASE WHEN @DateFrom = @DateTo AND @TillNo IS NOT NULL AND @ShiftNo IS NOT NULL THEN 1 ELSE 0 END;

    /* 1. The cashups. */
    SELECT
        t.BranchId, t.TransactionDate, t.TillNo, tl.TillDescription, t.ShiftNo, sh.ShiftDescription,
        t.EmployeeCode, e.EmployeeName, t.DayEndNo,
        t.ZRead,
        t.CashBags, t.Debtors, t.SmartATM, t.CashDevices, t.DirectDeposits, t.StaffShorts, t.Expenses,
        t.FleetCard, t.Suppliers, t.ABSA, t.Zapper, t.Yumbi, t.YumbiVoucher, t.FNB, t.Infinity,
        t.Cards, t.ActualTotal, t.Variance, t.StoredVariance, t.CreditCardAmount,
        t.LegLines, t.ReconciledLines, t.NotCountedLines,
        t.Zreads, t.ZreadSales, t.ZreadEmployeeCode, t.ZreadEodCounter, t.ZreadCheck, t.LegacyGridShows,
        t.DayBalanced, t.ShortsConfirmed, t.AgoraStatus, t.[Status], t.CashupId,
        t.ConfirmedAt, cu.UserName AS ConfirmedByName, t.ConfirmedZRead, t.ConfirmedActual, t.ConfirmedVariance, t.ConfirmNote,
        t.ClosedAt, xu.UserName AS ClosedByName, t.CloseNote,
        t.ReopenedAt, ru.UserName AS ReopenedByName, t.ReopenReason,
        /* A confirmation is of figures. When they have moved since — a leg
           loaded late, a recon adjustment — the confirmation no longer
           describes the cashup, and a close is refused until it is redone. */
        CONVERT(BIT, CASE WHEN t.AgoraStatus IN ('confirmed', 'closed')
                           AND (ABS(ISNULL(t.ConfirmedZRead, 0) - ISNULL(t.ZRead, 0)) > 0.005
                                OR ABS(ISNULL(t.ConfirmedActual, 0) - t.ActualTotal) > 0.005)
                          THEN 1 ELSE 0 END) AS FiguresMoved,
        t.Note, t.CapturedAt
    FROM agora.vw_CashupTotals t
    OUTER APPLY (SELECT TOP 1 x.TillDescription FROM agora.vw_Till x WHERE x.BranchId = t.BranchId AND x.TillNo = t.TillNo) tl
    OUTER APPLY (SELECT TOP 1 x.ShiftDescription FROM agora.vw_Shift x WHERE x.BranchId = t.BranchId AND x.ShiftNo = t.ShiftNo) sh
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x WHERE x.BranchId = t.BranchId AND x.EmployeeCode = t.EmployeeCode) e
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = t.ConfirmedBy) cu
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = t.ClosedBy) xu
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = t.ReopenedBy) ru
    WHERE t.BranchId = @BranchId
      AND t.TransactionAt >= @From AND t.TransactionAt < @To
      AND (@TillNo IS NULL OR t.TillNo = @TillNo)
      AND (@ShiftNo IS NULL OR t.ShiftNo = @ShiftNo)
      AND (@DayEndNo IS NULL OR t.DayEndNo = @DayEndNo)
      AND (@EmployeeCode IS NULL OR t.EmployeeCode = @EmployeeCode)
    ORDER BY t.TransactionDate, t.TillNo, t.ShiftNo;

    /* 2. One cashup's leg lines. */
    SELECT
        g.Channel, g.LineRef, g.Counterparty, g.Detail, g.Amount, g.InActualTotal,
        g.ReconBatchNo,
        CONVERT(BIT, CASE WHEN ISNULL(g.ReconBatchNo, 0) <> 0 THEN 1 ELSE 0 END) AS IsReconciled,
        g.AdjustedBy, g.CreatedAt
    FROM agora.vw_CashupLeg g
    WHERE @One = 1
      AND g.BranchId = @BranchId AND g.TillNo = @TillNo AND g.ShiftNo = @ShiftNo
      AND g.TransactionAt = @From
    ORDER BY g.Channel, g.LineRef;

    /* 3. The day's Z-reads, and whether each has a cashup. */
    SELECT
        z.ZReadDate, z.Location, z.TerminalNo, z.EodCounter, z.LogFile, z.PosUserId, z.Sales,
        z.TillNo, tl.TillDescription, z.ShiftNo, sh.ShiftDescription, z.EmployeeCode, e.EmployeeName,
        z.IsAllocated, z.AllocatedBy,
        CONVERT(BIT, CASE WHEN h.BranchId IS NULL THEN 0 ELSE 1 END) AS HasCashup
    FROM agora.vw_CashZread z
    OUTER APPLY (SELECT TOP 1 c.BranchId FROM agora.vw_Cashup c
                 WHERE c.BranchId = z.BranchId AND c.TillNo = z.TillNo AND c.ShiftNo = z.ShiftNo
                   AND c.TransactionAt = z.ZReadAt) h
    OUTER APPLY (SELECT TOP 1 x.TillDescription FROM agora.vw_Till x WHERE x.BranchId = z.BranchId AND x.TillNo = z.TillNo) tl
    OUTER APPLY (SELECT TOP 1 x.ShiftDescription FROM agora.vw_Shift x WHERE x.BranchId = z.BranchId AND x.ShiftNo = z.ShiftNo) sh
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x WHERE x.BranchId = z.BranchId AND x.EmployeeCode = z.EmployeeCode) e
    WHERE @DateFrom = @DateTo
      AND z.BranchId = @BranchId
      AND z.ZReadAt >= @From AND z.ZReadAt < @To
    ORDER BY z.TillNo, z.ShiftNo, z.Location, z.TerminalNo, z.LogFile;

    /* 4. What has been done to that one cashup. */
    SELECT
        v.Id, v.[Event], v.Channel, v.Lines, v.ZRead, v.ActualTotal, v.Variance, v.WriteMode, v.SaveRef,
        v.Note, v.CreatedAt, u.UserName AS CreatedByName
    FROM agora.CashupEvent v
    OUTER APPLY (SELECT TOP 1 x.UserName FROM agora.[User] x WHERE x.Id = v.CreatedBy) u
    WHERE @One = 1
      AND v.BranchId = @BranchId AND v.TransactionDate = @DateFrom AND v.TillNo = @TillNo AND v.ShiftNo = @ShiftNo
    ORDER BY v.Id DESC;
END
