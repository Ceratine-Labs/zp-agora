/*
 * agora.usp_Recon_GridCriteria — the extraction configuration, ours beside
 * theirs.
 *
 * Read by:  Recon -> {area} -> Configuration
 * Reads:    agora.ReconCriteria, agora.vw_AutoReconCriteria, agora.Branch,
 *           agora.[User], and (only when asked) agora.vw_BankStatementLine
 * Writes:   nothing.
 *
 * EVERY ROW SHOWS BOTH VALUES, ALWAYS. That is not a nicety, it is the price
 * of the design Ryan chose on 8 September 2026: an Agora override shadows the
 * customer's row, so the configuration now has two sources of truth and ZP can
 * still edit theirs in SSMS without telling us. A screen that showed only the
 * effective value would make that divergence invisible, which is exactly the
 * failure mode the whole module exists to end.
 *
 * So each row carries three things: what is IN EFFECT (what the previews will
 * actually resolve), what the CUSTOMER'S row says, and where the effective
 * value came from —
 *
 *   Customer         no override; the legacy row is in effect
 *   Overridden       an active override replaces a legacy row
 *   Added by Agora   an active override where the branch has NO legacy row.
 *                    Twenty-four of twenty-six branches are in this position
 *                    for at least one area (finding 1), and it is the larger
 *                    half of what this screen is for.
 *   Parked           an override exists but is switched off; the legacy row —
 *                    or nothing at all — is back in effect
 *
 * ONE ROW PER RULE, KEYED ON AutoReconId. Not on (BranchId, BankReconArea,
 * ProcessOrder) — that triple does NOT identify a rule, and the customer's own
 * data says so: branch 23 has two FNB rules, both ProcessOrder 1, ids 283 and
 * 293, identical in every column. Keying on the triple deduplicated 133 rules
 * to 132 and then met each side of the join twice, so this screen reported 135
 * rows for an estate of 133. Caught by reading the count against the view's,
 * which is the whole reason that check is a habit here.
 *
 * @WithNarrativeCheck IS OFF BY DEFAULT AND THAT IS DELIBERATE.
 * `RCN_BankStatementLinesPumpIT` is a table inside a 249 GB database people
 * are trading on. Asking it how long its narratives actually are is the single
 * most useful thing this screen can say — it is how finding 11 (branch 7
 * extracting from position 43 of a 32-character narrative) would have been
 * visible at a glance instead of taking an investigation — but it is a read
 * across a live estate, so the screen asks for it rather than doing it on
 * every page load. One grouped query over a bounded window, not one per row.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_GridCriteria]
    @BranchIds          NVARCHAR(MAX) = NULL,
    @DateFrom           DATE          = NULL,
    @DateTo             DATE          = NULL,
    @Search             NVARCHAR(200) = NULL,
    @SortColumn         NVARCHAR(80)  = NULL,
    @SortAsc            BIT           = 1,
    @Page               INT           = 1,
    @PageSize           INT           = 50,
    @FiltersJson        NVARCHAR(MAX) = NULL,
    @ReconArea          NVARCHAR(20)  = NULL,
    @AllowedBranchIds   NVARCHAR(MAX) = NULL,
    @WithNarrativeCheck BIT           = 0,
    @NarrativeDays      INT           = 90
AS
BEGIN
    SET NOCOUNT ON;

    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');
    SET @NarrativeDays = CASE WHEN ISNULL(@NarrativeDays, 90) BETWEEN 1 AND 400 THEN @NarrativeDays ELSE 90 END;

    DECLARE @Offset INT = (@Page - 1) * @PageSize;

    /* The empty-string trap: STRING_SPLIT('', ',') returns one row holding an
       empty string, and TRY_CONVERT(int, '') is 0, not NULL. */
    DECLARE @Branch  TABLE (BranchId int PRIMARY KEY);
    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch)  THEN 0 ELSE 1 END;
    DECLARE @AllAllowed  bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    /* Header filters, in the shape GridQuery actually sends: a JSON ARRAY of
       {column, type, op, value} — the column name is at $.column, NOT the
       OPENJSON key, which over an array is the index. See docs/grid.md. */
    DECLARE @Filter TABLE ([Column] nvarchar(80), [Op] nvarchar(20), [Value] nvarchar(400));
    DECLARE @FilterSet TABLE ([Column] nvarchar(80), [Value] nvarchar(400));

    IF @FiltersJson IS NOT NULL
    BEGIN
        INSERT INTO @Filter ([Column], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'),
               ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
               JSON_VALUE(f.value, '$.value')
        FROM OPENJSON(@FiltersJson) f
        WHERE JSON_VALUE(f.value, '$.value') IS NOT NULL;

        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), v.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') v
        WHERE JSON_VALUE(f.value, '$.type') = 'set';
    END

    DECLARE @fBranch  NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'BranchName'),
            @fFilter  NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'FILTER_Value'),
            @fReason  NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'Reason'),
            @fChanged NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'ChangedBy');

    DECLARE @fAreaAny   bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'BankReconArea') THEN 1 ELSE 0 END;
    DECLARE @fSourceAny bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'Source') THEN 1 ELSE 0 END;

    /* How long the narratives at each site actually are, when asked for.
       ONE grouped query over a bounded window, joined to every row — not one
       lookup per row, which over this table would be unforgivable. */
    DECLARE @Narrative TABLE (BranchId int, BankType nvarchar(50), MaxLen int, Lines int,
                              PRIMARY KEY (BranchId, BankType));

    IF @WithNarrativeCheck = 1
        INSERT INTO @Narrative (BranchId, BankType, MaxLen, Lines)
        SELECT l.BranchId, l.Type, MAX(LEN(RTRIM(l.Description))), COUNT_BIG(*)
        FROM agora.vw_BankStatementLine l
        WHERE l.LineDate >= DATEADD(day, -@NarrativeDays, CONVERT(date, GETDATE()))
          AND (@AllBranches = 1 OR l.BranchId IN (SELECT BranchId FROM @Branch))
          AND (@AllAllowed  = 1 OR l.BranchId IN (SELECT BranchId FROM @Allowed))
        GROUP BY l.BranchId, l.Type;

    ;WITH effective AS (
        /* What the previews will actually resolve — the same view they read,
           so this screen cannot disagree with them about what is in force. */
        SELECT v.AutoReconId, v.BranchId, v.BankReconArea, v.ProcessOrder,
               v.BANK_StartPosition, v.BANK_EndPosition,
               v.BANK_StartPosition2, v.BANK_EndPosition2,
               v.MOPS_StartPosition, v.MOPS_EndPosition,
               v.FILTER_Value, v.FILTER_StartPosition, v.FILTER_EndPosition
        FROM agora.vw_AutoReconCriteria v
    ),
    rows AS (
        /*
         * One row per rule in force, plus one for every override that is
         * switched OFF — a parked change is something the reader has to be
         * able to see, and by definition it is not in the view.
         *
         * AutoReconId is the identity on both sides: positive for one of the
         * customer's rules, negative for one of Agora's. The joins below are
         * therefore one-to-one, which is what the earlier keying on
         * (site, area, order) was not.
         */
        SELECT
            e.BranchId,
            b.Name AS BranchName,
            e.BankReconArea,
            e.ProcessOrder,
            o.Id                       AS OverrideId,
            e.AutoReconId,

            CASE WHEN o.Id IS NOT NULL AND lg.AutoReconId IS NOT NULL THEN 'Overridden'
                 WHEN o.Id IS NOT NULL                                THEN 'Added by Agora'
                 ELSE 'Customer' END   AS Source,

            e.BANK_StartPosition, e.BANK_EndPosition,
            e.BANK_StartPosition2, e.BANK_EndPosition2,
            e.MOPS_StartPosition, e.MOPS_EndPosition,
            e.FILTER_Value, e.FILTER_StartPosition, e.FILTER_EndPosition,

            /* The length the previews resolve to. BANK_EndPosition is a LENGTH
               in some areas and an END POSITION in others and the column does
               not say which (finding 9); this is the same test every preview
               makes, so the number here is the number they will use. */
            CASE WHEN e.BANK_EndPosition >= e.BANK_StartPosition
                 THEN e.BANK_EndPosition - e.BANK_StartPosition + 1
                 ELSE e.BANK_EndPosition END AS ResolvedBankLen,

            lg.AutoReconId             AS LegacyAutoReconId,
            lg.BANK_StartPosition      AS LegacyBankStart,
            lg.BANK_EndPosition        AS LegacyBankEnd,
            lg.MOPS_StartPosition      AS LegacyMopsStart,
            lg.MOPS_EndPosition        AS LegacyMopsEnd,
            lg.FILTER_Value            AS LegacyFilterValue,

            o.Reason, o.IsActive, o.CopiedFromBranchId,
            o.UpdatedAt                AS ChangedAt,
            u.UserName                 AS ChangedBy,
            n.MaxLen                   AS NarrativeMaxLen,
            n.Lines                    AS NarrativeLines
        FROM effective e
        /* Ours, when the effective row IS ours: the view gives an override the
           negative of its own id, so this is exact. */
        LEFT JOIN agora.ReconCriteria o ON o.Id = -e.AutoReconId AND e.AutoReconId < 0
        /* The rule this override replaces, by the id it names. */
        LEFT JOIN agora.vw_LegacyReconCriteria lg ON lg.AutoReconId = o.LegacyAutoReconId
        LEFT JOIN agora.Branch b ON b.BranchId = e.BranchId AND b.DeletedAt IS NULL
        LEFT JOIN agora.[User] u ON u.Id = ISNULL(o.UpdatedBy, o.CreatedBy)
        LEFT JOIN @Narrative n
               ON n.BranchId = e.BranchId
              AND n.BankType = CASE WHEN e.BankReconArea = 'CashBags' THEN 'CashDeposit' ELSE e.BankReconArea END
        WHERE (@ReconArea IS NULL OR e.BankReconArea = @ReconArea)
          AND (@AllBranches = 1 OR e.BranchId IN (SELECT BranchId FROM @Branch))
          AND (@AllAllowed  = 1 OR e.BranchId IN (SELECT BranchId FROM @Allowed))

        UNION ALL

        /* Parked overrides. Not in the view — that is what parked means — so
           what is shown beside them is the rule that is back in force. */
        SELECT
            o.BranchId,
            b.Name,
            o.BankReconArea,
            o.ProcessOrder,
            o.Id,
            CONVERT(int, -o.Id),
            'Parked',
            ISNULL(lg.BANK_StartPosition, o.BANK_StartPosition),
            ISNULL(lg.BANK_EndPosition, o.BANK_EndPosition),
            lg.BANK_StartPosition2, lg.BANK_EndPosition2,
            lg.MOPS_StartPosition, lg.MOPS_EndPosition,
            lg.FILTER_Value, lg.FILTER_StartPosition, lg.FILTER_EndPosition,
            CASE WHEN lg.BANK_EndPosition >= lg.BANK_StartPosition
                 THEN lg.BANK_EndPosition - lg.BANK_StartPosition + 1
                 ELSE lg.BANK_EndPosition END,
            lg.AutoReconId,
            lg.BANK_StartPosition, lg.BANK_EndPosition,
            lg.MOPS_StartPosition, lg.MOPS_EndPosition, lg.FILTER_Value,
            o.Reason, o.IsActive, o.CopiedFromBranchId,
            o.UpdatedAt,
            u.UserName,
            n.MaxLen, n.Lines
        FROM agora.ReconCriteria o
        LEFT JOIN agora.vw_LegacyReconCriteria lg ON lg.AutoReconId = o.LegacyAutoReconId
        LEFT JOIN agora.Branch b ON b.BranchId = o.BranchId AND b.DeletedAt IS NULL
        LEFT JOIN agora.[User] u ON u.Id = ISNULL(o.UpdatedBy, o.CreatedBy)
        LEFT JOIN @Narrative n
               ON n.BranchId = o.BranchId
              AND n.BankType = CASE WHEN o.BankReconArea = 'CashBags' THEN 'CashDeposit' ELSE o.BankReconArea END
        WHERE o.IsActive = 0
          AND (@ReconArea IS NULL OR o.BankReconArea = @ReconArea)
          AND (@AllBranches = 1 OR o.BranchId IN (SELECT BranchId FROM @Branch))
          AND (@AllAllowed  = 1 OR o.BranchId IN (SELECT BranchId FROM @Allowed))
    )

    SELECT *
    INTO #Rows
    FROM (
        SELECT r.*,
               /* The finding-11 flag, and it is only honest when the narrative
                  check ran — otherwise it says nothing rather than guessing. */
               CASE WHEN r.NarrativeMaxLen IS NULL THEN NULL
                    WHEN r.BANK_StartPosition IS NULL THEN 'No extraction configured'
                    WHEN r.BANK_StartPosition > r.NarrativeMaxLen
                         THEN 'Starts past the end of every narrative — extracts nothing'
                    WHEN r.ResolvedBankLen IS NULL OR r.ResolvedBankLen <= 0
                         THEN 'Resolved length is not positive'
                    WHEN r.BANK_StartPosition + r.ResolvedBankLen - 1 > r.NarrativeMaxLen
                         THEN 'Runs past the end of the longest narrative'
                    ELSE 'Fits' END AS Health
        FROM rows r
    ) x
    WHERE (@Search IS NULL
           OR x.BranchName   LIKE '%' + @Search + '%'
           OR x.FILTER_Value LIKE '%' + @Search + '%'
           OR x.Reason       LIKE '%' + @Search + '%')
      AND (@fBranch  IS NULL OR x.BranchName   LIKE '%' + @fBranch + '%')
      AND (@fFilter  IS NULL OR x.FILTER_Value LIKE '%' + @fFilter + '%')
      AND (@fReason  IS NULL OR x.Reason       LIKE '%' + @fReason + '%')
      AND (@fChanged IS NULL OR x.ChangedBy    LIKE '%' + @fChanged + '%')
      AND (@fAreaAny   = 0 OR x.BankReconArea IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'BankReconArea'))
      AND (@fSourceAny = 0 OR x.Source        IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Source'));

    SELECT *
    FROM #Rows
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'BranchName'    THEN BranchName
                WHEN 'BankReconArea' THEN BankReconArea
                WHEN 'Source'        THEN Source
                WHEN 'FILTER_Value'  THEN FILTER_Value
                WHEN 'Health'        THEN Health
                WHEN 'ChangedBy'     THEN ChangedBy
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'BranchName'    THEN BranchName
                WHEN 'BankReconArea' THEN BankReconArea
                WHEN 'Source'        THEN Source
                WHEN 'FILTER_Value'  THEN FILTER_Value
                WHEN 'Health'        THEN Health
                WHEN 'ChangedBy'     THEN ChangedBy
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'ProcessOrder'       THEN ProcessOrder END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'ProcessOrder'       THEN ProcessOrder END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'BANK_StartPosition' THEN BANK_StartPosition END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'BANK_StartPosition' THEN BANK_StartPosition END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'ChangedAt'          THEN ChangedAt END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'ChangedAt'          THEN ChangedAt END DESC,
        /* Default and tie-break: the estate read the way a person walks it. */
        BranchName, BankReconArea, ProcessOrder
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Rows;

    DROP TABLE #Rows;
END
