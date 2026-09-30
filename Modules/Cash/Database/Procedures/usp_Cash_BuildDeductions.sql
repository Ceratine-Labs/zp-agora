/* ============================================================================
   agora.usp_Cash_BuildDeductions

   WHAT IT IS FOR
   Build one site's deduction schedule for a pay period (T041): every short
   and loss the five head-office tabs show, measured against its allowance,
   recorded as agora.DeductionRun + agora.Deduction so the payroll file is
   taken from a fixed record rather than from a report re-run on a different
   day. Cash → Deductions → Payroll → Build.

   WHAT GOES IN, one row per source (UX_Deduction_Natural — a short reaches the
   schedule exactly once):

     cashier_short  every cashup that came out short
                    (agora.usp_Cash_DeductionCashShorts): deducted in full when
                    worse than @Leniency, written off at or inside it
     pump_short     every pump shortages line: deducted in full
     cashbag_short  every approved cash-bag short (on no cashup, PM decision
                    01a0e5b5): deducted in full
     stock_loss     each person's stock loss per AREA GROUP for the period,
                    against the group's allowance — the port of
                    dbo.sp_RPT_EmployeeStockLossReportPAYROLLDETAIL_LeniencySummary
                    (@ShowLosses = 1): the detail rows (rounded to the cent as
                    the detail report prints them) summed per person and group;
                    the allowance is the days in the period x the group's
                    LossGraceDaily; a total at or inside the allowance is
                    written off, a total beyond it is deducted IN FULL — there
                    is no partial rule. Areas whose group is not on
                    STK_AreaGroup are left out, as the legacy's INNER JOIN
                    leaves them out.
     nonint_pos     each person's loss per Lotto / Electricity / Airtime area
                    for the period, R1.00 or more (the non-integrated summary
                    tab): deducted in full.

   A REBUILD SUPERSEDES. Building a period the site already has a built run
   for marks the old one 'superseded' and points it at the new one; nothing is
   deleted. A period that overlaps one already EXPORTED to payroll is refused
   (DEDUCTION_EXPORTED): a paid period is not quietly rebuilt.

   Writes: agora.DeductionRun, agora.Deduction — Agora's own tables only.
   Reads the customer's estate through the agora.vw_* views, like the tabs.

   Result set: the status row (Ok, Code, Message, Id = the new run).

     EXEC agora.usp_Cash_BuildDeductions @BranchId = 21, @FromDate = '2026-09-01', @ToDate = '2026-09-30', @UserId = 1;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_BuildDeductions]
    @BranchId   INT,
    @FromDate   DATE,
    @ToDate     DATE,
    @Leniency   DECIMAL(18,2) = 3.00,
    @UserId     INT           = NULL,
    @Note       NVARCHAR(400) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @UserId IS NULL
        THROW 51000, 'AGORA:DEDUCTION_USER:A deduction run is built by a person, and none was given.', 1;

    IF NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:DEDUCTION_BRANCH:That site is not in Agora.', 1;

    IF @FromDate IS NULL OR @ToDate IS NULL OR @ToDate < @FromDate
        THROW 51000, 'AGORA:DEDUCTION_PERIOD:Say which period: a from date on or before a to date.', 1;

    IF DATEDIFF(day, @FromDate, @ToDate) > 62
        THROW 51000, 'AGORA:DEDUCTION_PERIOD:A deduction run covers one pay period — two months at the most.', 1;

    IF @ToDate >= CONVERT(DATE, GETDATE())
        THROW 51000, 'AGORA:DEDUCTION_PERIOD:A pay period is built once it has ended — the to date has to be before today.', 1;

    DECLARE @Msg NVARCHAR(2048);

    SET @Msg = (SELECT TOP 1 CONCAT('AGORA:DEDUCTION_EXPORTED:Run ', r.Id, ' for ', CONVERT(NVARCHAR(10), r.FromDate, 23), ' to ',
                                    CONVERT(NVARCHAR(10), r.ToDate, 23), ' went to payroll on ',
                                    CONVERT(NVARCHAR(16), DATEADD(minute, DATEPART(tzoffset, SYSDATETIMEOFFSET()), r.ExportedAt), 120),
                                    '. A period already paid is not rebuilt. Nothing was written.')
                FROM agora.DeductionRun r
                WHERE r.BranchId = @BranchId AND r.[Status] = 'exported'
                  AND r.FromDate <= @ToDate AND r.ToDate >= @FromDate
                ORDER BY r.ExportedAt DESC);
    IF @Msg IS NOT NULL THROW 51000, @Msg, 1;

    DECLARE @Scope NVARCHAR(20) = CONVERT(NVARCHAR(20), @BranchId),
            @Days  INT          = DATEDIFF(day, @FromDate, @ToDate) + 1;

    /* ---------------------------------------------------------- what is short */
    IF OBJECT_ID('tempdb..#CashShort') IS NOT NULL DROP TABLE #CashShort;
    CREATE TABLE #CashShort (
        BranchId INT, Source VARCHAR(16) COLLATE DATABASE_DEFAULT, EmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT,
        TransactionDate DATE, TransactionAt DATETIME, TillNo INT, ShiftNo INT,
        ZReading MONEY NULL, PumpAttShort MONEY, CashierShort MONEY,
        LeniencyAllowance MONEY, Deduction MONEY, IsWrittenOff BIT,
        DayEndNo NVARCHAR(20) COLLATE DATABASE_DEFAULT NULL, StaffShortsNo INT NULL, Note NVARCHAR(400) COLLATE DATABASE_DEFAULT NULL);

    EXEC agora.usp_Cash_DeductionCashShorts @BranchIds = @Scope, @DateFrom = @FromDate, @DateTo = @ToDate, @Leniency = @Leniency;

    IF OBJECT_ID('tempdb..#StockLoss') IS NOT NULL DROP TABLE #StockLoss;
    CREATE TABLE #StockLoss (
        BranchId INT, TransactionDate DATE, ShiftNo INT NULL, AreaNo INT,
        EmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT, StockItemNo NVARCHAR(20) COLLATE DATABASE_DEFAULT, EmployeeCount INT,
        LossF FLOAT NULL, LossD DECIMAL(38,13) NULL);

    EXEC agora.usp_Cash_DeductionStockLoss @BranchIds = @Scope, @DateFrom = @FromDate, @DateTo = @ToDate,
         @Kind = 'stock', @Grain = 'shift';

    SELECT * INTO #sl_stock FROM #StockLoss;
    TRUNCATE TABLE #StockLoss;

    EXEC agora.usp_Cash_DeductionStockLoss @BranchIds = @Scope, @DateFrom = @FromDate, @DateTo = @ToDate,
         @Kind = 'nonint', @Grain = 'day';

    /* ------------------------------------------------------------- the lines */
    IF OBJECT_ID('tempdb..#lines') IS NOT NULL DROP TABLE #lines;
    CREATE TABLE #lines (
        Source VARCHAR(16) COLLATE DATABASE_DEFAULT, SourceKey NVARCHAR(80) COLLATE DATABASE_DEFAULT, EmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT,
        SourceDate DATE NULL, TillNo INT NULL, ShiftNo INT NULL, AreaNo INT NULL, AreaGroup NVARCHAR(50) COLLATE DATABASE_DEFAULT NULL,
        SourceRef NVARCHAR(60) COLLATE DATABASE_DEFAULT NULL, Amount DECIMAL(18,2), Allowance DECIMAL(18,2), Deducted DECIMAL(18,2),
        IsWrittenOff BIT, Note NVARCHAR(400) COLLATE DATABASE_DEFAULT NULL);

    INSERT INTO #lines (Source, SourceKey, EmployeeCode, SourceDate, TillNo, ShiftNo, SourceRef,
                        Amount, Allowance, Deducted, IsWrittenOff, Note)
    SELECT c.Source,
           CASE c.Source
               WHEN 'cashbag_short' THEN CONCAT('short|', c.StaffShortsNo)
               ELSE CONCAT(CONVERT(NVARCHAR(10), c.TransactionDate, 23), '|', c.TillNo, '|', c.ShiftNo) END,
           c.EmployeeCode, c.TransactionDate, c.TillNo, c.ShiftNo,
           CASE c.Source
               WHEN 'cashier_short' THEN CASE WHEN c.DayEndNo IS NOT NULL THEN CONCAT('Day end ', c.DayEndNo) END
               ELSE CASE WHEN c.StaffShortsNo IS NOT NULL THEN CONCAT('Staff short ', c.StaffShortsNo) END END,
           CASE WHEN c.Source = 'pump_short' THEN c.PumpAttShort ELSE c.CashierShort END,
           c.LeniencyAllowance, c.Deduction, c.IsWrittenOff, c.Note
    FROM #CashShort c;

    /* Stock loss against the group's allowance — the LeniencySummary port. */
    ;WITH detail AS (
        SELECT s.EmployeeCode, a.AreaGroup, a.LossGraceDaily,
               CONVERT(DECIMAL(10,2), SUM(s.LossF)) AS Loss
        FROM #sl_stock s
        JOIN agora.vw_StockArea a ON a.BranchId = s.BranchId AND a.AreaNo = s.AreaNo
        WHERE a.AreaGroupId IS NOT NULL
        GROUP BY s.EmployeeCode, s.TransactionDate, s.AreaNo, s.ShiftNo, s.EmployeeCount, a.AreaGroup, a.LossGraceDaily
    ),
    grouped AS (
        SELECT d.EmployeeCode, d.AreaGroup,
               SUM(d.Loss)                                        AS Loss,
               CONVERT(DECIMAL(18,2), -@Days * MAX(d.LossGraceDaily)) AS Allowance
        FROM detail d
        GROUP BY d.EmployeeCode, d.AreaGroup
    )
    INSERT INTO #lines (Source, SourceKey, EmployeeCode, AreaGroup, SourceRef, Amount, Allowance, Deducted, IsWrittenOff, Note)
    SELECT 'stock_loss', CONCAT('group|', g.AreaGroup), g.EmployeeCode, g.AreaGroup,
           CONCAT(@Days, ' days x R', FORMAT(-g.Allowance / NULLIF(@Days, 0), 'N2'), ' a day'),
           g.Loss, g.Allowance,
           CASE WHEN g.Loss > g.Allowance THEN 0 ELSE g.Loss END,
           CASE WHEN g.Loss > g.Allowance THEN 1 ELSE 0 END,
           NULL
    FROM grouped g
    WHERE g.Loss < 0;

    /* The Lotto / Electricity / Airtime areas — the non-integrated summary. */
    INSERT INTO #lines (Source, SourceKey, EmployeeCode, AreaNo, SourceRef, Amount, Allowance, Deducted, IsWrittenOff, Note)
    SELECT 'nonint_pos', CONCAT('area|', s.AreaNo), s.EmployeeCode, s.AreaNo, MAX(a.AreaDescription),
           CONVERT(DECIMAL(10,2), SUM(s.LossD)), 0, CONVERT(DECIMAL(10,2), SUM(s.LossD)), 0, NULL
    FROM #StockLoss s
    OUTER APPLY (SELECT TOP 1 x.AreaDescription FROM agora.vw_StockArea x WHERE x.BranchId = s.BranchId AND x.AreaNo = s.AreaNo) a
    GROUP BY s.EmployeeCode, s.AreaNo
    HAVING CONVERT(DECIMAL(10,2), SUM(s.LossD)) <= -1.00;

    /* ------------------------------------------------------------- the record */
    DECLARE @RunId BIGINT, @Now DATETIME2(0) = SYSUTCDATETIME();   -- Agora's ledger is UTC, like the application's

    BEGIN TRY
    BEGIN TRANSACTION;

        INSERT INTO agora.DeductionRun (BranchId, FromDate, ToDate, [Status], CashLeniency, Note, CreatedAt, CreatedBy)
        VALUES (@BranchId, @FromDate, @ToDate, 'built', ABS(ISNULL(@Leniency, 3.00)), @Note, @Now, @UserId);

        SET @RunId = SCOPE_IDENTITY();

        INSERT INTO agora.Deduction (BranchId, RunId, Source, SourceKey, EmployeeCode, EmployeeName, PostDescription,
                                     SourceDate, TillNo, ShiftNo, AreaNo, AreaGroup, SourceRef,
                                     Amount, Allowance, Deducted, IsWrittenOff, Note, CreatedAt, CreatedBy)
        SELECT @BranchId, @RunId, l.Source, l.SourceKey, l.EmployeeCode,
               NULLIF(LTRIM(RTRIM(ISNULL(m.FirstName, '') + ' ' + ISNULL(m.Surname, ''))), ''),
               p.PostDescription,
               l.SourceDate, l.TillNo, l.ShiftNo, l.AreaNo, l.AreaGroup, l.SourceRef,
               l.Amount, l.Allowance, l.Deducted, l.IsWrittenOff, l.Note, @Now, @UserId
        FROM #lines l
        OUTER APPLY (SELECT TOP 1 x.FirstName, x.Surname, x.PostNo FROM agora.vw_Employee x WHERE x.BranchId = @BranchId AND x.EmployeeCode = l.EmployeeCode) m
        OUTER APPLY (SELECT TOP 1 x.PostDescription FROM agora.vw_EmployeePost x WHERE x.BranchId = @BranchId AND x.PostNo = m.PostNo) p;

        UPDATE r SET
            r.Lines          = t.Lines,
            r.Employees      = t.Employees,
            r.Shorts         = t.Shorts,
            r.WrittenOff     = t.WrittenOff,
            r.Deducted       = t.Deducted,
            r.CashDeducted   = t.CashDeducted,
            r.StockDeducted  = t.StockDeducted,
            r.NonIntDeducted = t.NonIntDeducted
        FROM agora.DeductionRun r
        CROSS JOIN (
            SELECT COUNT(*)                                                                 AS Lines,
                   COUNT(DISTINCT CASE WHEN Deducted <> 0 THEN EmployeeCode END)            AS Employees,
                   ISNULL(SUM(Amount), 0)                                                   AS Shorts,
                   ISNULL(SUM(CASE WHEN IsWrittenOff = 1 THEN Amount ELSE 0 END), 0)        AS WrittenOff,
                   ISNULL(SUM(Deducted), 0)                                                 AS Deducted,
                   ISNULL(SUM(CASE WHEN Source IN ('cashier_short', 'pump_short', 'cashbag_short') THEN Deducted ELSE 0 END), 0) AS CashDeducted,
                   ISNULL(SUM(CASE WHEN Source = 'stock_loss' THEN Deducted ELSE 0 END), 0) AS StockDeducted,
                   ISNULL(SUM(CASE WHEN Source = 'nonint_pos' THEN Deducted ELSE 0 END), 0) AS NonIntDeducted
            FROM agora.Deduction WHERE BranchId = @BranchId AND RunId = @RunId
        ) t
        WHERE r.BranchId = @BranchId AND r.Id = @RunId;

        /* The previous build of this period stands down, and says what replaced it. */
        UPDATE agora.DeductionRun
           SET [Status] = 'superseded', SupersededAt = @Now, SupersededBy = @RunId, UpdatedAt = @Now, UpdatedBy = @UserId
        WHERE BranchId = @BranchId AND [Status] = 'built' AND Id <> @RunId
          AND FromDate = @FromDate AND ToDate = @ToDate;

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(BIT, 1) AS Ok,
           'BUILT' AS Code,
           (SELECT CONCAT(r.Lines, ' line(s) for ', r.Employees, ' person(s): R', FORMAT(-r.Deducted, 'N2'),
                          ' to deduct, R', FORMAT(-r.WrittenOff, 'N2'), ' written off inside the allowances.')
            FROM agora.DeductionRun r WHERE r.BranchId = @BranchId AND r.Id = @RunId) AS [Message],
           @RunId AS Id;
END
