/* ============================================================================
   agora.usp_Cash_GetTillBalance

   WHAT IT IS FOR
   The Till balance screen: one site over a few days — its cashups with a
   variance worth moving, and the till balances already captured against them,
   with where each one stands.

   WHO READS IT
   Cash → Till balance (app.cash.tillbalance), in the branch for its own site
   and in head office. It replaces the list the PumpIT till-balance screen
   works from.

   Result sets, always both, in this order:

     1  the cashups — one row per BRN_DailyBanking header at the site in the
        period whose variance is at least @VarianceFloor either way, or that a
        till balance waiting for approval already touches. Variance is the
        Daily Banking grid's (agora.vw_CashupTotals); Pending is what the till
        balances waiting for approval will move it by once PumpIT posts them,
        and VarianceIfPosted is where that leaves it. CanBalance says whether a
        till balance may name it now, and Problem says why not:
          no Z-read / more than one Z-read   the row's key needs the LOGFILE of
                                             the one Z-read behind the cashup
          closed                             a manager closed it in Agora
     2  the till balances whose either side falls in the period at the site,
        one row per number: the side giving the amount up (From*) and the side
        taking it (To*), the status, who captured it and where, who approved or
        declined it, and PostedLines — how many of its sides PumpIT has posted
        to a cashup's Expenses leg (the 'Till Balance Expense' line whose
        purchase request carries the number in QuoteNo).

   Arguments:
     @BranchId       the site — required
     @DateFrom       the first day
     @DateTo         the last day; the same day when NULL
     @EmployeeCode   optional: one person's cashups (either side, in set 2)
     @VarianceFloor  the smallest variance, either way, a cashup is listed for
                     (cash.tillbalance.variance_floor)

   Every label lookup is OUTER APPLY TOP 1 on the master's full key, so a label
   can never change a row count.

   READ-ONLY.

     EXEC agora.usp_Cash_GetTillBalance @BranchId = 3, @DateFrom = '2026-09-01', @DateTo = '2026-09-07';

   Refusals:
     AGORA:CASH_TILLBAL_SITE   no site
     AGORA:CASH_TILLBAL_RANGE  no day, or a period that ends before it starts
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GetTillBalance]
    @BranchId       INT,
    @DateFrom       DATE,
    @DateTo         DATE          = NULL,
    @EmployeeCode   NVARCHAR(11)  = NULL,
    @VarianceFloor  MONEY         = 1.00
AS
BEGIN
    SET NOCOUNT ON;

    IF @BranchId IS NULL
        THROW 51000, 'AGORA:CASH_TILLBAL_SITE:Choose a site. A till balance moves money between cashups at one site.', 1;

    SET @DateTo = ISNULL(@DateTo, @DateFrom);

    IF @DateFrom IS NULL OR @DateTo < @DateFrom
        THROW 51000, 'AGORA:CASH_TILLBAL_RANGE:Give the day, or a period that ends on or after it starts.', 1;

    SET @EmployeeCode  = NULLIF(LTRIM(RTRIM(@EmployeeCode)), '');
    SET @VarianceFloor = ABS(ISNULL(@VarianceFloor, 0));

    DECLARE @From DATETIME = @DateFrom, @To DATETIME = DATEADD(day, 1, CONVERT(DATETIME, @DateTo));

    /* The site's till balance rows in the period, once, for both sets. */
    SELECT b.BranchId, b.TillBalanceNo, b.TillNo, b.ShiftNo, b.TransactionAt, b.TransactionDate, b.EmployeeCode,
           b.Amount, b.VarianceBefore, b.VarianceAfter, b.[Status]
    INTO #tb_rows
    FROM agora.vw_TillBalance b
    WHERE b.BranchId = @BranchId
      AND b.TransactionAt >= @From AND b.TransactionAt < @To;

    /* 1. The cashups. */
    SELECT
        t.BranchId, t.TransactionDate, t.TillNo, tl.TillDescription, t.ShiftNo, sh.ShiftDescription,
        t.EmployeeCode, e.EmployeeName,
        t.ZRead, t.ActualTotal, t.Variance,
        CONVERT(MONEY, ISNULL(p.Pending, 0))                       AS Pending,
        ISNULL(p.PendingCount, 0)                                  AS PendingCount,
        CONVERT(MONEY, t.Variance + ISNULL(p.Pending, 0))          AS VarianceIfPosted,
        z.Zreads, z.LogFile,
        t.[Status],
        CONVERT(BIT, CASE WHEN z.Zreads = 1 AND t.AgoraStatus <> 'closed' THEN 1 ELSE 0 END) AS CanBalance,
        CASE WHEN ISNULL(z.Zreads, 0) = 0 THEN 'No Z-read is allocated to this till and shift'
             WHEN z.Zreads > 1 THEN 'More than one Z-read is allocated to this till and shift'
             WHEN t.AgoraStatus = 'closed' THEN 'Closed by a manager — reopen it to move its variance'
        END                                                        AS Problem
    FROM agora.vw_CashupTotals t
    OUTER APPLY (SELECT SUM(r.Amount) AS Pending, COUNT(*) AS PendingCount
                 FROM #tb_rows r
                 WHERE r.TillNo = t.TillNo AND r.ShiftNo = t.ShiftNo AND r.TransactionAt = t.TransactionAt
                   AND r.[Status] = 'pending') p
    OUTER APPLY (SELECT COUNT(*) AS Zreads, MAX(x.LogFile) AS LogFile
                 FROM agora.vw_CashZread x
                 WHERE x.BranchId = t.BranchId AND x.ZReadAt = t.TransactionAt AND x.TillNo = t.TillNo AND x.ShiftNo = t.ShiftNo) z
    OUTER APPLY (SELECT TOP 1 x.TillDescription FROM agora.vw_Till x WHERE x.BranchId = t.BranchId AND x.TillNo = t.TillNo) tl
    OUTER APPLY (SELECT TOP 1 x.ShiftDescription FROM agora.vw_Shift x WHERE x.BranchId = t.BranchId AND x.ShiftNo = t.ShiftNo) sh
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x WHERE x.BranchId = t.BranchId AND x.EmployeeCode = t.EmployeeCode) e
    WHERE t.BranchId = @BranchId
      AND t.TransactionAt >= @From AND t.TransactionAt < @To
      AND (@EmployeeCode IS NULL OR t.EmployeeCode = @EmployeeCode)
      AND (ABS(t.Variance) >= @VarianceFloor OR ISNULL(p.PendingCount, 0) > 0)
    ORDER BY e.EmployeeName, t.EmployeeCode, t.TransactionDate, t.TillNo, t.ShiftNo;

    /* 2. The till balances, one row per number — every row of each number,
          wherever its other side falls. */
    SELECT x.TillBalanceNo, x.TillNo, x.ShiftNo, x.TransactionAt, x.TransactionDate, x.EmployeeCode, x.Amount,
           x.VarianceBefore, x.VarianceAfter, x.[Status], x.CapturedIn, x.CapturedAt, x.CreatedByErpUserId,
           x.ApprovedAt, x.ApprovedByErpUserId, x.DeclinedAt, x.DeclinedByErpUserId, x.DeclinedReason, x.CaptureEventId
    INTO #tb_all
    FROM agora.vw_TillBalance x
    WHERE x.BranchId = @BranchId
      AND x.TillBalanceNo IN (SELECT r.TillBalanceNo FROM #tb_rows r);

    SELECT
        d.TillBalanceNo,
        f.TransactionDate AS FromDate, f.TillNo AS FromTillNo, ftl.TillDescription AS FromTill, f.ShiftNo AS FromShiftNo,
        fsh.ShiftDescription AS FromShift, f.EmployeeCode AS FromEmployeeCode, fe.EmployeeName AS FromEmployeeName,
        f.VarianceBefore AS FromVarianceBefore, f.VarianceAfter AS FromVarianceAfter,
        g.TransactionDate AS ToDate, g.TillNo AS ToTillNo, gtl.TillDescription AS ToTill, g.ShiftNo AS ToShiftNo,
        gsh.ShiftDescription AS ToShift, g.EmployeeCode AS ToEmployeeCode, ge.EmployeeName AS ToEmployeeName,
        g.VarianceBefore AS ToVarianceBefore, g.VarianceAfter AS ToVarianceAfter,
        CONVERT(MONEY, ISNULL(g.Amount, -f.Amount))                AS Amount,
        d.[Rows],
        s.[Status], s.CapturedIn, s.CapturedAt,
        COALESCE(au.UserName, cu.UserName)                         AS CapturedByName,
        s.ApprovedAt, apu.UserName AS ApprovedByName,
        s.DeclinedAt, dcu.UserName AS DeclinedByName, s.DeclinedReason,
        ISNULL(ps.PostedLines, 0)                                  AS PostedLines,
        ev.Note
    FROM (SELECT a.TillBalanceNo, COUNT(*) AS [Rows] FROM #tb_all a GROUP BY a.TillBalanceNo) d
    OUTER APPLY (SELECT TOP 1 a.* FROM #tb_all a WHERE a.TillBalanceNo = d.TillBalanceNo AND a.Amount < 0
                 ORDER BY a.TransactionAt, a.TillNo, a.ShiftNo) f
    OUTER APPLY (SELECT TOP 1 a.* FROM #tb_all a WHERE a.TillBalanceNo = d.TillBalanceNo AND a.Amount >= 0
                 ORDER BY a.TransactionAt, a.TillNo, a.ShiftNo) g
    OUTER APPLY (SELECT TOP 1 a.* FROM #tb_all a WHERE a.TillBalanceNo = d.TillBalanceNo
                 ORDER BY CASE a.[Status] WHEN 'declined' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END, a.CapturedAt) s
    OUTER APPLY (SELECT TOP 1 e.Note, e.CreatedBy FROM agora.TillBalanceEvent e
                 WHERE e.BranchId = @BranchId AND e.Id = s.CaptureEventId) ev
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = ev.CreatedBy) au
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.vw_ErpUser u WHERE u.ErpUserId = s.CreatedByErpUserId) cu
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.vw_ErpUser u WHERE u.ErpUserId = s.ApprovedByErpUserId) apu
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.vw_ErpUser u WHERE u.ErpUserId = s.DeclinedByErpUserId) dcu
    OUTER APPLY (
        /* A side is posted when its cashup carries the Till Balance Expense
           line PumpIT's approval wrote for this number: an Expenses leg row at
           the side's exact key whose purchase request names the number. */
        SELECT COUNT(*) AS PostedLines
        FROM #tb_all a
        WHERE a.TillBalanceNo = d.TillBalanceNo
          AND EXISTS (SELECT 1
                      FROM [PumpIT].dbo.BRN_DailyBankingExpenses l
                      JOIN [PumpIT].dbo.BRN_Transaction q
                        ON q.SSBranchId = l.SSBranchId AND q.TransactionNo = l.TransactionNo
                      WHERE l.SSBranchId = @BranchId AND l.TillNo = a.TillNo AND l.ShiftNo = a.ShiftNo
                        AND l.TransactionDate = a.TransactionAt
                        AND LTRIM(RTRIM(q.QuoteNo)) = CONVERT(NVARCHAR(30), a.TillBalanceNo))
    ) ps
    OUTER APPLY (SELECT TOP 1 x.TillDescription FROM agora.vw_Till x WHERE x.BranchId = @BranchId AND x.TillNo = f.TillNo) ftl
    OUTER APPLY (SELECT TOP 1 x.ShiftDescription FROM agora.vw_Shift x WHERE x.BranchId = @BranchId AND x.ShiftNo = f.ShiftNo) fsh
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x WHERE x.BranchId = @BranchId AND x.EmployeeCode = f.EmployeeCode) fe
    OUTER APPLY (SELECT TOP 1 x.TillDescription FROM agora.vw_Till x WHERE x.BranchId = @BranchId AND x.TillNo = g.TillNo) gtl
    OUTER APPLY (SELECT TOP 1 x.ShiftDescription FROM agora.vw_Shift x WHERE x.BranchId = @BranchId AND x.ShiftNo = g.ShiftNo) gsh
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x WHERE x.BranchId = @BranchId AND x.EmployeeCode = g.EmployeeCode) ge
    WHERE @EmployeeCode IS NULL OR f.EmployeeCode = @EmployeeCode OR g.EmployeeCode = @EmployeeCode
    ORDER BY d.TillBalanceNo DESC;

    DROP TABLE #tb_all;
    DROP TABLE #tb_rows;
END
