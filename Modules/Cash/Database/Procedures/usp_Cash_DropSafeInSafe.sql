/* ============================================================================
   agora.usp_Cash_DropSafeInSafe

   WHAT IT IS FOR
   "In the safe": the bags dropped at a site and not yet handed to the security
   company, counted per site. The chip on the Drop safe screens, the figure
   behind the branch console's drop safe step (T029, when it lands) and the
   badge a console reads (cash.dropsafe.insafe).

   WHAT "IN THE SAFE" MEANS, and why it is PumpIT's own rule:
   a bag is in the safe while BRN_DropSafe.CollectionId is 0 — exactly the rows
   PumpIT's own collection screen lists to pick from
   (dbo.sp_SelectDropSafeCapture: "where SSBranchId = @BranchId and
   CollectionId = 0", with no date limit). A bag that is already on a cashup
   is still in the safe until it is collected; OnCashup counts those.

   WHO READS IT
   Cash → Drop safe and the phone screen (one site); the badge (the sites in
   the person's scope).

   @BranchIds  CSV of sites; NULL or empty = every site.

   One row per site that has a bag in the safe. A site with none has no row —
   the caller reads that as nought.

   READ-ONLY.

     EXEC agora.usp_Cash_DropSafeInSafe @BranchIds = '3,7,22';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_DropSafeInSafe]
    @BranchIds  NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Branch TABLE (BranchId INT PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches BIT = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    SELECT
        d.BranchId,
        MAX(b.Name)                                                   AS BranchName,
        COUNT(*)                                                      AS Bags,
        CONVERT(MONEY, SUM(d.Amount))                                 AS Amount,
        CONVERT(DATE, MIN(d.DropDate))                                AS OldestDropDate,
        CONVERT(DATE, MAX(d.DropDate))                                AS NewestDropDate,
        SUM(CASE WHEN d.IsSelectedForDailyBanking = 1 THEN 1 ELSE 0 END) AS OnCashup
    FROM agora.vw_DropSafeBag d
    OUTER APPLY (SELECT TOP 1 x.Name FROM agora.Branch x WHERE x.BranchId = d.BranchId ORDER BY x.Id) b
    WHERE ISNULL(d.CollectionId, 0) = 0
      AND (@AllBranches = 1 OR d.BranchId IN (SELECT BranchId FROM @Branch))
    GROUP BY d.BranchId
    ORDER BY MAX(b.Name), d.BranchId;
END
