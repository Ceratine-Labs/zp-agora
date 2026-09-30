/* ============================================================================
   agora.usp_Cash_GridStaffShorts

   WHAT IT IS FOR
   Staff shorts across sites: every short raised, the code it reads as, the
   note, and where it stands — waiting for an approver, approved and not yet on
   a cashup, posted, or declined. PumpIT's staff-shorts list
   (dbo.sp_SelectStaffShorts) and its approval queue
   (dbo.sp_SelectUnApprovedStaffShortsByApproverUser) as one grid.

   WHO READS IT
   Cash → Staff shorts list (app.cash.staffshorts.list), and head office's
   Today → Waiting on a decision → Staff shorts. The approver band of the
   legacy queue belongs to the screen (cash.staffshort.approve); the branches
   selector scopes the rows, as usp_Reports_GridStaffShorts does.

   ONE ROW PER SHORT (agora.vw_CashStaffShort). ReasonCode is Agora's code for a
   short Agora raised and, for a legacy one, the code its free-text reason reads
   as (agora.StaffShortReasonAlias) — 'other' when none — with the text as typed
   kept in Note. So the eleven-plus spellings of "pump short" filter as one.
   Names are looked up TOP 1, so nothing multiplies a row.

   THE PERIOD is the short's day (TransactionDate — the day for a short raised
   in Agora, the moment of capture for one raised in PumpIT). Waiting and
   approved-but-unposted shorts are listed WHATEVER their date while
   @IncludeOpen is 1 (the default): they are the work, and an approver who
   narrows the dates must not lose one.

   The grid procedure template (feature-rules §2), plus @AllowedBranchIds (the
   sites the person may see; NULL = every site — an empty @BranchIds means
   these) and @IncludeOpen.

   Result sets: 1 the page, 2 (TotalRows), 3 grand totals (AmountShort,
   WaitingAmount, Waiting, ApprovedAmount, Approved).

   READ-ONLY.

     EXEC agora.usp_Cash_GridStaffShorts @BranchIds = '6', @DateFrom = '2026-09-01', @DateTo = '2026-09-27';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GridStaffShorts]
    @BranchIds         NVARCHAR(MAX) = NULL,
    @DateFrom          DATE          = NULL,
    @DateTo            DATE          = NULL,
    @Search            NVARCHAR(200) = NULL,
    @SortColumn        NVARCHAR(80)  = NULL,
    @SortAsc           BIT           = 1,
    @Page              INT           = 1,
    @PageSize          INT           = 50,
    @FiltersJson       NVARCHAR(MAX) = NULL,
    @AllowedBranchIds  NVARCHAR(MAX) = NULL,
    @IncludeOpen       BIT           = 1
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Today DATE = CONVERT(DATE, GETDATE());

    SET @Page        = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize    = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search      = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');
    SET @DateTo      = ISNULL(@DateTo, @Today);
    SET @DateFrom    = ISNULL(@DateFrom, DATEADD(day, -29, @DateTo));
    SET @IncludeOpen = ISNULL(@IncludeOpen, 1);

    DECLARE @From DATETIME = @DateFrom, @To DATETIME = DATEADD(day, 1, CONVERT(DATETIME, @DateTo));

    DECLARE @Branch TABLE (BranchId INT PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    IF NOT EXISTS (SELECT 1 FROM @Branch)
        INSERT INTO @Branch (BranchId)
        SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
        FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
        WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches BIT = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    SELECT
        s.BranchId,
        b.Name                                                   AS BranchName,
        s.StaffShortsNo,
        s.TransactionDate,
        s.EmployeeCode,
        ISNULL(s.EmployeeName, s.EmployeeCode)                   AS EmployeeName,
        s.AmountShort,
        s.ReasonCode,
        s.ReasonLabel,
        s.Note,
        s.[Status],
        s.RaisedIn,
        COALESCE(ru.UserName, rl.UserName)                       AS RaisedBy,
        COALESCE(du.UserName, dl.UserName)                       AS DecidedBy,
        CONVERT(DATE, CASE WHEN s.IsDeclined = 1 THEN s.DeclinedDate ELSE s.ApprovedDate END) AS DecidedAt,
        COALESCE(de.DeclineNote, s.DeclinedReason)               AS DeclinedReason,
        CASE WHEN s.PostedTillNo IS NOT NULL
             THEN CONCAT('till ', s.PostedTillNo, ', shift ', s.PostedShiftNo, ' on ', CONVERT(CHAR(10), s.PostedDate, 23)) END AS PostedTo,
        s.PostedDate,
        CASE s.Source WHEN 'recon' THEN 'Cash-bag match' WHEN 'phone' THEN 'Phone' WHEN 'screen' THEN 'Staff shorts'
                      ELSE 'PumpIT' END                          AS Source,
        s.SourceRef,
        s.CapturedAt,
        DATEDIFF(day, s.TransactionDate, @Today)                 AS AgeDays
    INTO #sg_rows
    FROM agora.vw_CashStaffShort s
    OUTER APPLY (SELECT TOP 1 x.Name FROM agora.Branch x WHERE x.BranchId = s.BranchId ORDER BY x.Id) b
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = s.RaisedByUserId) ru
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.vw_ErpUser u WHERE u.ErpUserId = s.CreatedByUserId) rl
    OUTER APPLY (SELECT TOP 1 e.CreatedBy, CASE WHEN e.[Event] = 'declined' THEN e.Note END AS DeclineNote
                 FROM agora.StaffShortEvent e
                 WHERE e.BranchId = s.BranchId AND e.StaffShortsNo = s.StaffShortsNo AND e.[Event] IN ('approved', 'declined')
                 ORDER BY e.Id DESC) de
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = de.CreatedBy) du
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.vw_ErpUser u
                 WHERE u.ErpUserId = CASE WHEN s.IsDeclined = 1 THEN NULLIF(s.DeclinedByUserId, 0) ELSE s.ApprovedByUserId END) dl
    WHERE (   (s.TransactionAt >= @From AND s.TransactionAt < @To)
           OR (@IncludeOpen = 1 AND s.[Status] IN ('Waiting', 'Approved')))
      AND (@AllBranches = 1 OR s.BranchId IN (SELECT BranchId FROM @Branch));

    IF @Search IS NOT NULL
        DELETE FROM #sg_rows
        WHERE NOT (   ISNULL(BranchName, '')     LIKE '%' + @Search + '%'
                   OR CONVERT(NVARCHAR(12), StaffShortsNo) = @Search
                   OR ISNULL(EmployeeCode, '')   LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeName, '')   LIKE '%' + @Search + '%'
                   OR ISNULL(ReasonLabel, '')    LIKE '%' + @Search + '%'
                   OR ISNULL(Note, '')           LIKE '%' + @Search + '%'
                   OR ISNULL(RaisedBy, '')       LIKE '%' + @Search + '%'
                   OR ISNULL(DecidedBy, '')      LIKE '%' + @Search + '%'
                   OR ISNULL(DeclinedReason, '') LIKE '%' + @Search + '%'
                   OR ISNULL(SourceRef, '')      LIKE '%' + @Search + '%');

    DECLARE @Filter    TABLE ([Column] NVARCHAR(80), [Type] NVARCHAR(10), [Op] NVARCHAR(20), [Value] NVARCHAR(400));
    DECLARE @FilterSet TABLE ([Column] NVARCHAR(80), [Value] NVARCHAR(400));

    IF @FiltersJson IS NOT NULL AND ISJSON(@FiltersJson) = 1
    BEGIN
        INSERT INTO @Filter ([Column], [Type], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'),
               ISNULL(JSON_VALUE(f.value, '$.type'), 'text'),
               ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
               JSON_VALUE(f.value, '$.value')
        FROM OPENJSON(@FiltersJson) f
        WHERE JSON_VALUE(f.value, '$.value') IS NOT NULL
          AND ISNULL(JSON_VALUE(f.value, '$.type'), 'text') <> 'set';

        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), v.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') v
        WHERE JSON_VALUE(f.value, '$.type') = 'set';
    END

    DELETE r
    FROM #sg_rows r
    WHERE EXISTS (
        SELECT 1
        FROM @Filter f
        CROSS APPLY (SELECT
            CASE f.[Column]
                WHEN 'BranchName'     THEN r.BranchName
                WHEN 'EmployeeCode'   THEN r.EmployeeCode
                WHEN 'EmployeeName'   THEN r.EmployeeName
                WHEN 'Note'           THEN r.Note
                WHEN 'RaisedBy'       THEN r.RaisedBy
                WHEN 'DecidedBy'      THEN r.DecidedBy
                WHEN 'DeclinedReason' THEN r.DeclinedReason
                WHEN 'PostedTo'       THEN r.PostedTo
                WHEN 'SourceRef'      THEN r.SourceRef
            END AS T,
            CASE f.[Column]
                WHEN 'AmountShort'    THEN CONVERT(DECIMAL(19,4), r.AmountShort)
                WHEN 'StaffShortsNo'  THEN CONVERT(DECIMAL(19,4), r.StaffShortsNo)
                WHEN 'AgeDays'        THEN CONVERT(DECIMAL(19,4), r.AgeDays)
            END AS N,
            CASE f.[Column]
                WHEN 'TransactionDate' THEN r.TransactionDate
                WHEN 'DecidedAt'       THEN r.DecidedAt
                WHEN 'PostedDate'      THEN r.PostedDate
            END AS D,
            TRY_CONVERT(DECIMAL(19,4), f.[Value]) AS FN,
            TRY_CONVERT(DATE, f.[Value]) AS FD
        ) v
        WHERE 1 = CASE
            WHEN f.[Type] = 'number' THEN CASE
                WHEN v.N IS NULL OR v.FN IS NULL THEN 1
                WHEN f.[Op] = 'ne'  AND v.N <> v.FN THEN 0
                WHEN f.[Op] = 'gt'  AND v.N >  v.FN THEN 0
                WHEN f.[Op] = 'gte' AND v.N >= v.FN THEN 0
                WHEN f.[Op] = 'lt'  AND v.N <  v.FN THEN 0
                WHEN f.[Op] = 'lte' AND v.N <= v.FN THEN 0
                WHEN f.[Op] NOT IN ('ne', 'gt', 'gte', 'lt', 'lte') AND v.N = v.FN THEN 0
                ELSE 1 END
            WHEN f.[Type] = 'date' THEN CASE
                WHEN v.D IS NULL OR v.FD IS NULL THEN 1
                WHEN f.[Op] = 'ne'  AND v.D <> v.FD THEN 0
                WHEN f.[Op] = 'gt'  AND v.D >  v.FD THEN 0
                WHEN f.[Op] = 'gte' AND v.D >= v.FD THEN 0
                WHEN f.[Op] = 'lt'  AND v.D <  v.FD THEN 0
                WHEN f.[Op] = 'lte' AND v.D <= v.FD THEN 0
                WHEN f.[Op] NOT IN ('ne', 'gt', 'gte', 'lt', 'lte') AND v.D = v.FD THEN 0
                ELSE 1 END
            ELSE CASE
                WHEN v.T IS NULL THEN 1
                WHEN f.[Op] = 'eq' AND v.T =  f.[Value] THEN 0
                WHEN f.[Op] = 'ne' AND v.T <> f.[Value] THEN 0
                WHEN f.[Op] NOT IN ('eq', 'ne') AND v.T LIKE '%' + f.[Value] + '%' THEN 0
                ELSE 1 END
        END
    )
    OR EXISTS (
        SELECT 1
        FROM (SELECT DISTINCT [Column] FROM @FilterSet) c
        WHERE NOT EXISTS (
            SELECT 1 FROM @FilterSet s
            WHERE s.[Column] = c.[Column]
              AND s.[Value] = CASE c.[Column]
                                  WHEN 'BranchName'  THEN r.BranchName
                                  WHEN 'Status'      THEN r.[Status]
                                  WHEN 'ReasonLabel' THEN r.ReasonLabel
                                  WHEN 'RaisedIn'    THEN r.RaisedIn
                                  WHEN 'Source'      THEN r.Source
                              END)
    );

    DECLARE @Offset INT = (@Page - 1) * @PageSize;

    SELECT *
    FROM #sg_rows
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'BranchName'     THEN BranchName
                WHEN 'EmployeeCode'   THEN EmployeeCode
                WHEN 'EmployeeName'   THEN EmployeeName
                WHEN 'ReasonLabel'    THEN ReasonLabel
                WHEN 'Status'         THEN [Status]
                WHEN 'RaisedIn'       THEN RaisedIn
                WHEN 'RaisedBy'       THEN RaisedBy
                WHEN 'DecidedBy'      THEN DecidedBy
                WHEN 'Source'         THEN Source
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'BranchName'     THEN BranchName
                WHEN 'EmployeeCode'   THEN EmployeeCode
                WHEN 'EmployeeName'   THEN EmployeeName
                WHEN 'ReasonLabel'    THEN ReasonLabel
                WHEN 'Status'         THEN [Status]
                WHEN 'RaisedIn'       THEN RaisedIn
                WHEN 'RaisedBy'       THEN RaisedBy
                WHEN 'DecidedBy'      THEN DecidedBy
                WHEN 'Source'         THEN Source
            END
        END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'AmountShort'    THEN CONVERT(DECIMAL(19,4), AmountShort)
                WHEN 'StaffShortsNo'  THEN CONVERT(DECIMAL(19,4), StaffShortsNo)
                WHEN 'AgeDays'        THEN CONVERT(DECIMAL(19,4), AgeDays)
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'AmountShort'    THEN CONVERT(DECIMAL(19,4), AmountShort)
                WHEN 'StaffShortsNo'  THEN CONVERT(DECIMAL(19,4), StaffShortsNo)
                WHEN 'AgeDays'        THEN CONVERT(DECIMAL(19,4), AgeDays)
            END
        END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'TransactionDate' THEN TransactionDate
                WHEN 'DecidedAt'       THEN DecidedAt
                WHEN 'PostedDate'      THEN PostedDate
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'TransactionDate' THEN TransactionDate
                WHEN 'DecidedAt'       THEN DecidedAt
                WHEN 'PostedDate'      THEN PostedDate
            END
        END DESC,
        /* The work first: waiting for an approver, then approved and not posted. */
        CASE [Status] WHEN 'Waiting' THEN 0 WHEN 'Approved' THEN 1 ELSE 2 END, TransactionDate DESC, StaffShortsNo DESC
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #sg_rows;

    SELECT CONVERT(MONEY, ISNULL(SUM(ISNULL(AmountShort, 0)), 0))                                        AS AmountShort,
           CONVERT(MONEY, ISNULL(SUM(CASE WHEN [Status] = 'Waiting' THEN ISNULL(AmountShort, 0) ELSE 0 END), 0))  AS WaitingAmount,
           ISNULL(SUM(CASE WHEN [Status] = 'Waiting' THEN 1 ELSE 0 END), 0)                              AS Waiting,
           CONVERT(MONEY, ISNULL(SUM(CASE WHEN [Status] = 'Approved' THEN ISNULL(AmountShort, 0) ELSE 0 END), 0)) AS ApprovedAmount,
           ISNULL(SUM(CASE WHEN [Status] = 'Approved' THEN 1 ELSE 0 END), 0)                             AS Approved
    FROM #sg_rows;

    DROP TABLE #sg_rows;
END
