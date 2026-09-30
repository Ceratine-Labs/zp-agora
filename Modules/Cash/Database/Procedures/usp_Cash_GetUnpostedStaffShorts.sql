/* ============================================================================
   agora.usp_Cash_GetUnpostedStaffShorts

   WHAT IT IS FOR
   Write once, flow everywhere (T042). A staff short reaches the deduction
   schedule, and so the payroll file, by one road: posted onto a cashup's pump
   shortages leg, where usp_Cash_DeductionCashShorts reads it as pump_short. A
   short that is approved and never posted is on no cashup, so it is deducted
   from nobody, and nothing says so. PumpIT was the same, and live shows 3 to
   13 such shorts a month (R600 to R4 654, Mar–Aug 2026).

   This counts them, so the Deductions screen can say, before a period is
   built, what is about to miss payroll and where to post it.

   Left out, because they reach payroll another way: a short a cash-bag match
   raised (agora.StaffShort Source 'recon'). Those are never posted onto a
   cashup and are deducted directly as cashbag_short (PM decision 01a0e5b5).

   @BranchIds         CSV of sites chosen; NULL or empty = @AllowedBranchIds.
   @AllowedBranchIds  CSV of the sites the person may see; NULL = every site.
   @DateFrom, @DateTo inclusive, on the short's transaction date.

   Result set: one row — Shorts, Amount (positive rand), Oldest (the earliest
   such short's date, NULL when there are none).

   READ-ONLY.
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GetUnpostedStaffShorts]
    @BranchIds         NVARCHAR(MAX) = NULL,
    @DateFrom          DATE,
    @DateTo            DATE,
    @AllowedBranchIds  NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Scope NVARCHAR(MAX) = NULLIF(LTRIM(RTRIM(ISNULL(@BranchIds, ''))), '');
    IF @Scope IS NULL SET @Scope = NULLIF(LTRIM(RTRIM(ISNULL(@AllowedBranchIds, ''))), '');

    DECLARE @Branch TABLE (BranchId INT PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(INT, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@Scope, ''), ',') s
    WHERE TRY_CONVERT(INT, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @All BIT = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    SELECT COUNT(*)                                AS Shorts,
           CONVERT(MONEY, ISNULL(SUM(v.AmountShort), 0)) AS Amount,
           MIN(v.TransactionDate)                  AS Oldest
    FROM agora.vw_CashStaffShort v
    LEFT JOIN agora.StaffShort a ON a.BranchId = v.BranchId AND a.Id = v.AgoraShortId
    WHERE v.[Status] = 'Approved'
      AND ISNULL(a.Source, '') <> 'recon'
      AND (@All = 1 OR v.BranchId IN (SELECT BranchId FROM @Branch))
      AND v.TransactionDate >= @DateFrom AND v.TransactionDate <= @DateTo;
END
