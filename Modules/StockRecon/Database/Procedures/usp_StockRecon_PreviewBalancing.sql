/*
 * agora.usp_StockRecon_PreviewBalancing — shift variance balancing, computed.
 *
 * Read by:  Stock recon centre -> Balance (the preview press), and nothing else.
 * Reads:    agora.vw_StockReconLine, agora.vw_StockArea, agora.vw_StockMaster,
 *           agora.vw_StockReconShiftEmployee  (who was on the shift — the ONLY
 *           definition of that in Agora; readers use it too, see v1__14b)
 * Writes:   agora.StockReconRunLine, agora.StockReconRun — Agora's own ledger.
 *           NOTHING in the customer's estate. The commit is a separate
 *           procedure and even that only writes to PumpIT in 'live' stamp mode.
 *
 * WHAT IT REPLACES. dbo.sp_UpdateAUTOStockReconBalancing looks for a variance
 * and the one after it and zeroes the pair. Three things stop it working:
 * it acts only where QtyVar + Diff = 0 EXACTLY, so a chain of five nearly
 * matched pairs is untouched; its LEAD has no PARTITION BY, so the "next" row
 * can belong to a different stock item; and its LEAD is ordered by
 * (StockItemDescription, StockItemNo, ...) while the ROW_NUMBER beside it is
 * ordered by (StockItemDescription, POSCode, ...), so wherever two items share
 * a description the pairing silently shifts. All three fall away here, because
 * the work is done on a running total per item rather than on adjacent pairs.
 *
 * THE METHOD. Variance(i) = POS(i) - (Open(i) + Issued(i) - Close(i)), and
 * Open(i) = Close(i-1). The only lever is the closing count, and because every
 * closing is also the next opening, raising one by d lifts this shift's
 * variance by d and drops the next one's by d. Over the window everything
 * cancels except the two ends, so the total T is FIXED by the choice of dates.
 * Balancing redistributes; it cannot reduce.
 *
 *   P(k) = running total of Variance over ACTIVE shifts
 *   M(k) = MAX(P(j) : j >= k)                  -- the reverse running maximum
 *   Q(k) = CLAMP(M(k), between 0 and T)
 *   Close(k) := Close(k) + (Q(k) - P(k))
 *
 * Q never rises, which is exactly "no shift ends over" (rule 1); it never sits
 * below P, which is exactly "no shift is made shorter than its own count"
 * (rule 2); and it is the smallest such curve, so it overrides the fewest
 * counts. The last active shift is pinned at zero amendment — that is the
 * anchor on the latest count.
 *
 * RULE 3, THE DORMANT SHIFT, is structural rather than a special case. A shift
 * with Open = Close, nothing issued and nothing sold did not trade; it is a
 * sign-off with no movement behind it, and charging it is charging somebody
 * who was never at the till. ActiveSeq numbers only the active shifts, so a
 * dormant row carries the number of the active shift BEFORE it and inherits
 * that shift's amendment from the same join — its opening and its closing move
 * together and its variance stays zero however much the chain around it is
 * re-cut. A run of consecutive dormant rows shares the number and moves as a
 * block. FlagDormantMoved then re-checks the result and blocks the chain if it
 * ever fails, which is the guard rather than the mechanism.
 *
 * FlagChainBroken IS OURS AND IS NOT IN THE METHOD NOTE. The whole method
 * rests on Open(i) = Close(i-1), and on branch 18's August 880 of 14,483 lines
 * break it in the SOURCE data. Redistributing along a path that does not exist
 * is meaningless, and it is also what made the dormant guard fire 144 times on
 * the first real run. Blocking those chains takes the guard to zero and the
 * invariant then holds to the fourth decimal.
 *
 * WHY IT WRITES. Every other preview in Agora returns rows and lets PHP record
 * them. A branch-month here is fifteen thousand shift lines, which as a chunked
 * multi-row INSERT is three hundred round trips — so the run header is created
 * first and its id passed in, and one INSERT ... SELECT lands the lot. It also
 * means the header counts and the lines come out of the SAME pass and cannot
 * disagree, which is a failure the bank-recon ledger had to be fixed for.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_PreviewBalancing]
    @RunId                INT,
    @BranchId             INT,
    @FromDate             DATE,
    @ToDate               DATE,
    @AreaNo               INT             = NULL,   -- NULL = every area at the site
    @MaxAmendment         DECIMAL(18,4)   = 9999,
    @MaxAmendmentPct      DECIMAL(9,4)    = 1.0,
    @MinShiftsInChain     INT             = 2,
    @UseOriginalCounts    BIT             = 1,
    @ExcludedAreaGroups   NVARCHAR(400)   = NULL,   -- CSV of STK_Area.AreaGroup
    @UserId               INT             = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    /* Tolerance. Counts are floats on the source and a butchery item is
       weighed, so nothing is ever compared with = against zero. */
    DECLARE @eps DECIMAL(18,4) = 0.005;

    IF @RunId IS NULL OR NOT EXISTS (SELECT 1 FROM agora.StockReconRun WHERE BranchId = @BranchId AND Id = @RunId)
        THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;

    IF @FromDate > @ToDate
        THROW 51000, 'AGORA:BAD_WINDOW:The from date is after the to date.', 1;

    IF @AreaNo IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM agora.vw_StockArea WHERE BranchId = @BranchId AND AreaNo = @AreaNo)
        THROW 51000, 'AGORA:NO_SUCH_AREA:That counting area is not configured at this site.', 1;

    /* The area groups this site does not count as stock. Lotto, airtime and
       electricity are virtual products with no issues and no physical count,
       so they read as a permanent enormous net over and cannot be reconciled
       as stock at all. By the customer's own AreaGroup column, never by a
       description pattern Agora invented. */
    DECLARE @Excluded TABLE (AreaGroup NVARCHAR(100) PRIMARY KEY);

    INSERT INTO @Excluded (AreaGroup)
    SELECT DISTINCT LTRIM(RTRIM(s.value))
    FROM STRING_SPLIT(ISNULL(@ExcludedAreaGroups, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '';

    /* ------------------------------------------------------------- stage 1
       The window, with the four quantities resolved once.

       CROSS APPLY so the dormant test and the variance read the same values.
       @UseOriginalCounts reads the _Original columns, which is what makes a
       re-run idempotent: the legacy procedure balances on top of its own
       earlier amendments and nothing anywhere says it has. */
    IF OBJECT_ID('tempdb..#chain') IS NOT NULL DROP TABLE #chain;

    SELECT
        l.AreaNo,
        l.StockItemNo,
        CONVERT(date, l.TransactionDate)                                  AS TransactionDate,
        l.ShiftNo,
        CONVERT(decimal(18,2), ISNULL(l.SellPrice, 0))                    AS SellPrice,
        x.QtyOpen, x.QtyIssued, x.QtyClose, x.QtyPOS,
        CONVERT(bit, CASE WHEN ABS(x.QtyOpen - x.QtyClose) < @eps
                           AND ABS(x.QtyIssued)            < @eps
                           AND ABS(x.QtyPOS)               < @eps
                          THEN 1 ELSE 0 END)                              AS IsDormant
    INTO #chain
    FROM agora.vw_StockReconLine l
    JOIN agora.vw_StockArea a
      ON a.BranchId = l.BranchId AND a.AreaNo = l.AreaNo
    CROSS APPLY (SELECT
        CONVERT(decimal(18,4), ISNULL(CASE WHEN @UseOriginalCounts = 1
                 THEN l.QtyOpen_Original  ELSE l.QtyOpen  END, 0))        AS QtyOpen,
        CONVERT(decimal(18,4), ISNULL(l.QtyIssued, 0))                    AS QtyIssued,
        CONVERT(decimal(18,4), ISNULL(CASE WHEN @UseOriginalCounts = 1
                 THEN l.QtyClose_Original ELSE l.QtyClose END, 0))        AS QtyClose,
        CONVERT(decimal(18,4), ISNULL(l.QtyComputer, 0))                  AS QtyPOS) x
    WHERE l.BranchId = @BranchId
      AND l.TransactionDate >= @FromDate
      AND l.TransactionDate <  DATEADD(day, 1, @ToDate)
      AND (@AreaNo IS NULL OR l.AreaNo = @AreaNo)
      AND NOT EXISTS (SELECT 1 FROM @Excluded e WHERE e.AreaGroup = a.AreaGroup);

    /* ------------------------------------------------------------- stage 2
       Number the active shifts, and notice a chain that is already broken.

       ActiveSeq is the running count of active shifts: its own position on an
       active row, the position of the active shift BEFORE it on a dormant one.
       That single expression is what carries rule 3 through one join later. */
    IF OBJECT_ID('tempdb..#gate') IS NOT NULL DROP TABLE #gate;

    ;WITH seq AS
    (
        SELECT
            c.*,
            CONVERT(decimal(18,4), c.QtyPOS - (c.QtyOpen + c.QtyIssued - c.QtyClose)) AS QtyVar,
            SUM(CASE WHEN c.IsDormant = 0 THEN 1 ELSE 0 END) OVER (
                PARTITION BY c.AreaNo, c.StockItemNo
                ORDER BY c.TransactionDate, c.ShiftNo
                ROWS UNBOUNDED PRECEDING)                                  AS ActiveSeq,
            SUM(CASE WHEN c.IsDormant = 0 THEN 1 ELSE 0 END) OVER (
                PARTITION BY c.AreaNo, c.StockItemNo)                      AS ActiveLen,
            COUNT(*) OVER (PARTITION BY c.AreaNo, c.StockItemNo)           AS ChainShifts,
            LAG(c.QtyClose) OVER (
                PARTITION BY c.AreaNo, c.StockItemNo
                ORDER BY c.TransactionDate, c.ShiftNo)                     AS PrevClose,
            SUM(c.QtyIssued) OVER (PARTITION BY c.AreaNo, c.StockItemNo)   AS ChainIssues,
            SUM(c.QtyPOS)    OVER (PARTITION BY c.AreaNo, c.StockItemNo)   AS ChainPOS,
            SUM(CASE WHEN ABS(c.QtyClose - c.QtyOpen) < @eps
                      AND c.QtyIssued < @eps
                      AND c.QtyPOS    > @eps THEN 1 ELSE 0 END) OVER (
                PARTITION BY c.AreaNo, c.StockItemNo)                      AS NoCountShifts
        FROM #chain c
    )
    SELECT
        seq.*,
        CONVERT(bit, CASE WHEN seq.PrevClose IS NOT NULL
                           AND ABS(seq.QtyOpen - seq.PrevClose) > @eps
                          THEN 1 ELSE 0 END)                               AS LinkBroken
    INTO #gate
    FROM seq;

    /* ------------------------------------------------------------- stage 3
       The algorithm, on active shifts only.

       act builds P and T; env takes the reverse running maximum M; sol clamps
       it into the band between 0 and T and subtracts P. The nested CASE is a
       clamp written without GREATEST/LEAST: the band is 0..T when T is
       negative and T..0 when T is positive. */
    IF OBJECT_ID('tempdb..#apply') IS NOT NULL DROP TABLE #apply;

    ;WITH act AS
    (
        SELECT g.AreaNo, g.StockItemNo, g.ActiveSeq, g.ActiveLen, g.QtyVar,
               SUM(g.QtyVar) OVER (
                   PARTITION BY g.AreaNo, g.StockItemNo
                   ORDER BY g.ActiveSeq
                   ROWS UNBOUNDED PRECEDING)                               AS P,
               SUM(g.QtyVar) OVER (PARTITION BY g.AreaNo, g.StockItemNo)   AS T
        FROM #gate g
        WHERE g.IsDormant = 0
    ),
    env AS
    (
        SELECT act.*,
               MAX(act.P) OVER (
                   PARTITION BY act.AreaNo, act.StockItemNo
                   ORDER BY act.ActiveSeq
                   ROWS BETWEEN CURRENT ROW AND UNBOUNDED FOLLOWING)       AS M
        FROM act
    ),
    sol AS
    (
        SELECT env.AreaNo, env.StockItemNo, env.ActiveSeq, env.T,
               CASE WHEN env.ActiveSeq = env.ActiveLen
                    THEN CONVERT(decimal(18,4), 0)     /* the anchor on the latest count */
                    ELSE CONVERT(decimal(18,4),
                           CASE
                               WHEN env.M > CASE WHEN env.T > 0 THEN env.T ELSE 0 END
                                   THEN CASE WHEN env.T > 0 THEN env.T ELSE 0 END
                               WHEN env.M < CASE WHEN env.T < 0 THEN env.T ELSE 0 END
                                   THEN CASE WHEN env.T < 0 THEN env.T ELSE 0 END
                               ELSE env.M
                           END - env.P)
               END                                                         AS AmendClose
        FROM env
    ),
    /* ----------------------------------------------------------- stage 4
       Give every row its amendment. Joined on ActiveSeq, so an active row
       collects its own and a dormant row collects the one belonging to the
       active shift before it. A LEADING dormant row has ActiveSeq = 0, matches
       nothing, and falls to zero through the ISNULL — which is rule 3 satisfied
       structurally rather than by a special case. */
    joined AS
    (
        SELECT g.*,
               ISNULL(s.AmendClose, CONVERT(decimal(18,4), 0))             AS AmendClose,
               ISNULL(s.T,          CONVERT(decimal(18,4), 0))             AS ChainNetVar
        FROM #gate g
        LEFT JOIN sol s
               ON s.AreaNo      = g.AreaNo
              AND s.StockItemNo = g.StockItemNo
              AND s.ActiveSeq   = g.ActiveSeq
    ),
    /* ----------------------------------------------------------- stage 5
       Rebuild the chain. New closing is the counted closing plus the
       amendment; new opening is the previous row's new closing, which is the
       chain constraint restated. The first row of each item keeps its counted
       opening — the anchor at the other end. */
    chained AS
    (
        SELECT j.*,
               CONVERT(decimal(18,4), j.QtyClose + j.AmendClose)           AS QtyCloseNew,
               CONVERT(decimal(18,4),
                   ISNULL(LAG(j.QtyClose + j.AmendClose) OVER (
                       PARTITION BY j.AreaNo, j.StockItemNo
                       ORDER BY j.TransactionDate, j.ShiftNo), j.QtyOpen)) AS QtyOpenNew
        FROM joined j
    ),
    /* ----------------------------------------------------------- stage 6
       Score it, and raise every flag that should stop a chain being written. */
    scored AS
    (
        SELECT ch.*,
               CONVERT(decimal(18,4),
                   ch.QtyPOS - (ch.QtyOpenNew + ch.QtyIssued - ch.QtyCloseNew)) AS QtyVarNew,

               /* A1 — the till sold more than the shift could possibly hold. */
               CONVERT(bit, CASE WHEN ch.QtyPOS - (ch.QtyOpen + ch.QtyIssued) > @eps THEN 1 ELSE 0 END)
                                                                           AS FlagSoldMoreThanOnHand,
               /* A2 — it closed holding more than it ever received. */
               CONVERT(bit, CASE WHEN ch.QtyClose - (ch.QtyOpen + ch.QtyIssued) > @eps THEN 1 ELSE 0 END)
                                                                           AS FlagCloseExceedsOnHand,
               /* A4 — net over for the window. No amendment can remove it. */
               CONVERT(bit, CASE WHEN ch.ChainNetVar > @eps THEN 1 ELSE 0 END)
                                                                           AS FlagNetOver,
               /* B2 — issued, nothing sold, count unchanged. It LOOKS dormant
                  and is not, so it must never be folded away. */
               CONVERT(bit, CASE WHEN ch.IsDormant = 0
                                  AND ABS(ch.QtyOpen - ch.QtyClose) < @eps
                                  AND ch.QtyIssued > @eps
                                  AND ABS(ch.QtyPOS) < @eps
                                 THEN 1 ELSE 0 END)                        AS FlagIssueWentNowhere,
               /* B1 — the plausibility caps, both halves. */
               CONVERT(bit, CASE WHEN ABS(ch.AmendClose) > @MaxAmendment THEN 1 ELSE 0 END)
                                                                           AS FlagBigAmendment,
               CONVERT(bit, CASE WHEN ch.QtyOpen + ch.QtyIssued > 0
                                  AND ABS(ch.AmendClose) > @MaxAmendmentPct * (ch.QtyOpen + ch.QtyIssued)
                                 THEN 1 ELSE 0 END)                        AS FlagPctAmendment,
               CONVERT(bit, CASE WHEN ch.QtyClose + ch.AmendClose < -@eps THEN 1 ELSE 0 END)
                                                                           AS FlagNegativeClose,
               CONVERT(bit, CASE WHEN ch.ActiveLen < @MinShiftsInChain THEN 1 ELSE 0 END)
                                                                           AS FlagShortChain,
               /* Rule 3, checked rather than assumed. */
               CONVERT(bit, CASE WHEN ch.IsDormant = 1
                                  AND ABS(ch.QtyCloseNew - ch.QtyOpenNew) > @eps
                                 THEN 1 ELSE 0 END)                        AS FlagDormantMoved
        FROM chained ch
    ),
    /* ----------------------------------------------------------- stage 7
       One bad shift blocks its whole chain, so a chain is never balanced half
       way around a fault. ChainBlocked is the per-chain maximum of every flag,
       LinkBroken included. */
    blocked AS
    (
        SELECT s.*,
               CONVERT(bit, MAX(CONVERT(int, s.FlagNetOver) + CONVERT(int, s.FlagSoldMoreThanOnHand)
                              + CONVERT(int, s.FlagCloseExceedsOnHand) + CONVERT(int, s.FlagIssueWentNowhere)
                              + CONVERT(int, s.FlagBigAmendment) + CONVERT(int, s.FlagPctAmendment)
                              + CONVERT(int, s.FlagNegativeClose) + CONVERT(int, s.FlagShortChain)
                              + CONVERT(int, s.FlagDormantMoved) + CONVERT(int, s.LinkBroken))
                   OVER (PARTITION BY s.AreaNo, s.StockItemNo))            AS ChainBlocked
        FROM scored s
    ),
    /* ----------------------------------------------------------- stage 7b
       A BLOCKED CHAIN CARRIES NO PROPOSAL AT ALL.

       The flags above are computed FROM the proposal — the caps, the negative
       closing and the dormant guard all read it — so it has to be built first
       and withdrawn afterwards. What the screen and the header then show for a
       blocked chain is the counted figures, unchanged, which is what a commit
       would leave behind.

       Withdrawing it matters more than it looks. Left in, the run header said
       "10,487 units still over after balancing" about amendments that were
       never going to be applied, and every "after" column on the grid was a
       number describing a world the press could not produce. Measured on
       branch 18's August, 8 September 2026: 221 of 225 chains blocked. */
    effective AS
    (
        SELECT b.*,
               CASE WHEN b.ChainBlocked = 1 THEN CONVERT(decimal(18,4), 0) ELSE b.AmendClose  END AS EffAmend,
               CASE WHEN b.ChainBlocked = 1 THEN b.QtyOpen  ELSE b.QtyOpenNew  END               AS EffOpenNew,
               CASE WHEN b.ChainBlocked = 1 THEN b.QtyClose ELSE b.QtyCloseNew END               AS EffCloseNew,
               CASE WHEN b.ChainBlocked = 1 THEN b.QtyVar   ELSE b.QtyVarNew   END               AS EffVarNew
        FROM blocked b
    ),
    /* D1 counts shorts AFTER the run — which on a blocked chain is after
       nothing, so it reads the effective variance like everything else. */
    ranked AS
    (
        SELECT e.*,
               SUM(CASE WHEN e.IsDormant = 0 AND e.EffVarNew < -@eps THEN 1 ELSE 0 END)
                   OVER (PARTITION BY e.AreaNo, e.StockItemNo)             AS ShortShiftsAfter,
               /*
                * DOES THIS ROW MOVE AT ALL — and it is TWO columns, not one.
                *
                * A row's closing can stay exactly where it was while its
                * OPENING moves, because the opening is the previous shift's
                * closing and that one was amended. The last active shift is
                * the clearest case: it is pinned to zero amendment by
                * construction, and its opening still has to follow the chain.
                *
                * Deciding this on the amendment alone leaves those rows out of
                * the commit, and the chain in the source then has a hole in it
                * exactly where the anchor is — the opening no longer equals the
                * previous closing, which is the very condition FlagChainBroken
                * blocks a chain for. Caught on branch 18's Hot Foods, line 28
                * of run 2, 8 September 2026.
                */
               CONVERT(bit, CASE WHEN ABS(e.EffAmend) > @eps
                                   OR ABS(e.EffOpenNew - e.QtyOpen) > @eps
                                  THEN 1 ELSE 0 END)                       AS RowMoves
        FROM effective e
    )
    SELECT * INTO #apply FROM ranked;

    /* ------------------------------------------------------------- stage 7c
       The labels, resolved ONCE.

       Stored on the line rather than joined at read time for the same reason
       SellPrice already is: a run is a record of what was true when it was
       made, and an item gets renamed. It is also what lets the proposals table
       — the one screen here that is not powered by a procedure, because its
       tick boxes decide what a commit writes — show an item name at all.

       THE EMPLOYEE IS A LIST. dbo.STK_StockReconEmployees is keyed on exactly
       the grain a recon line is, and 90 of branch 18's 1,138 shifts have more
       than one person signed on to the area. A short on a shift two people
       worked cannot be attributed to either, so the count travels with the
       names and the screen says so. STRING_AGG orders by name so the same two
       people always read the same way round. */
    IF OBJECT_ID('tempdb..#emp') IS NOT NULL DROP TABLE #emp;

    /*
     * ONE DEFINITION, read here and by every reader.
     *
     * The aggregate used to be written out inline here, and the readers were
     * left to fall back on their own — which is exactly how the proposals
     * screen came to show a shift with nobody on it while the exceptions grid
     * showed a name. agora.vw_StockReconShiftEmployee is now the only place
     * that says who was on a shift; see v1__14b for what it costs to have two.
     *
     * The predicate is on the view's GROUPING columns, so it is pushed into
     * the aggregate rather than applied after it.
     */
    SELECT se.TransactionDate,
           se.ShiftNo,
           se.AreaNo,
           se.EmployeeCount,
           se.EmployeeCodes,
           se.EmployeeNames
    INTO #emp
    FROM agora.vw_StockReconShiftEmployee se
    WHERE se.BranchId = @BranchId
      AND se.TransactionDate >= @FromDate
      AND se.TransactionDate <  DATEADD(day, 1, @ToDate);

    /* ------------------------------------------------------------- stage 8
       Record it. One INSERT, ordered so the line numbers read as a chain.

       WouldAmend is a stored bit and is 1 only where the chain is clear AND
       the amendment is non-zero — there is no path from "blocked" to
       "written". Selected follows it, because the ask is the least interaction
       that is still safe and the safety is the confirmation before the press,
       not an empty grid somebody has to fill in. */
    INSERT INTO agora.StockReconRunLine (
        BranchId, RunId, [LineNo], AreaNo, StockItemNo, TransactionDate, ShiftNo, SellPrice,
        QtyOpen, QtyIssued, QtyClose, QtyPOS, QtyVar,
        QtyOpenNew, QtyCloseNew, QtyVarNew, AmendClose,
        IsDormant, ActiveSeq, ActiveLen, ChainNetVar,
        FlagNetOver, FlagSoldMoreThanOnHand, FlagCloseExceedsOnHand, FlagIssueWentNowhere,
        FlagBigAmendment, FlagPctAmendment, FlagNegativeClose, FlagShortChain,
        FlagDormantMoved, FlagChainBroken, ChainBlocked,
        ExceptionCode, Outcome, WouldAmend, Selected, CommitState,
        ItemDescription, POSCode, StockLocation, AreaDescription,
        EmployeeCodes, EmployeeNames, EmployeeCount,
        CreatedAt, CreatedBy)
    SELECT
        @BranchId,
        @RunId,
        ROW_NUMBER() OVER (ORDER BY a.AreaNo, a.StockItemNo, a.TransactionDate, a.ShiftNo),
        a.AreaNo, a.StockItemNo, a.TransactionDate, a.ShiftNo, a.SellPrice,
        a.QtyOpen, a.QtyIssued, a.QtyClose, a.QtyPOS, a.QtyVar,
        a.EffOpenNew, a.EffCloseNew, a.EffVarNew, a.EffAmend,
        a.IsDormant, a.ActiveSeq, a.ActiveLen, a.ChainNetVar,
        a.FlagNetOver, a.FlagSoldMoreThanOnHand, a.FlagCloseExceedsOnHand, a.FlagIssueWentNowhere,
        a.FlagBigAmendment, a.FlagPctAmendment, a.FlagNegativeClose, a.FlagShortChain,
        a.FlagDormantMoved, a.LinkBroken, a.ChainBlocked,

        /* The exception class, ranked most serious first. A line can satisfy
           several tests; the class it is REPORTED under is the first one it
           trips, so an unrecorded issue is never filed as a counting habit. */
        CASE
            WHEN a.FlagSoldMoreThanOnHand = 1                                            THEN 'A1'
            WHEN a.FlagCloseExceedsOnHand = 1                                            THEN 'A2'
            WHEN a.ChainNetVar > @eps AND a.ChainIssues < @eps AND a.ChainPOS > @eps      THEN 'A3'
            WHEN a.ChainNetVar > @eps                                                    THEN 'A4'
            WHEN a.QtyOpen < -@eps OR a.QtyClose < -@eps
                 OR a.FlagNegativeClose = 1 OR a.FlagBigAmendment = 1
                 OR a.FlagPctAmendment = 1  OR a.LinkBroken = 1                          THEN 'B1'
            WHEN a.FlagIssueWentNowhere = 1                                              THEN 'B2'
            WHEN a.NoCountShifts * 2 > a.ActiveLen AND a.ActiveLen > 0                    THEN 'C1'
            WHEN a.ActiveLen * 2 < a.ChainShifts AND a.ChainShifts >= 6                   THEN 'C2'
            WHEN a.ShortShiftsAfter * 2 > a.ActiveLen AND a.ActiveLen >= 6                THEN 'D1'
            ELSE NULL
        END,

        /* Plain language, in the same order, so the grid reads as prose and
           the chip beside it agrees with the class above. */
        CASE
            WHEN a.FlagSoldMoreThanOnHand = 1 THEN 'Unrecorded issue, proven: the till sold more than the shift held'
            WHEN a.FlagCloseExceedsOnHand = 1 THEN 'Unrecorded issue: closed holding more than it ever received'
            WHEN a.ChainNetVar > @eps AND a.ChainIssues < @eps AND a.ChainPOS > @eps
                                              THEN 'Unrecorded issue: selling all window with no issues captured'
            WHEN a.ChainNetVar > @eps         THEN 'Unrecorded issue: net over for the window, cannot be balanced'
            WHEN a.LinkBroken = 1             THEN 'Chain broken: this opening is not the previous closing'
            WHEN a.QtyOpen < -@eps OR a.QtyClose < -@eps
                                              THEN 'Data fault: a count below zero'
            WHEN a.FlagNegativeClose = 1      THEN 'Data fault: balancing would drive the closing below zero'
            WHEN a.FlagBigAmendment = 1 OR a.FlagPctAmendment = 1
                                              THEN 'Implausible amendment: the correction is too large to be a miscount'
            WHEN a.FlagIssueWentNowhere = 1   THEN 'Issue went nowhere: issued, nothing sold, count unchanged'
            WHEN a.FlagShortChain = 1         THEN 'Chain too short: not enough active shifts in the window'
            WHEN a.ChainBlocked = 1           THEN 'Held back: another shift on this item blocks the chain'
            WHEN a.IsDormant = 1              THEN 'Dormant: opening and closing held equal, no stock moved'
            /* The residual short leads, whether or not this row was touched:
               it is the figure a branch is answerable for, and burying it
               under "opening follows the closing before it" is how a charge
               stops being visible on the row that carries it. */
            WHEN a.EffVarNew < -@eps AND a.RowMoves = 1
                                              THEN 'Balanced, short remains: accountable'
            WHEN a.EffVarNew < -@eps          THEN 'Short: accountable, no amendment available'
            WHEN ABS(a.EffAmend) > @eps       THEN 'Balanced to zero'
            /* EVERY outcome is "Label: explanation", and this was the one
               that was not. The screen shows the label and hovers the rest
               — see StockReconRunLine::outcomeLabel() — so an outcome with
               no colon has to render its whole sentence in a pill, which is
               how a 45-character string came to be the widest thing in the
               proposals table. The wording is otherwise unchanged. */
            WHEN a.RowMoves = 1               THEN 'Opening only: follows the amended closing before it'
            ELSE 'No change needed'
        END,

        a.RowMoves,
        a.RowMoves,
        'pending',

        /* The labels. A missing master row leaves the description NULL and
           every reader falls back to the item number — inventing a name for an
           item the master does not have would be worse than showing its id. */
        m.StockItemDescription,
        m.POSCode,
        m.StockLocation,
        ar.AreaDescription,
        emp.EmployeeCodes,
        emp.EmployeeNames,
        ISNULL(emp.EmployeeCount, 0),

        SYSDATETIME(), @UserId
    FROM #apply a
    LEFT JOIN agora.vw_StockMaster m
           ON m.BranchId = @BranchId AND m.StockItemNo = a.StockItemNo
    LEFT JOIN agora.vw_StockArea ar
           ON ar.BranchId = @BranchId AND ar.AreaNo = a.AreaNo
    LEFT JOIN #emp emp
           ON emp.TransactionDate = a.TransactionDate
          AND emp.ShiftNo = a.ShiftNo
          AND emp.AreaNo  = a.AreaNo;

    /* ------------------------------------------------------------- stage 9
       The header, from the same pass. Nothing here is recomputed in PHP.

       Every "after" figure is the EFFECTIVE one, so the strip at the top of
       the run describes what the press would leave behind rather than what the
       arithmetic could have done if nothing were blocking it. */
    ;WITH chains AS (
        SELECT AreaNo, StockItemNo,
               MAX(CONVERT(int, ChainBlocked)) AS Blocked,
               MAX(ChainNetVar)                AS T,
               MAX(SellPrice)                  AS SellPrice
        FROM #apply
        GROUP BY AreaNo, StockItemNo
    )
    UPDATE r SET
        r.Status            = 'previewed',
        r.TotalRows         = t.TotalRows,
        r.DormantRows       = t.DormantRows,
        r.ChainCount        = c.Chains,
        r.BalanceableChains = c.Chains - c.Blocked,
        r.BlockedChains     = c.Blocked,
        r.AmendedRows       = t.AmendedRows,
        r.OverRowsBefore    = t.OverRowsBefore,
        r.OverRowsAfter     = t.OverRowsAfter,
        r.ShortRowsBefore   = t.ShortRowsBefore,
        r.ShortRowsAfter    = t.ShortRowsAfter,
        r.OverUnitsBefore   = t.OverUnitsBefore,
        r.OverUnitsAfter    = t.OverUnitsAfter,
        r.ShortUnitsBefore  = t.ShortUnitsBefore,
        r.ShortUnitsAfter   = t.ShortUnitsAfter,
        r.UnitsAmended      = t.UnitsAmended,
        r.NetOverUnits      = c.NetOverUnits,
        r.NetOverValue      = c.NetOverValue,
        r.ShortValueAfter   = t.ShortValueAfter,
        r.UpdatedAt         = SYSDATETIME(),
        r.UpdatedBy         = @UserId
    FROM agora.StockReconRun r
    /*
     * EVERY AGGREGATE IS ISNULL'd, and that is not belt and braces.
     *
     * SUM() over an empty set is NULL, not 0. A window with no recon lines in
     * it — an area that did not trade that week, a date range off the end of
     * the data — is a perfectly ordinary answer, and without these the UPDATE
     * fails with "cannot insert the value NULL into column 'DormantRows'" and
     * the operator gets a 500 instead of "nothing to balance here". Found on
     * the first real form submission, 8 September 2026.
     */
    CROSS JOIN (
        SELECT
            COUNT(*)                                                                    AS TotalRows,
            ISNULL(SUM(CASE WHEN IsDormant = 1 THEN 1 ELSE 0 END), 0)                   AS DormantRows,
            ISNULL(SUM(CONVERT(int, RowMoves)), 0)                                      AS AmendedRows,
            ISNULL(SUM(CASE WHEN QtyVar    >  @eps THEN 1 ELSE 0 END), 0)               AS OverRowsBefore,
            ISNULL(SUM(CASE WHEN EffVarNew >  @eps THEN 1 ELSE 0 END), 0)               AS OverRowsAfter,
            ISNULL(SUM(CASE WHEN QtyVar    < -@eps THEN 1 ELSE 0 END), 0)               AS ShortRowsBefore,
            ISNULL(SUM(CASE WHEN EffVarNew < -@eps THEN 1 ELSE 0 END), 0)               AS ShortRowsAfter,
            CONVERT(decimal(18,3), ISNULL(SUM(CASE WHEN QtyVar    >  @eps THEN  QtyVar    ELSE 0 END), 0)) AS OverUnitsBefore,
            CONVERT(decimal(18,3), ISNULL(SUM(CASE WHEN EffVarNew >  @eps THEN  EffVarNew ELSE 0 END), 0)) AS OverUnitsAfter,
            CONVERT(decimal(18,3), ISNULL(SUM(CASE WHEN QtyVar    < -@eps THEN -QtyVar    ELSE 0 END), 0)) AS ShortUnitsBefore,
            CONVERT(decimal(18,3), ISNULL(SUM(CASE WHEN EffVarNew < -@eps THEN -EffVarNew ELSE 0 END), 0)) AS ShortUnitsAfter,
            CONVERT(decimal(18,3), ISNULL(SUM(ABS(EffAmend)), 0))                       AS UnitsAmended,
            CONVERT(decimal(18,2), ISNULL(SUM(CASE WHEN EffVarNew < -@eps THEN -EffVarNew * SellPrice ELSE 0 END), 0)) AS ShortValueAfter
        FROM #apply
    ) t
    CROSS JOIN (
        SELECT COUNT(*)                                                                 AS Chains,
               ISNULL(SUM(Blocked), 0)                                                  AS Blocked,
               CONVERT(decimal(18,3), ISNULL(SUM(CASE WHEN T > @eps THEN T ELSE 0 END), 0)) AS NetOverUnits,
               CONVERT(decimal(18,2), ISNULL(SUM(CASE WHEN T > @eps THEN T * SellPrice ELSE 0 END), 0)) AS NetOverValue
        FROM chains
    ) c
    WHERE r.BranchId = @BranchId AND r.Id = @RunId;

    DROP TABLE #emp;
    DROP TABLE #apply;
    DROP TABLE #gate;
    DROP TABLE #chain;

    SELECT CONVERT(bit, 1)                      AS Ok,
           'PREVIEWED'                          AS Code,
           'The balancing was computed and recorded. Nothing was written to PumpIT.' AS [Message],
           CONVERT(bigint, @RunId)              AS Id;
END
