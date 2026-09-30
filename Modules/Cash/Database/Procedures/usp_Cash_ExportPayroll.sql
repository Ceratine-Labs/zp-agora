/* ============================================================================
   agora.usp_Cash_ExportPayroll

   WHAT IT IS FOR
   The payroll file (T041): one line per person per site for a pay period, the
   deduction the built schedule says to take from their pay, split the way the
   head-office tabs split it. Cash → Deductions → Payroll → Export.

   It reads the CURRENT run for each site in scope with exactly this period —
   'built' or 'exported', never 'superseded' — and marks every 'built' one
   'exported' (ExportedAt, ExportedBy) in the same press, so a period that has
   gone to payroll cannot then be rebuilt without somebody saying so
   (usp_Cash_BuildDeductions refuses it). Exporting again returns the same
   lines and leaves the first export's time alone.

   THE LAYOUT IS PROVISIONAL — the plan says "in the payroll's layout, confirm
   with ZP" and ZP has not said. Amounts are what to DEDUCT, as positive rand:
   the schedule stores them negative, as the legacy prints them.

     BranchId, BranchName, EmployeeCode, EmployeeName, PostDescription,
     FromDate, ToDate, CashShort, StockLoss, NonIntegrated, Total

   CashShort is cashier + pump + cash-bag shorts. A person whose total is nil
   is not listed. The file's Total column adds up to the runs' Deducted stat,
   which is the acceptance test's check.

   Writes: agora.DeductionRun (Status, ExportedAt, ExportedBy) only.

   Result sets: 1 the status row (Ok, Code, Message, Id = runs exported now),
                2 the lines.

     EXEC agora.usp_Cash_ExportPayroll @BranchIds = '21', @FromDate = '2026-09-01', @ToDate = '2026-09-30', @UserId = 1;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_ExportPayroll]
    @BranchIds  NVARCHAR(MAX),
    @FromDate   DATE,
    @ToDate     DATE,
    @UserId     INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @UserId IS NULL
        THROW 51000, 'AGORA:DEDUCTION_USER:A payroll file is taken by a person, and none was given.', 1;

    IF @FromDate IS NULL OR @ToDate IS NULL OR @ToDate < @FromDate
        THROW 51000, 'AGORA:DEDUCTION_PERIOD:Say which period: a from date on or before a to date.', 1;

    DECLARE @Branch TABLE (BranchId INT PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(INT, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE TRY_CONVERT(INT, LTRIM(RTRIM(s.value))) IS NOT NULL;

    IF NOT EXISTS (SELECT 1 FROM @Branch)
        THROW 51000, 'AGORA:DEDUCTION_BRANCH:Say which sites the payroll file is for.', 1;

    IF OBJECT_ID('tempdb..#runs') IS NOT NULL DROP TABLE #runs;

    SELECT r.BranchId, r.Id, r.[Status]
    INTO #runs
    FROM agora.DeductionRun r
    WHERE r.BranchId IN (SELECT BranchId FROM @Branch)
      AND r.FromDate = @FromDate AND r.ToDate = @ToDate
      AND r.[Status] IN ('built', 'exported');

    IF NOT EXISTS (SELECT 1 FROM #runs)
        THROW 51000, 'AGORA:DEDUCTION_NO_RUN:No deduction run has been built for these sites and this period. Build it first; nothing was exported.', 1;

    DECLARE @Exported INT = (SELECT COUNT(*) FROM #runs WHERE [Status] = 'built');

    UPDATE r SET r.[Status] = 'exported', r.ExportedAt = SYSUTCDATETIME(), r.ExportedBy = @UserId,
                 r.UpdatedAt = SYSUTCDATETIME(), r.UpdatedBy = @UserId
    FROM agora.DeductionRun r
    JOIN #runs x ON x.BranchId = r.BranchId AND x.Id = r.Id
    WHERE r.[Status] = 'built';

    DECLARE @Missing NVARCHAR(MAX) = (
        SELECT STRING_AGG(CONVERT(NVARCHAR(MAX), ISNULL(b.Name, CONVERT(NVARCHAR(12), br.BranchId))), N', ')
        FROM @Branch br
        OUTER APPLY (SELECT TOP 1 x.Name FROM agora.Branch x WHERE x.BranchId = br.BranchId ORDER BY x.Id) b
        WHERE NOT EXISTS (SELECT 1 FROM #runs r WHERE r.BranchId = br.BranchId));

    SELECT CONVERT(BIT, 1) AS Ok,
           'EXPORTED' AS Code,
           CONCAT((SELECT COUNT(*) FROM #runs), ' site(s) in the file',
                  CASE WHEN @Exported < (SELECT COUNT(*) FROM #runs) THEN ' (some went to payroll before)' ELSE '' END,
                  CASE WHEN @Missing IS NOT NULL THEN CONCAT('. No run built for: ', @Missing, '.') ELSE '.' END) AS [Message],
           CONVERT(BIGINT, @Exported) AS Id;

    SELECT d.BranchId,
           MAX(b.Name)                                                                              AS BranchName,
           d.EmployeeCode,
           MAX(d.EmployeeName)                                                                      AS EmployeeName,
           MAX(d.PostDescription)                                                                   AS PostDescription,
           @FromDate                                                                                AS FromDate,
           @ToDate                                                                                  AS ToDate,
           CONVERT(DECIMAL(18,2), -SUM(CASE WHEN d.Source IN ('cashier_short', 'pump_short', 'cashbag_short') THEN d.Deducted ELSE 0 END)) AS CashShort,
           CONVERT(DECIMAL(18,2), -SUM(CASE WHEN d.Source = 'stock_loss' THEN d.Deducted ELSE 0 END)) AS StockLoss,
           CONVERT(DECIMAL(18,2), -SUM(CASE WHEN d.Source = 'nonint_pos' THEN d.Deducted ELSE 0 END)) AS NonIntegrated,
           CONVERT(DECIMAL(18,2), -SUM(d.Deducted))                                                 AS Total
    FROM agora.Deduction d
    JOIN #runs r ON r.BranchId = d.BranchId AND r.Id = d.RunId
    OUTER APPLY (SELECT TOP 1 x.Name FROM agora.Branch x WHERE x.BranchId = d.BranchId ORDER BY x.Id) b
    GROUP BY d.BranchId, d.EmployeeCode
    HAVING SUM(d.Deducted) <> 0
    ORDER BY MAX(b.Name), MAX(d.EmployeeName), d.EmployeeCode;

    DROP TABLE #runs;
END
