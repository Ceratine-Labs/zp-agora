/* ============================================================================
   agora.usp_Reports_GridAuditTrail

   WHAT IT IS FOR
   The audit trail: every change Agora has made in PumpIT, newest first. That
   covers stock recon counts, bank recon stamps, Z-read allocations, utility
   readings and the captures. Each row gives the PumpIT row, what changed
   (before → after), why, who and when, and whether it was later reversed.
   Ryan, 1 Oct 2026: "so that we can refer back to it if there's a problem".

   WHERE IT COMES FROM
   agora.vw_PumpItChange, which reads the ledgers the reversals themselves run
   on. Nothing is copied, so this list and what a Reverse would put back
   cannot disagree. Times are SAST.

   WHO READS IT
   Head office, Control → Governance → Audit trail. Search finds a row by
   item, reference, batch, run or person.

   READ-ONLY. The default window is the last 7 days: the bank recon stamps
   alone run to ~35,000 a week (2 Oct 2026, live: 152,644 in a month).

     EXEC agora.usp_Reports_GridAuditTrail @Search = 'Dunhill';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridAuditTrail]
    @BranchIds   NVARCHAR(MAX) = NULL,
    @DateFrom    DATE          = NULL,
    @DateTo      DATE          = NULL,
    @Search      NVARCHAR(200) = NULL,
    @SortColumn  NVARCHAR(80)  = NULL,
    @SortAsc     BIT           = 0,
    @Page        INT           = 1,
    @PageSize    INT           = 50
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;   -- it fills temp tables, and the checker counts those as writes

    SET @DateTo   = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -7, @DateTo));
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');
    SET @SortColumn = NULLIF(@SortColumn, '');   -- none chosen: the last ORDER BY arm, newest first

    DECLARE @Branch TABLE (BranchId int PRIMARY KEY);

    /* The empty-string trap: STRING_SPLIT('') is one row holding '', and
       TRY_CONVERT(int, '') is 0, not NULL. */
    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;
    DECLARE @Total bigint;

    CREATE TABLE #Page (Rn int PRIMARY KEY, BranchId int, Ledger nvarchar(40) COLLATE DATABASE_DEFAULT, LedgerId bigint);

    /* TWO SPEEDS. The view's text columns (the row, the change, the reason) are
       the expensive part: the bank recon arm builds them from JSON and a bank
       statement line per stamp, and a month is ~150,000 stamps. So with no
       search and a sort on a cheap column, find the page on the cheap columns
       first and build the text for those rows only (2 Oct 2026: the first
       version built every row and ran four minutes on live). A search or a sort
       on a text column has to read the text, and takes the slow road. */
    IF @Search IS NULL AND (@SortColumn IS NULL OR @SortColumn IN ('ChangedAt', 'Module', 'Action', 'PumpItTable', 'State'))
    BEGIN
        SELECT c.BranchId, c.Ledger, c.LedgerId, c.ChangedAt, c.Module, c.Action, c.PumpItTable, c.State
        INTO #Keys
        FROM agora.vw_PumpItChange c
        WHERE c.ChangedAt >= @DateFrom AND c.ChangedAt < DATEADD(day, 1, @DateTo)
          AND (@AllBranches = 1 OR c.BranchId IN (SELECT BranchId FROM @Branch));

        SET @Total = (SELECT COUNT_BIG(*) FROM #Keys);

        INSERT INTO #Page (Rn, BranchId, Ledger, LedgerId)
        SELECT k.Ord, k.BranchId, k.Ledger, k.LedgerId
        FROM (SELECT k.*,
                     ROW_NUMBER() OVER (ORDER BY
                        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'Module' THEN k.Module WHEN 'Action' THEN k.Action
                                                    WHEN 'PumpItTable' THEN k.PumpItTable WHEN 'State' THEN k.State END END ASC,
                        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'Module' THEN k.Module WHEN 'Action' THEN k.Action
                                                    WHEN 'PumpItTable' THEN k.PumpItTable WHEN 'State' THEN k.State END END DESC,
                        CASE WHEN @SortAsc = 1 AND @SortColumn = 'ChangedAt' THEN k.ChangedAt END ASC,
                        k.ChangedAt DESC, k.Ledger, k.LedgerId) AS Ord
              FROM #Keys k) k
        WHERE k.Ord > (@Page - 1) * @PageSize AND k.Ord <= @Page * @PageSize;
    END
    ELSE
    BEGIN
        SELECT c.BranchId, c.Ledger, c.LedgerId, c.ChangedAt, c.ReversedAt, c.Module, c.Action, c.PumpItTable,
               c.RowKey, c.Ref, c.State, b.Name AS BranchName, cu.UserName AS ChangedBy
        INTO #Rows
        FROM agora.vw_PumpItChange c
        LEFT JOIN agora.vw_Branch b ON b.BranchId = c.BranchId
        OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = c.ChangedBy) cu
        WHERE c.ChangedAt >= @DateFrom AND c.ChangedAt < DATEADD(day, 1, @DateTo)
          AND (@AllBranches = 1 OR c.BranchId IN (SELECT BranchId FROM @Branch))
          AND (@Search IS NULL
               OR c.RowKey      LIKE '%' + @Search + '%'
               OR c.Change      LIKE '%' + @Search + '%'
               OR c.Reason      LIKE '%' + @Search + '%'
               OR c.Ref         LIKE '%' + @Search + '%'
               OR c.Module      LIKE '%' + @Search + '%'
               OR c.Action      LIKE '%' + @Search + '%'
               OR c.PumpItTable LIKE '%' + @Search + '%'
               OR b.Name        LIKE '%' + @Search + '%'
               OR cu.UserName   LIKE '%' + @Search + '%');

        SET @Total = (SELECT COUNT_BIG(*) FROM #Rows);

        INSERT INTO #Page (Rn, BranchId, Ledger, LedgerId)
        SELECT r.Ord, r.BranchId, r.Ledger, r.LedgerId
        FROM (SELECT r.*,
                     ROW_NUMBER() OVER (ORDER BY
                        CASE WHEN @SortAsc = 1 THEN
                            CASE @SortColumn WHEN 'BranchName'  THEN r.BranchName
                                             WHEN 'ChangedBy'   THEN r.ChangedBy
                                             WHEN 'Module'      THEN r.Module
                                             WHEN 'Action'      THEN r.Action
                                             WHEN 'PumpItTable' THEN r.PumpItTable
                                             WHEN 'RowKey'      THEN r.RowKey
                                             WHEN 'Ref'         THEN r.Ref
                                             WHEN 'State'       THEN r.State END END ASC,
                        CASE WHEN @SortAsc = 0 THEN
                            CASE @SortColumn WHEN 'BranchName'  THEN r.BranchName
                                             WHEN 'ChangedBy'   THEN r.ChangedBy
                                             WHEN 'Module'      THEN r.Module
                                             WHEN 'Action'      THEN r.Action
                                             WHEN 'PumpItTable' THEN r.PumpItTable
                                             WHEN 'RowKey'      THEN r.RowKey
                                             WHEN 'Ref'         THEN r.Ref
                                             WHEN 'State'       THEN r.State END END DESC,
                        CASE WHEN @SortAsc = 1 THEN
                            CASE @SortColumn WHEN 'ChangedAt'  THEN r.ChangedAt
                                             WHEN 'ReversedAt' THEN r.ReversedAt END END ASC,
                        CASE WHEN @SortAsc = 0 THEN
                            CASE @SortColumn WHEN 'ChangedAt'  THEN r.ChangedAt
                                             WHEN 'ReversedAt' THEN r.ReversedAt END END DESC,
                        r.ChangedAt DESC, r.Ledger, r.LedgerId) AS Ord
              FROM #Rows r) r
        WHERE r.Ord > (@Page - 1) * @PageSize AND r.Ord <= @Page * @PageSize;
    END

    /* The page itself, text and all — built for these rows only. CROSS APPLY
       with the key in the predicate lets each arm of the view seek its own
       ledger by (BranchId, Id) rather than build every row and join. */
    SELECT c.BranchId,
           ISNULL(b.Name, CONCAT(N'Site ', c.BranchId)) AS BranchName,
           c.ChangedAt, cu.UserName AS ChangedBy, c.Module, c.Action, c.PumpItTable,
           c.RowKey, c.Change, c.Reason, c.Ref, c.WriteMode, c.State, c.ReversedAt,
           ru.UserName AS ReversedBy, c.ReversalReason, c.Ledger, c.LedgerId, c.Detail
    FROM #Page p
    CROSS APPLY (SELECT v.* FROM agora.vw_PumpItChange v
                 WHERE v.BranchId = p.BranchId AND v.Ledger = p.Ledger AND v.LedgerId = p.LedgerId) c
    LEFT JOIN agora.vw_Branch b ON b.BranchId = c.BranchId
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = c.ChangedBy) cu
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = c.ReversedBy) ru
    ORDER BY p.Rn;

    SELECT @Total AS TotalRows;
END
