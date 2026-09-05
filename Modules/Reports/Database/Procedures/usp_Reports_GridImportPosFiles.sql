/* ============================================================================
   agora.usp_Reports_GridImportPosFiles

   WHAT IT IS FOR
   What each branch is CONFIGURED to import, from which POS system and which
   drive, and whether that configuration produced anything on the day.

   WHO READS IT
   Whoever is asked "why is this site missing from the report" — and the answer
   is nearly always one of three things: the site does not import that feed at
   all, the loader has not run, or it ran and found nothing.

   HOW IT DIFFERS FROM usp_Reports_GridOvernightLoads
   Overnight loads counts what landed. This says what SHOULD land. A branch with
   no BRN_POSImportSelection row is not broken — it is a site that does not
   import POS files, and putting it on the overnight-loads report as a permanent
   red row is how a daily check stops being read. The two together answer the
   question; neither does on its own.

   WHAT THE ESTATE ACTUALLY HOLDS, measured 4 Sep 2026:
   BRN_POSImportSelection has 83 rows. BRN_POSImportedFiles — the table whose
   name promises a per-file log — is EMPTY, and has been. There is no per-file
   history in PumpIT to report on, so this report is configuration against
   outcome, and the file-by-file view the mockup shows would need Agora to keep
   its own import log. Named here rather than faked.

   READ-ONLY.

     EXEC agora.usp_Reports_GridImportPosFiles @DateFrom = '2026-09-03';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridImportPosFiles]
    @BranchIds   NVARCHAR(MAX) = NULL,
    @DateFrom    DATE          = NULL,
    @DateTo      DATE          = NULL,
    @Search      NVARCHAR(200) = NULL,
    @SortColumn  NVARCHAR(80)  = NULL,
    @SortAsc     BIT           = 1,
    @Page        INT           = 1,
    @PageSize    INT           = 50
AS
BEGIN
    SET NOCOUNT ON;

    SET @DateTo   = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, @DateTo);
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    DECLARE @Branch TABLE (BranchId int PRIMARY KEY);

    /* The empty-string trap. STRING_SPLIT('', ',') returns ONE row holding an
       empty string, and TRY_CONVERT(int, '') is 0 — not NULL. Without the
       first predicate below, a caller who passes no branches gets a @Branch
       table containing branch 0, @AllBranches is 0, and every report in this
       module silently returns nothing at all. Caught on the first run. */
    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    /* What each branch produced in the window, counted once. */
    SELECT y.BranchId, COUNT_BIG(*) AS DayEnds, MAX(y.DayEndDate) AS LastDayEndDate
    INTO #Landed
    FROM agora.vw_DayEnd y
    WHERE y.DayEndDate >= @DateFrom AND y.DayEndDate < DATEADD(day, 1, @DateTo)
    GROUP BY y.BranchId;

    SELECT z.BranchId, COUNT_BIG(*) AS ZReads
    INTO #ZReads
    FROM agora.vw_ZRead z
    WHERE z.ZReadDate >= @DateFrom AND z.ZReadDate < DATEADD(day, 1, @DateTo)
    GROUP BY z.BranchId;

    /* LEFT JOIN from the branch, not from the configuration: a branch with the
       POS import switch on and NO configuration row is precisely the
       misconfiguration this report should surface, and joining the other way
       round would hide it. */
    SELECT
        b.BranchId,
        b.BranchName,
        b.POSType                                   AS BranchPosType,
        s.POSType                                   AS ConfiguredPosType,
        s.POSImportType,
        s.ImportFromDrive,
        b.IsPOSImport,
        b.IsARCHImport,
        b.IsMSACCESSImport,
        b.BranchLastImportAt,
        b.StockLastImportAt,
        b.ReconLastImportAt,
        b.NamosLastImportAt,
        ISNULL(l.DayEnds, 0)                        AS DayEnds,
        l.LastDayEndDate,
        ISNULL(z.ZReads, 0)                         AS ZReads,
        /* Order matters. A branch can carry configuration rows AND have every
           import switch off — 'nothing arrived' would be the true statement and
           the useless one, because nothing was ever going to. The switches are
           tested before the outcome. */
        CASE WHEN s.POSType IS NULL AND b.IsPOSImport = 1 THEN 'POS import on, nothing configured'
             WHEN s.POSType IS NULL                       THEN 'Not configured for POS import'
             WHEN b.IsPOSImport = 0 AND b.IsARCHImport = 0 AND b.IsMSACCESSImport = 0
                                                          THEN 'Configured, but every import switch is off'
             WHEN ISNULL(l.DayEnds, 0) = 0 AND ISNULL(z.ZReads, 0) = 0 THEN 'Configured, nothing arrived'
             WHEN ISNULL(l.DayEnds, 0) = 0                THEN 'Z-reads only, no day end'
             ELSE 'Imported' END                    AS Status
    INTO #Rows
    FROM agora.vw_BranchImportStatus b
    LEFT JOIN agora.vw_PosImportSelection s ON s.BranchId = b.BranchId
    LEFT JOIN #Landed l ON l.BranchId = b.BranchId
    LEFT JOIN #ZReads z ON z.BranchId = b.BranchId
    WHERE b.IsActive = 1
      AND (@AllBranches = 1 OR b.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL
           OR b.BranchName     LIKE '%' + @Search + '%'
           OR s.POSType        LIKE '%' + @Search + '%'
           OR s.POSImportType  LIKE '%' + @Search + '%'
           OR s.ImportFromDrive LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.BranchPosType, r.ConfiguredPosType,
           r.POSImportType, r.ImportFromDrive,
           r.IsPOSImport, r.IsARCHImport, r.IsMSACCESSImport,
           r.BranchLastImportAt, r.StockLastImportAt, r.ReconLastImportAt, r.NamosLastImportAt,
           r.DayEnds, r.LastDayEndDate, r.ZReads, r.Status
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'        THEN r.BranchName
                             WHEN 'ConfiguredPosType' THEN r.ConfiguredPosType
                             WHEN 'POSImportType'     THEN r.POSImportType
                             WHEN 'Status'            THEN r.Status END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'        THEN r.BranchName
                             WHEN 'ConfiguredPosType' THEN r.ConfiguredPosType
                             WHEN 'POSImportType'     THEN r.POSImportType
                             WHEN 'Status'            THEN r.Status END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'DayEnds' THEN CONVERT(decimal(38,6), r.DayEnds)
                             WHEN 'ZReads'  THEN CONVERT(decimal(38,6), r.ZReads) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'DayEnds' THEN CONVERT(decimal(38,6), r.DayEnds)
                             WHEN 'ZReads'  THEN CONVERT(decimal(38,6), r.ZReads) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchLastImportAt' THEN CONVERT(datetime2, r.BranchLastImportAt)
                             WHEN 'LastDayEndDate'     THEN CONVERT(datetime2, r.LastDayEndDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchLastImportAt' THEN CONVERT(datetime2, r.BranchLastImportAt)
                             WHEN 'LastDayEndDate'     THEN CONVERT(datetime2, r.LastDayEndDate) END END DESC,
        r.Status, r.BranchName
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
