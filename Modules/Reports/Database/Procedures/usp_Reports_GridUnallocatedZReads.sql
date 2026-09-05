/* ============================================================================
   agora.usp_Reports_GridUnallocatedZReads

   WHAT IT IS FOR
   Till readings the system has, that nobody has claimed a shift or a person
   for. Until a Z-read is allocated, the takings it represents belong to no
   shift and no employee, so every variance built on top of it is unattributable.

   WHO READS IT
   Head office and the branch manager. It is a queue: the rows are meant to
   disappear.

   THE RULE, AND WHERE IT COMES FROM
   dbo.sp_MissingZREAD, which is the customer's own definition and is reproduced
   exactly:

       ISNULL(TillNo, 0) = 0 AND ISNULL(ShiftNo, 0) = 0 AND ISNULL(EmployeeCode, '') = ''

   All THREE must be empty. A reading with a till but no employee is partially
   allocated, not unallocated, and it is deliberately not in this queue — it is
   returned by the same report with @Search over the till, and it is a different
   conversation with the branch. Measured 4 Sep 2026: 1,965 fully unallocated
   readings out of 166,230.

   WHY THE TRIMS MATTER
   EOD_CNTR, TERMNUM, LOGFILE and USERID are fixed-width CHAR columns that carry
   trailing spaces. agora.vw_ZRead trims them, which is why an equality against
   any of them works here and does not against the base table.

   READ-ONLY.

     EXEC agora.usp_Reports_GridUnallocatedZReads @DateFrom = '2026-08-01';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridUnallocatedZReads]
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
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -90, @DateTo));
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

    SELECT
        z.BranchId,
        b.Name                                      AS BranchName,
        z.ZReadDate,
        z.EodCounter,
        z.TerminalNo,
        z.LogFile,
        z.PosUserId,
        z.Sales,
        z.TillNo,
        z.ShiftNo,
        z.EmployeeCode,
        z.IsCaptured,
        z.MistEod,
        DATEDIFF(day, z.ZReadDate, @DateTo)         AS AgeDays,

        /* The POS user id is the only clue the loader leaves about who was on
           the till. It is not an EmployeeCode and must never be treated as one
           — it is shown so the branch has somewhere to start, and the match, if
           there is one, is a human decision. */
        CASE WHEN z.PosUserId IS NULL OR z.PosUserId = '' THEN 'No POS user either'
             ELSE 'POS user ' + z.PosUserId END      AS Clue
    INTO #Rows
    FROM agora.vw_ZRead z
    JOIN agora.vw_Branch b ON b.BranchId = z.BranchId
    WHERE z.ZReadDate >= @DateFrom AND z.ZReadDate < DATEADD(day, 1, @DateTo)
      AND ISNULL(z.TillNo, 0) = 0
      AND ISNULL(z.ShiftNo, 0) = 0
      AND ISNULL(z.EmployeeCode, '') = ''
      AND (@AllBranches = 1 OR z.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL
           OR b.Name        LIKE '%' + @Search + '%'
           OR z.EodCounter  LIKE '%' + @Search + '%'
           OR z.TerminalNo  LIKE '%' + @Search + '%'
           OR z.LogFile     LIKE '%' + @Search + '%'
           OR z.PosUserId   LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.ZReadDate, r.EodCounter, r.TerminalNo,
           r.LogFile, r.PosUserId, r.Sales, r.TillNo, r.ShiftNo, r.EmployeeCode,
           r.IsCaptured, r.MistEod, r.AgeDays, r.Clue
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName' THEN r.BranchName
                             WHEN 'EodCounter' THEN r.EodCounter
                             WHEN 'TerminalNo' THEN r.TerminalNo
                             WHEN 'LogFile'    THEN r.LogFile
                             WHEN 'PosUserId'  THEN r.PosUserId END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName' THEN r.BranchName
                             WHEN 'EodCounter' THEN r.EodCounter
                             WHEN 'TerminalNo' THEN r.TerminalNo
                             WHEN 'LogFile'    THEN r.LogFile
                             WHEN 'PosUserId'  THEN r.PosUserId END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'Sales'   THEN CONVERT(decimal(38,6), r.Sales)
                             WHEN 'AgeDays' THEN CONVERT(decimal(38,6), r.AgeDays) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'Sales'   THEN CONVERT(decimal(38,6), r.Sales)
                             WHEN 'AgeDays' THEN CONVERT(decimal(38,6), r.AgeDays) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'ZReadDate' THEN CONVERT(datetime2, r.ZReadDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'ZReadDate' THEN CONVERT(datetime2, r.ZReadDate) END END DESC,
        /* Oldest first — a queue is worked from the back. */
        r.ZReadDate, r.BranchName, r.EodCounter, r.TerminalNo, r.LogFile
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
