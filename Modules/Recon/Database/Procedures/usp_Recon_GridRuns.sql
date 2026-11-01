/*
 * agora.usp_Recon_GridRuns — the runs made in one reconciliation area.
 *
 * Read by:  Recon -> {area} -> Runs (the third tab of the area workbench), and
 *           the Recon hub, which renders the same grid with no area.
 * Reads:    agora.ReconRun, agora.Branch, agora.[User]
 * Writes:   nothing.
 *
 * The grid procedure template (feature-rules §2), plus four arguments the nine
 * have no room for. They are NOT header filters — the person did not type them
 * and cannot clear them — so they arrive as extra named parameters rather than
 * inside @FiltersJson:
 *
 *   @ReconArea         which area's tab this is. NULL is the hub: every area.
 *   @MineOnly          the Mine / All switch. Default 1: a clerk opening the
 *                      tab is looking for their own morning's work, and
 *                      showing everybody's is what ZP asked us to stop.
 *   @UserId            who "mine" is. Resolved from the session, never the
 *                      query string — a user id that arrives in a URL is a
 *                      user id somebody can change.
 *   @AllowedBranchIds  every site this person may see at all, which is a
 *                      different question from the sites they have SELECTED.
 *                      A head-office user with a partial grant and no
 *                      selection must still not see the rest of the estate,
 *                      and @BranchIds is empty in exactly that case.
 *   @OpenOnly          the Open work / Everything switch (23 Sep 2026). 1
 *                      shows only runs still in hand — 'previewing' and
 *                      'previewed'. A runs list that grows forever is why
 *                      "clear old runs" was asked for: on live that morning the
 *                      clerks' lists held 1,136 open previews and 236 failed
 *                      runs between them. Default 0 here, so every caller that
 *                      predates it is unchanged; the screen passes 1 unless
 *                      the person asked for everything. Committed, reversed,
 *                      closed and failed runs are one switch away, and the
 *                      Status filter still narrows within either setting.
 *
 * THE @FiltersJson SHAPE, which is the thing to get right here.
 * GridQuery::filtersJson() sends a JSON ARRAY of objects that GridFilter
 * produced:
 *
 *     [{"column":"Note","type":"text","op":"contains","value":"August"},
 *      {"column":"Status","type":"set","in":["previewed","committed"]}]
 *
 * So the column name is INSIDE each element, at $.column — it is not the
 * OPENJSON key, which over an array is the index 0, 1, 2. agora.
 * usp_Core_GridUsers reads it the other way and its header filters have
 * therefore never narrowed anything; that is its own bug task, and this
 * procedure is the worked example the fix follows.
 *
 * THE DATE RANGE IS THE RUN'S PERIOD, NOT WHEN IT WAS RUN. A recon clerk
 * saying "August" means the August statement, and the tab beside this one uses
 * the same two dates to mean exactly that. Overlap rather than containment: a
 * run of 25 July to 5 August is part of what happened in August, and hiding it
 * from an August search would be the screen quietly dropping evidence.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_GridRuns]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @Search           NVARCHAR(200) = NULL,
    @SortColumn       NVARCHAR(80)  = NULL,
    @SortAsc          BIT           = 1,
    @Page             INT           = 1,
    @PageSize         INT           = 50,
    @FiltersJson      NVARCHAR(MAX) = NULL,
    @ReconArea        NVARCHAR(20)  = NULL,
    @MineOnly         BIT           = 1,
    @UserId           INT           = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @OpenOnly         BIT           = 0,
    @InvalidOnly      BIT           = 0
AS
BEGIN
    SET NOCOUNT ON;

    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    DECLARE @Offset INT = (@Page - 1) * @PageSize;

    /* One exact permission/filter scope shared with bulk archive; no ceiling. */
    CREATE TABLE #RunGridScope (RunId bigint PRIMARY KEY);
    INSERT INTO #RunGridScope EXEC agora.usp_Recon_RunScope
        @BranchIds=@BranchIds, @DateFrom=@DateFrom, @DateTo=@DateTo,
        @Search=@Search, @FiltersJson=@FiltersJson, @ReconArea=@ReconArea,
        @MineOnly=@MineOnly, @UserId=@UserId, @AllowedBranchIds=@AllowedBranchIds,
        @OpenOnly=@OpenOnly;

    SELECT
        r.Id,
        r.BranchId,
        b.Name AS BranchName,
        r.ReconArea,
        /* The run's own name, and a readable stand-in when nobody gave it
           one. A column of forty dashes is a column nobody can navigate,
           so an unnamed run is described by the thing that distinguishes
           it — and the filter below then matches what is on screen. */
        ISNULL(NULLIF(LTRIM(RTRIM(r.Note)), ''),
               CONVERT(nvarchar(10), r.FromDate, 120) + ' to ' + CONVERT(nvarchar(10), r.ToDate, 120)) AS Note,
        r.FromDate,
        r.ToDate,
        r.Status,
        r.StampMode,
        r.ProcedureName,
        r.TotalRows,
        r.MatchedRows,
        r.MismatchRows,
        r.BankOnlyRows,
        r.DepositOnlyRows,
        r.OtherRows,
        r.MatchedTotal,
        r.CommittedRows,
        r.CommittedTotal,
        r.PreviewMs,
        r.CreatedAt,
        u.UserName AS RunBy,
        /* Whether this run is still open work. A previewed run that was
           never committed is the one a clerk comes back to; anything else
           is history. Returned rather than derived on the page, so the row
           action and the resume card cannot disagree about it. */
        CONVERT(bit, CASE WHEN r.Status = 'previewed' THEN 1 ELSE 0 END) AS IsOpen,
        /* Which working-list gesture the row offers. Status alone, on
           purpose: whether anything has been processed against a run is
           decided by usp_Recon_DiscardRuns and nowhere else, and it
           refuses the rare run that says 'previewed' but has evidence
           hanging off it. The row only has to know which button to show. */
        CONVERT(bit, CASE WHEN r.Status IN ('previewed', 'failed') THEN 1 ELSE 0 END) AS IsClosable,
        CONVERT(bit, CASE WHEN r.Status = 'closed' THEN 1 ELSE 0 END) AS IsClosed
    INTO #RunGridRows
    FROM agora.ReconRun r
    JOIN #RunGridScope scope ON scope.RunId=r.Id
    LEFT JOIN agora.Branch b ON b.BranchId = r.BranchId AND b.DeletedAt IS NULL
    LEFT JOIN agora.[User] u ON u.Id = r.CreatedBy;

    CREATE TABLE #RunGridValidity (RunId bigint PRIMARY KEY, Validity nvarchar(12),
        InvalidRows int NULL, RemainingRows int NULL, IsInvalid bit);
    DECLARE @ValidityIds nvarchar(max);
    /* Only the Invalid filter needs to inspect every scoped run. An ordinary
       page proves just its visible rows; closed/processed history never drills. */
    IF ISNULL(@InvalidOnly,0)=1
    BEGIN
        SELECT @ValidityIds=N'['+ISNULL(STRING_AGG(CONVERT(nvarchar(max),RunId),N','),N'')+N']'
        FROM #RunGridScope;
        INSERT INTO #RunGridValidity EXEC agora.usp_Recon_RunValidity @RunIdsJson=@ValidityIds, @InvalidOnly=1;
        DELETE r FROM #RunGridRows r
        WHERE NOT EXISTS (SELECT 1 FROM #RunGridValidity v WHERE v.RunId=r.Id AND v.IsInvalid=1);
    END

    SELECT *, ROW_NUMBER() OVER (ORDER BY
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'Id' THEN Id END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'Id' THEN Id END DESC,
        /* Text and date sorts kept apart so each compares as itself — '10'
           after '9', not before it. */
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'Note'       THEN Note
                WHEN 'BranchName' THEN BranchName
                WHEN 'Status'     THEN Status
                WHEN 'RunBy'      THEN RunBy
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'Note'       THEN Note
                WHEN 'BranchName' THEN BranchName
                WHEN 'Status'     THEN Status
                WHEN 'RunBy'      THEN RunBy
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'FromDate'     THEN FromDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'FromDate'     THEN FromDate END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'ToDate'       THEN ToDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'ToDate'       THEN ToDate END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'TotalRows'    THEN TotalRows END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'TotalRows'    THEN TotalRows END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'MatchedRows'  THEN MatchedRows END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'MatchedRows'  THEN MatchedRows END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'MatchedTotal' THEN MatchedTotal END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'MatchedTotal' THEN MatchedTotal END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'CreatedAt'    THEN CreatedAt END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'CreatedAt'    THEN CreatedAt END DESC,
        /* Newest first is the default and also the tie-break: the run a person
           wants is almost always the one they just made. */
        Id DESC) AS GridRowNumber
    INTO #RunGridRanked
    FROM #RunGridRows;

    IF ISNULL(@InvalidOnly,0)=0
    BEGIN
        SELECT @ValidityIds=N'['+ISNULL(STRING_AGG(CONVERT(nvarchar(max),Id),N','),N'')+N']'
        FROM #RunGridRanked WHERE GridRowNumber>@Offset AND GridRowNumber<=CONVERT(bigint,@Offset)+@PageSize;
        INSERT INTO #RunGridValidity EXEC agora.usp_Recon_RunValidity @RunIdsJson=@ValidityIds;
    END

    /* Original columns retain their order; validity is append-only. */
    SELECT
        Id, Note, BranchId, BranchName, ReconArea, FromDate, ToDate, Status, RunBy, CreatedAt,
        TotalRows, MatchedRows, MismatchRows, BankOnlyRows, DepositOnlyRows, OtherRows,
        MatchedTotal, CommittedRows, CommittedTotal, PreviewMs, StampMode, ProcedureName, IsOpen,
        IsClosable, IsClosed,
        v.Validity, v.InvalidRows, v.RemainingRows, v.IsInvalid
    FROM #RunGridRanked r
    JOIN #RunGridValidity v ON v.RunId=r.Id
    WHERE GridRowNumber>@Offset AND GridRowNumber<=CONVERT(bigint,@Offset)+@PageSize
    ORDER BY GridRowNumber;

    SELECT COUNT_BIG(*) AS TotalRows FROM #RunGridRows;
END
