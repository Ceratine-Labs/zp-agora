/* ============================================================================
   agora.usp_Cash_GetDropSafe

   WHAT IT IS FOR
   The Drop safe screen and its phone twin, for one site: what is in the safe
   now, what was collected in a period, and the lists a capture chooses from.

   WHO READS IT
   Cash → Drop safe (app.cash.dropsafe) and the phone screen
   (app.cash.dropsafe.phone).

   THE RESULT SETS
     1  In the safe — every bag at the site with CollectionId 0, whatever its
        day: PumpIT's own collection list (dbo.sp_SelectDropSafeCapture), with
        the names, the reason, whether it is on a cashup (IsSelectedForDaily
        Banking, or a cash-bags leg naming it — the two agree on every bag on
        the customer's instance) and whether it may still be corrected
        (CanEdit: in the safe and on no cashup).
     2  Collections — those at the site whose day falls in the period, with
        the bags in each counted from the bags, beside what the collection row
        declares.
     3  Manual-bag reasons (agora.vw_DropSafeReason), active first.
     4  Reasons offered for a bag over the threshold
        (agora.vw_DropSafeLimitReason), active only.
     5  The site's employees (agora.vw_Employee), active only, for the cashier
        and manager lists.
     6  One row: the safe's totals, the period's collections, and PumpIT's
        clock (ServerNow) — the capture's default day and time.

   Names are "Surname FirstName" as agora.vw_Employee writes them, looked up
   TOP 1 per (BranchId, EmployeeCode) so a label can never multiply a bag.

   @Threshold  a bag above it needs a reason (NeedsReason on set 1) —
               cash.dropsafe.reason_threshold, R2,000.00.

   READ-ONLY.

     EXEC agora.usp_Cash_GetDropSafe @BranchId = 3, @DateFrom = '2026-09-01', @DateTo = '2026-09-27';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GetDropSafe]
    @BranchId   INT,
    @DateFrom   DATE  = NULL,
    @DateTo     DATE  = NULL,
    @Threshold  MONEY = 2000.00
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Today DATE = CONVERT(DATE, GETDATE());
    SET @DateTo    = ISNULL(@DateTo, @Today);
    SET @DateFrom  = ISNULL(@DateFrom, DATEADD(day, -13, @DateTo));
    SET @Threshold = ISNULL(@Threshold, 2000.00);

    DECLARE @From DATETIME = @DateFrom, @To DATETIME = DATEADD(day, 1, CONVERT(DATETIME, @DateTo));

    /* ---- 1. In the safe ---------------------------------------------------- */
    SELECT
        d.BranchId,
        LTRIM(RTRIM(d.BagNo))                                          AS BagNo,
        CONVERT(DATE, d.DropDate)                                      AS DropDate,
        d.DropTime,
        d.Amount,
        d.ReasonForManualBagId,
        r.ReasonForManualBag,
        CONVERT(BIT, ISNULL(r.IsRefNoRequired, 0))                     AS IsRefNoRequired,
        NULLIF(LTRIM(RTRIM(d.ReasonIfAmountMoreThanR2000)), '')        AS LimitReason,
        NULLIF(LTRIM(RTRIM(d.RefNoFaultReported)), '')                 AS RefNoFaultReported,
        LTRIM(RTRIM(d.CashierCode))                                    AS CashierCode,
        k.EmployeeName                                                 AS CashierName,
        LTRIM(RTRIM(d.ManagerCode))                                    AS ManagerCode,
        m.EmployeeName                                                 AS ManagerName,
        CONVERT(BIT, CASE WHEN d.IsSelectedForDailyBanking = 1 OR g.OnLeg IS NOT NULL THEN 1 ELSE 0 END) AS OnCashup,
        g.OnLeg,
        CONVERT(BIT, CASE WHEN ISNULL(d.IsSelectedForDailyBanking, 0) = 0 AND g.OnLeg IS NULL THEN 1 ELSE 0 END) AS CanEdit,
        CONVERT(BIT, CASE WHEN d.Amount > @Threshold AND ISNULL(LTRIM(RTRIM(d.ReasonIfAmountMoreThanR2000)), '') = '' THEN 1 ELSE 0 END) AS NeedsReason,
        d.CreateDateTime                                               AS CapturedAt,
        CASE WHEN a.Id IS NULL THEN 'pumpit' ELSE 'agora' END          AS CapturedIn,
        DATEDIFF(day, d.DropDate, @Today)                              AS AgeDays
    FROM agora.vw_DropSafeBag d
    OUTER APPLY (SELECT TOP 1 x.ReasonForManualBag, x.IsRefNoRequired FROM agora.vw_DropSafeReason x
                 WHERE x.ReasonForManualBagId = d.ReasonForManualBagId) r
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x
                 WHERE x.BranchId = d.BranchId AND x.EmployeeCode = d.CashierCode) k
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x
                 WHERE x.BranchId = d.BranchId AND x.EmployeeCode = d.ManagerCode) m
    OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23)) AS OnLeg
                 FROM [PumpIT].dbo.BRN_DailyBankingCashBags x
                 WHERE x.SSBranchId = d.BranchId AND LTRIM(RTRIM(x.CashBagNo)) = LTRIM(RTRIM(d.BagNo))
                 ORDER BY x.TransactionDate DESC) g
    OUTER APPLY (SELECT TOP 1 e.Id FROM agora.DropSafeEvent e
                 WHERE e.BranchId = d.BranchId AND e.BagNo = d.BagNo AND e.[Event] IN ('dropped', 'edited')
                 ORDER BY e.Id DESC) a
    WHERE d.BranchId = @BranchId
      AND ISNULL(d.CollectionId, 0) = 0
    ORDER BY d.DropDate, d.DropTime, d.BagNo;

    /* ---- 2. Collections in the period ------------------------------------- */
    SELECT
        c.BranchId,
        CONVERT(BIGINT, c.CollectionId)                                AS CollectionId,
        LTRIM(RTRIM(c.DBagNo))                                         AS DBagNo,
        CONVERT(DATE, c.CollectionDate)                                AS CollectionDate,
        c.CollectionTime,
        LTRIM(RTRIM(c.ManagerCode))                                    AS ManagerCode,
        m.EmployeeName                                                 AS ManagerName,
        c.SecurityName,
        CONVERT(INT, c.TotalNoOfBags)                                  AS DeclaredBags,
        c.TotalAmount                                                  AS DeclaredAmount,
        ISNULL(b.Bags, 0)                                              AS Bags,
        CONVERT(MONEY, ISNULL(b.Amount, 0))                            AS Amount,
        c.CreateDateTime                                               AS CapturedAt,
        CASE WHEN a.Id IS NULL THEN 'pumpit' ELSE 'agora' END          AS CapturedIn
    FROM agora.vw_DropSafeCollectionHeader c
    OUTER APPLY (SELECT COUNT(*) AS Bags, SUM(x.Amount) AS Amount FROM agora.vw_DropSafeBag x
                 WHERE x.BranchId = c.BranchId AND x.CollectionId = c.CollectionId) b
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x
                 WHERE x.BranchId = c.BranchId AND x.EmployeeCode = c.ManagerCode) m
    OUTER APPLY (SELECT TOP 1 e.Id FROM agora.DropSafeEvent e
                 WHERE e.BranchId = c.BranchId AND e.CollectionId = c.CollectionId AND e.[Event] = 'collected') a
    WHERE c.BranchId = @BranchId
      AND c.CollectionDate >= @From AND c.CollectionDate < @To
    ORDER BY c.CollectionDate DESC, c.CollectionId DESC;

    /* ---- 3. Manual-bag reasons ------------------------------------------- */
    SELECT r.ReasonForManualBagId, LTRIM(RTRIM(r.ReasonForManualBag)) AS ReasonForManualBag,
           CONVERT(BIT, ISNULL(r.IsRefNoRequired, 0)) AS IsRefNoRequired, CONVERT(BIT, ISNULL(r.IsActive, 1)) AS IsActive
    FROM agora.vw_DropSafeReason r
    ORDER BY CASE WHEN ISNULL(r.IsActive, 1) = 1 THEN 0 ELSE 1 END, r.ReasonForManualBagId;

    /* ---- 4. Reasons offered for a bag over the threshold ------------------ */
    SELECT l.LimitReasonId, l.LimitReason
    FROM agora.vw_DropSafeLimitReason l
    WHERE l.IsActive = 1
    ORDER BY l.LimitReason;

    /* ---- 5. The site's employees ------------------------------------------ */
    SELECT e.EmployeeCode, MAX(e.EmployeeName) AS EmployeeName
    FROM agora.vw_Employee e
    WHERE e.BranchId = @BranchId AND ISNULL(e.IsActive, 1) = 1
    GROUP BY e.EmployeeCode
    ORDER BY MAX(e.EmployeeName), e.EmployeeCode;

    /* ---- 6. The totals, and PumpIT's clock -------------------------------- */
    SELECT
        s.Bags                                                         AS InSafeBags,
        s.Amount                                                       AS InSafeAmount,
        s.Oldest                                                       AS OldestDropDate,
        s.OnCashup                                                     AS InSafeOnCashup,
        s.NeedsReason                                                  AS InSafeNeedsReason,
        p.Collections                                                  AS Collections,
        p.Amount                                                       AS CollectedAmount,
        @DateFrom                                                      AS DateFrom,
        @DateTo                                                        AS DateTo,
        @Threshold                                                     AS Threshold,
        CONVERT(DATETIME, GETDATE())                                   AS ServerNow
    FROM (SELECT COUNT(*) AS Bags, CONVERT(MONEY, ISNULL(SUM(d.Amount), 0)) AS Amount,
                 CONVERT(DATE, MIN(d.DropDate)) AS Oldest,
                 ISNULL(SUM(CASE WHEN d.IsSelectedForDailyBanking = 1 THEN 1 ELSE 0 END), 0) AS OnCashup,
                 ISNULL(SUM(CASE WHEN d.Amount > @Threshold AND ISNULL(LTRIM(RTRIM(d.ReasonIfAmountMoreThanR2000)), '') = '' THEN 1 ELSE 0 END), 0) AS NeedsReason
          FROM agora.vw_DropSafeBag d
          WHERE d.BranchId = @BranchId AND ISNULL(d.CollectionId, 0) = 0) s
    CROSS JOIN (SELECT COUNT(*) AS Collections, CONVERT(MONEY, ISNULL(SUM(c.TotalAmount), 0)) AS Amount
                FROM agora.vw_DropSafeCollectionHeader c
                WHERE c.BranchId = @BranchId AND c.CollectionDate >= @From AND c.CollectionDate < @To) p;
END
