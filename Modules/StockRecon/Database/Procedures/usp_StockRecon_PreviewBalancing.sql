/*
 * agora.usp_StockRecon_PreviewBalancing — shift variance balancing, computed.
 *
 * Read by:  Stock recon centre -> Balance (the preview press), and nothing else.
 * Reads:    agora.vw_StockReconLine, agora.vw_StockArea, agora.vw_StockMaster,
 *           agora.vw_StockItem, agora.StockIssueMultiple,
 *           agora.vw_StockReconShiftEmployee  (who was on the shift — the ONLY
 *           definition of that in Agora; readers use it too, see v1__14b)
 *           agora.vw_StockAreaFood  (prepared food per area: never a clear
 *           duplicate issue)
 * Writes:   agora.StockReconRunLine, agora.StockReconRun — Agora's own ledger.
 *           NOTHING in the customer's estate. The commit is a separate
 *           procedure and even that only writes to PumpIT in 'live' stamp mode.
 *
 * THE CLIENT'S RULES (ZRP Weekly Stock Recon pack, CEO, 23 Sep 2026). Everything
 * below follows them; docs/stock-recon.md says where each one lands.
 *
 *   1. Variance = POS - (opening + issued - closing). Negative is short.
 *   3. The opening must equal the previous closing. Where it does not, the
 *      OPENING is amended and the closing stands. Changing a closing to match
 *      is manipulation.
 *   4. Balancing: an over may be balanced against a short, a short may shrink.
 *      An over never becomes a short, a short never grows, and the latest
 *      closing count stands.
 *   5. An over within 10% of a whole issue multiple is an issue not written
 *      up. It is captured, not balanced away.
 *   10. A site is analysed only up to its last complete recon day.
 *   E1. Stock falling with no till sales against it is a PLU or recipe link
 *      fault. Nobody is charged for it.
 *
 * THE METHOD. With every opening repaired to the previous closing, Open(i) =
 * Close(i-1) holds, and the only lever left is the closing count. Raising one
 * by d lifts this shift's variance by d and drops the next one's by d, so over
 * a stretch everything cancels except the two ends: the total T is FIXED once
 * the openings are repaired. Balancing redistributes; it cannot reduce.
 *
 *   P(k) = running total of Variance over ACTIVE shifts
 *   M(k) = MAX(P(j) : j >= k)                  -- the reverse running maximum
 *   Q(k) = CLAMP(M(k), between 0 and T)
 *   Close(k) := Close(k) + (Q(k) - P(k))
 *
 * Q never rises, which is exactly "an over goes to nil and no further"; it
 * never sits below P, which is exactly "a short never grows"; and it is the
 * smallest such curve, so it overrides the fewest counts. The last active shift
 * is pinned at zero amendment — the latest count stands.
 *
 * WHERE AN OPENING WAS REPAIRED, THE CLOSING BEFORE IT IS PINNED TOO. The pack
 * audits a break by asking whether the previous closing moved; it cannot tell
 * "moved to match" from "moved to balance", and flags both (S12). So a break
 * splits the chain into SEGMENTS at that point, each balanced on its own with
 * its own last closing pinned. An over that its own segment cannot absorb is
 * a net over and is reported, never pushed across a count the rule says stands.
 *
 * EXCEPT A SMALL ONE (ZRP, 4 Oct 2026). A break of @KeepBreaksUpTo units or
 * fewer — 1, 2 or 3 by default — does not split the chain. The opening is
 * still repaired to the previous closing, but the closing before it is free to
 * move, so the chain balances straight through; the break is reported on the
 * row (BreakKept, the qty in the outcome) and on the exceptions tab as S10a or
 * S10b as before. S12 and S13 ignore a break that small, on the audit of what
 * is in PumpIT and on the check of what Agora is about to write, so a kept
 * break never withdraws its own chain as F3. 0 restores the old split.
 *
 * A DUPLICATE ISSUE IS TAKEN OUT FIRST (1 Oct 2026, decisions 01a0f60b-0386 and
 * -0459). Where a shift is short by EXACTLY the issue it was given and an
 * identical issue on a neighbouring shift arrived, the second issue never
 * reached the shelf: it was keyed twice. A clear one (D1) is removed from the
 * balancing basis — the issue reads 0, as the pack reads a fat-finger issue
 * as its correction — so the shift balances and nobody is charged, and the
 * commit writes QtyIssued = 0 with the prior kept. A possible one (D2) is
 * listed with its twin and never changed. Stage 2b has the rule.
 *
 * RULE 3 OF OURS, THE DORMANT SHIFT, is structural rather than a special case.
 * A shift whose (repaired) opening equals its closing, with nothing issued and
 * nothing sold, did not trade. SegSeq numbers only the active shifts, so a
 * dormant row carries the number of the active shift BEFORE it and inherits
 * that shift's amendment from the same join — its opening and its closing move
 * together and its variance stays zero. FlagDormantMoved re-checks the result.
 *
 * WHY IT WRITES. A branch-month is fifteen thousand shift lines, which as a
 * chunked multi-row INSERT from PHP is three hundred round trips — so the run
 * header is created first and its id passed in, and one INSERT ... SELECT lands
 * the lot. The header counts and the lines come out of the SAME pass.
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
    @HoldBackIncomplete   BIT             = 1,
    @ExcludedAreaGroups   NVARCHAR(400)   = NULL,   -- CSV of STK_Area.AreaGroup
    @KeepBreaksUpTo       DECIMAL(18,4)   = 3,      -- a break this small keeps the chain going
    /* config('stockrecon.issue_mode') = 'live'. 0 detects and reports a clear
       duplicate (D1) but leaves it in the basis, so nothing proposes to write
       the issue. */
    @RemoveDuplicates     BIT             = 0,
    /* config('stockrecon.exceptions.D2.blocks'). THE ONE PLACE a possible
       duplicate (D2) would hold its chain back from the commit; off, it is
       listed and the chain balances as usual (task 01a0f610-5ac0 decides). */
    @BlockPossibleDuplicates BIT          = 0,
    @UserId               INT             = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    /* Tolerance. Counts are floats on the source and a butchery item is
       weighed, so nothing is ever compared with = against zero. */
    DECLARE @eps DECIMAL(18,4) = 0.005;

    /* Whether two COUNTS are the same count — an opening against the previous
       closing, the answer against what PumpIT holds. Tighter than @eps: a
       weighed item is counted to the gram, 8.355 against 8.350 is a break the
       pack reports, and a row left unwritten over it leaves one in PumpIT.
       Half the three decimals the ledger stores. */
    DECLARE @tol DECIMAL(18,4) = 0.0005;

    /* The size a break has to EXCEED to split the chain. Below it the break is
       kept: repaired, reported, and balanced through. At 0 every break splits,
       which is how it worked from 30 Sep to 4 Oct 2026. */
    DECLARE @split DECIMAL(18,4) = ISNULL(CASE WHEN @KeepBreaksUpTo > 0 THEN @KeepBreaksUpTo ELSE 0 END, 0) + @tol;

    IF @RunId IS NULL OR NOT EXISTS (SELECT 1 FROM agora.StockReconRun WHERE BranchId = @BranchId AND Id = @RunId)
        THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;

    IF @FromDate > @ToDate
        THROW 51000, 'AGORA:BAD_WINDOW:The from date is after the to date.', 1;

    IF @AreaNo IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM agora.vw_StockArea WHERE BranchId = @BranchId AND AreaNo = @AreaNo)
        THROW 51000, 'AGORA:NO_SUCH_AREA:That counting area is not configured at this site.', 1;

    /* The area groups this site does not count as stock. Lotto, airtime and
       electricity are virtual products with no issues and no physical count
       (the pack's rule 12). By the customer's own AreaGroup column, never by a
       description pattern Agora invented. */
    DECLARE @Excluded TABLE (AreaGroup NVARCHAR(100) PRIMARY KEY);

    INSERT INTO @Excluded (AreaGroup)
    SELECT DISTINCT LTRIM(RTRIM(s.value))
    FROM STRING_SPLIT(ISNULL(@ExcludedAreaGroups, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '';

    /* ------------------------------------------------------------- stage 0
       Rule 10: the last complete recon day.

       The pack's test (cutoff.py), per SITE rather than per area, because an
       unfinished balancing or an unposted POS day is a site-wide state:

         a shift is in    when it carries >= 95% of its normal line count and
                          its share of non-zero closings is >= half the normal;
         a day is complete when every shift the site normally runs is in, and
                          its share of lines with a POS quantity is >= half the
                          normal.

       "Normal" is the median over the window. The pack takes one median across
       all shifts and requires at least two; that fails a site whose middle
       shift counts only a few areas (every day would be incomplete), so the
       line count is judged against each SHIFT's own median, and "every shift it
       normally runs" is every shift seen on at least half the days. On a
       two-shift site the two readings are the same test.

       Everything from the first incomplete day on is HELD BACK — not balanced,
       not written, counted on the header. Balancing it would pin the "latest
       closing" on a count nobody has captured yet. */
    DECLARE @CompleteTo DATE = @ToDate,
            @FirstBad   DATE = NULL,
            @Days       INT;

    IF @HoldBackIncomplete = 1
    BEGIN
        IF OBJECT_ID('tempdb..#ds') IS NOT NULL DROP TABLE #ds;

        SELECT CONVERT(date, l.TransactionDate)                                           AS D,
               l.ShiftNo,
               COUNT(*)                                                                   AS N,
               AVG(CASE WHEN ABS(ISNULL(l.QtyClose, 0))    > @eps THEN 1.0 ELSE 0.0 END)  AS CloseShare,
               AVG(CASE WHEN ABS(ISNULL(l.QtyComputer, 0)) > @eps THEN 1.0 ELSE 0.0 END)  AS PosShare
        INTO #ds
        FROM agora.vw_StockReconLine l
        JOIN agora.vw_StockArea a
          ON a.BranchId = l.BranchId AND a.AreaNo = l.AreaNo
        WHERE l.BranchId = @BranchId
          AND l.TransactionDate >= @FromDate
          AND l.TransactionDate <  DATEADD(day, 1, @ToDate)
          AND NOT EXISTS (SELECT 1 FROM @Excluded e WHERE e.AreaGroup = a.AreaGroup)
        GROUP BY CONVERT(date, l.TransactionDate), l.ShiftNo;

        SET @Days = (SELECT COUNT(DISTINCT D) FROM #ds);

        ;WITH norm AS (
            SELECT DISTINCT ds.ShiftNo,
                   PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY ds.N)          OVER (PARTITION BY ds.ShiftNo) AS NMed,
                   PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY ds.CloseShare) OVER (PARTITION BY ds.ShiftNo) AS CMed,
                   COUNT(*)                                                   OVER (PARTITION BY ds.ShiftNo) AS DaysSeen
            FROM #ds ds
        ),
        expected AS (
            SELECT ShiftNo, NMed, CMed FROM norm WHERE DaysSeen * 2 >= @Days
        ),
        dd AS (SELECT DISTINCT D FROM #ds),
        shiftsin AS (
            SELECT dd.D,
                   MIN(CASE WHEN s.N IS NOT NULL
                             AND s.N          >= 0.95 * e.NMed
                             AND s.CloseShare >= 0.50 * e.CMed THEN 1 ELSE 0 END) AS AllIn
            FROM dd
            CROSS JOIN expected e
            LEFT JOIN #ds s ON s.D = dd.D AND s.ShiftNo = e.ShiftNo
            GROUP BY dd.D
        ),
        pos AS (
            SELECT D, AVG(PosShare) AS PosShare FROM #ds GROUP BY D
        ),
        pmed AS (
            SELECT DISTINCT PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY PosShare) OVER () AS PMed FROM #ds
        )
        SELECT @FirstBad = MIN(p.D)
        FROM pos p
        JOIN shiftsin k ON k.D = p.D
        CROSS JOIN pmed
        WHERE k.AllIn = 0 OR p.PosShare < 0.5 * pmed.PMed;

        IF @FirstBad IS NOT NULL
            SET @CompleteTo = DATEADD(day, -1, @FirstBad);

        DROP TABLE #ds;
    END

    /* ------------------------------------------------------------- stage 1
       The window, up to the last complete day, with every quantity resolved
       once: the counted pair balancing works from, and the pair PumpIT holds
       NOW, which is what the commit compares its answer against.

       @UseOriginalCounts reads the _Original counts, which is what makes a
       re-run idempotent. Issues and POS are always the live columns: an issue
       captured at balancing is a missing issue captured, which the pack
       accepts (and reports as S4a). */
    IF OBJECT_ID('tempdb..#chain') IS NOT NULL DROP TABLE #chain;

    SELECT
        l.AreaNo,
        l.StockItemNo,
        CONVERT(date, l.TransactionDate)                                  AS TransactionDate,
        l.ShiftNo,
        CONVERT(decimal(18,2), ISNULL(l.SellPrice, 0))                    AS SellPrice,
        CONVERT(decimal(18,4), ISNULL(CASE WHEN @UseOriginalCounts = 1
                 THEN l.QtyOpen_Original  ELSE l.QtyOpen  END, 0))        AS QtyOpen,
        CONVERT(decimal(18,4), ISNULL(l.QtyIssued, 0))                    AS QtyIssued,
        CONVERT(decimal(18,4), ISNULL(CASE WHEN @UseOriginalCounts = 1
                 THEN l.QtyClose_Original ELSE l.QtyClose END, 0))        AS QtyClose,
        CONVERT(decimal(18,4), ISNULL(l.QtyComputer, 0))                  AS QtyPOS,
        CONVERT(decimal(18,4), ISNULL(l.QtyOpen, 0))                      AS QtyOpenNow,
        CONVERT(decimal(18,4), ISNULL(l.QtyClose, 0))                     AS QtyCloseNow,
        CONVERT(decimal(18,4), ISNULL(l.QtyOpen_Original, 0))             AS QtyOpenOrig,
        CONVERT(decimal(18,4), ISNULL(l.QtyClose_Original, 0))            AS QtyCloseOrig,
        CONVERT(decimal(18,4), ISNULL(l.QtyIssued_Original, 0))           AS QtyIssuedOrig,
        CONVERT(decimal(18,4), ISNULL(l.QtyComputer_Original, 0))         AS QtyPOSOrig
    INTO #chain
    FROM agora.vw_StockReconLine l
    JOIN agora.vw_StockArea a
      ON a.BranchId = l.BranchId AND a.AreaNo = l.AreaNo
    WHERE l.BranchId = @BranchId
      AND l.TransactionDate >= @FromDate
      AND l.TransactionDate <  DATEADD(day, 1, @ToDate)
      AND (@AreaNo IS NULL OR l.AreaNo = @AreaNo)
      AND NOT EXISTS (SELECT 1 FROM @Excluded e WHERE e.AreaGroup = a.AreaGroup);

    DECLARE @HeldBackRows INT = (SELECT COUNT(*) FROM #chain WHERE TransactionDate > @CompleteTo);

    DELETE FROM #chain WHERE TransactionDate > @CompleteTo;

    /* The pack's S11 is "the latest closing count was amended" — the latest
       in the data it was given, which it cuts at the last complete day. Only
       meaningful here when this window reaches that far: a preview of August
       does not end on anybody's latest count. */
    DECLARE @LatestDay DATE = (
        SELECT MAX(CONVERT(date, l.TransactionDate)) FROM agora.vw_StockReconLine l WHERE l.BranchId = @BranchId);
    DECLARE @AtDataEnd BIT = CASE WHEN @CompleteTo < @ToDate OR @ToDate >= @LatestDay THEN 1 ELSE 0 END;

    /* The shift BEFORE the window, per item — the previous closing the first
       opening has to equal. Its LIVE closing, because that row is outside the
       window and will not be written: the repaired opening must meet the
       chain as it stands in PumpIT. Five weeks back at most; the pack exports
       three, and a closing older than that is not "the previous shift". */
    IF OBJECT_ID('tempdb..#pred') IS NOT NULL DROP TABLE #pred;

    ;WITH items AS (SELECT DISTINCT AreaNo, StockItemNo FROM #chain),
    prior AS (
        SELECT l.AreaNo, l.StockItemNo,
               CONVERT(decimal(18,4), ISNULL(l.QtyClose, 0))          AS PredClose,
               CONVERT(decimal(18,4), ISNULL(l.QtyClose_Original, 0)) AS PredCloseOrig,
               ROW_NUMBER() OVER (PARTITION BY l.AreaNo, l.StockItemNo
                                  ORDER BY l.TransactionDate DESC, l.ShiftNo DESC) AS rn
        FROM agora.vw_StockReconLine l
        JOIN items i ON i.AreaNo = l.AreaNo AND i.StockItemNo = l.StockItemNo
        WHERE l.BranchId = @BranchId
          AND l.TransactionDate >= DATEADD(day, -35, @FromDate)
          AND l.TransactionDate <  @FromDate
    )
    SELECT AreaNo, StockItemNo, PredClose, PredCloseOrig INTO #pred FROM prior WHERE rn = 1;

    /* The item: its pack size and whether it is weighed. Head office's
       workbook first (v1__14f), the stock master second; weighed where the
       workbook says "Kilogram / litre", the unit is KG or LTR, or the
       description says so the way the pack reads it. */
    IF OBJECT_ID('tempdb..#item') IS NOT NULL DROP TABLE #item;

    SELECT i.AreaNo, i.StockItemNo,
           CONVERT(decimal(18,4), COALESCE(im.IssueMultiple, si.IssueMultiple, 0)) AS Mult,
           CONVERT(bit, CASE WHEN im.IsWeighed = 1
                              OR si.UOMCode IN ('KG', 'KGS', 'L', 'LT', 'LTR')
                              OR ' ' + ISNULL(m.StockItemDescription, '') + ' ' LIKE '%[^A-Z]KG[^A-Z]%'
                              OR m.StockItemDescription LIKE '%(K)%'
                              OR m.StockItemDescription LIKE '%(L)%'
                              OR m.StockItemDescription LIKE '%P/KG%'
                              OR m.StockItemDescription LIKE '%1000G%'
                              OR m.StockItemDescription LIKE '%LITRE%'
                             THEN 1 ELSE 0 END)                                   AS Weighed
    INTO #item
    FROM (SELECT DISTINCT AreaNo, StockItemNo FROM #chain) i
    OUTER APPLY (SELECT TOP 1 x.IssueMultiple, x.IsWeighed
                   FROM agora.StockIssueMultiple x
                  WHERE x.BranchId = @BranchId AND x.AreaNo = i.AreaNo AND x.StockItemNo = i.StockItemNo) im
    OUTER APPLY (SELECT TOP 1 x.IssueMultiple, x.UOMCode
                   FROM agora.vw_StockItem x
                  WHERE x.BranchId = @BranchId AND x.StockItemNo = i.StockItemNo
                  ORDER BY CASE WHEN x.AreaNo = i.AreaNo THEN 0 ELSE 1 END) si
    OUTER APPLY (SELECT TOP 1 x.StockItemDescription
                   FROM agora.vw_StockMaster x
                  WHERE x.BranchId = @BranchId AND x.StockItemNo = i.StockItemNo
                  ORDER BY CASE WHEN x.AreaNo = i.AreaNo THEN 0 ELSE 1 END, x.StockLocation) m;

    /* ------------------------------------------------------------- stage 2
       Rule 3: repair every opening to the previous closing, and number what is
       left.

       PrevClose is the counted closing of the row before, or the live closing
       of the shift before the window. ChainBreak is how far the counted
       opening missed it — negative is stock that vanished between shifts
       (S10a, "the known manipulation route"), positive is stock that appeared
       (S10b). OpenRep is the opening the rule says it should have been, and
       everything from here on works on it: the incoming shift carries the
       difference, which is what the pack's own staff basis does. */
    IF OBJECT_ID('tempdb..#rep') IS NOT NULL DROP TABLE #rep;

    ;WITH prev AS (
        SELECT c.*,
               /* LAG's default is the shift before the window, where there is
                  one, so the first row is checked like every other. */
               LAG(c.QtyClose, 1, p.PredClose) OVER (
                   PARTITION BY c.AreaNo, c.StockItemNo
                   ORDER BY c.TransactionDate, c.ShiftNo)                   AS PrevClose,
               /* The same, as the pack's audit sees it: original on both sides,
                  and the live previous closing, so S12 and S13 can be read off
                  what the hand balancing did. */
               LAG(c.QtyCloseOrig, 1, p.PredCloseOrig) OVER (
                   PARTITION BY c.AreaNo, c.StockItemNo
                   ORDER BY c.TransactionDate, c.ShiftNo)                   AS PrevCloseOrig,
               LAG(c.QtyCloseNow, 1, p.PredClose) OVER (
                   PARTITION BY c.AreaNo, c.StockItemNo
                   ORDER BY c.TransactionDate, c.ShiftNo)                   AS PrevCloseNow,
               CONVERT(bit, CASE WHEN LEAD(c.ShiftNo) OVER (
                                           PARTITION BY c.AreaNo, c.StockItemNo
                                           ORDER BY c.TransactionDate, c.ShiftNo) IS NULL
                                 THEN 1 ELSE 0 END)                         AS IsLastInWindow
        FROM #chain c
        LEFT JOIN #pred p ON p.AreaNo = c.AreaNo AND p.StockItemNo = c.StockItemNo
    ),
    rep AS (
        SELECT prev.*,
               CONVERT(decimal(18,4), CASE WHEN prev.PrevClose IS NULL THEN 0
                                           ELSE prev.QtyOpen - prev.PrevClose END) AS ChainBreak,
               CONVERT(decimal(18,4), ISNULL(prev.PrevClose, prev.QtyOpen))        AS OpenRep
        FROM prev
    )
    SELECT rep.*,
           CONVERT(bit, CASE WHEN ABS(rep.ChainBreak) > @tol THEN 1 ELSE 0 END) AS LinkBroken,
           /* Broken, but small enough to keep the chain going. */
           CONVERT(bit, CASE WHEN ABS(rep.ChainBreak) > @tol
                              AND ABS(rep.ChainBreak) <= @split THEN 1 ELSE 0 END)  AS BreakKept,
           /* As the staff counted it, before any repair — what the screen
              calls "counted". */
           CONVERT(decimal(18,4), rep.QtyPOS - (rep.QtyOpen + rep.QtyIssued - rep.QtyClose)) AS QtyVar,
           /* The basis balancing works on: the opening repaired. */
           CONVERT(decimal(18,4), rep.QtyPOS - (rep.OpenRep + rep.QtyIssued - rep.QtyClose)) AS QtyVarRep,
           CONVERT(bit, CASE WHEN ABS(rep.OpenRep - rep.QtyClose) < @eps
                              AND ABS(rep.QtyIssued)           < @eps
                              AND ABS(rep.QtyPOS)              < @eps
                             THEN 1 ELSE 0 END)                                  AS IsDormant,
           /* The issue as PumpIT holds it. QtyIssued itself becomes the
              BASIS issue below, 0 on a clear duplicate (stage 2b); every
              reading of PumpIT as it stands — the audit, the hand variance,
              what the run records as issued — reads this one. */
           rep.QtyIssued                                                         AS QtyIssuedKeyed,
           CONVERT(decimal(18,4), 0)                                             AS IssueCut,
           CONVERT(nvarchar(2),   NULL)                                          AS DupTier,
           CONVERT(date,          NULL)                                          AS DupTwinDate,
           CONVERT(int,           NULL)                                          AS DupTwinShift,
           CONVERT(decimal(18,4), NULL)                                          AS DupTwinVar,
           CONVERT(decimal(18,4), NULL)                                          AS DupQty,
           CONVERT(decimal(18,4), NULL)                                          AS DupTol,
           CONVERT(nvarchar(80),  NULL)                                          AS DupReason
    INTO #rep
    FROM rep;

    /* ------------------------------------------------------------- stage 2b
       DUPLICATE ISSUES (customer's ask via Ryan; decisions 01a0f60b-0386 and
       01a0f60b-0459, 1 Oct 2026; docs/stock-recon.md → "Duplicate issues").

       PumpIT's capture SETS QtyIssued per shift line, so a resubmitted capture
       cannot double it: a duplicate is a person keying the same issue again,
       typically the night shift re-keying the day's issue sheet. The count is
       the independent witness. If the issue never arrived, opening less
       closing is just what the till sold, and the shift is short by exactly
       the issue.

       CLEAR (D1) — on shift s with issue Q, all five:
         1. s is short by Q exactly: whole units to the count, weighed items
            within 2% of Q;
         2. an identical issue on a neighbouring shift t — same item and area,
            within two shifts and one day — whose stock DID arrive: t balances
            (to the same tolerance);
         3. t is on the same day, or a day apart when Q is not the item's usual
            delivery (under half its issues over the window and the eight weeks
            before it are that size);
         4. one candidate per PAIR: no identical-issue neighbour of s is ALSO
            short by exactly its issue — then nothing says which of the two was
            real, and we never guess;
         5. shelf goods only. In prepared food a same-size batch thrown away
            unrecorded looks exactly the same (agora.vw_StockAreaFood).
       POSSIBLE (D2) — s short within 10% of Q, a twin exists, and one of the
       above fails. Listed with the twin and the reason; never changed.

       The test reads the BASIS variance — repaired opening, the issue as
       PumpIT holds it — so a duplicate head office already cut by hand is
       gone from it and is not found twice. The day either side of the window
       is read too, so a twin just outside it can make a candidate possible or
       ambiguous; only a twin inside the window can make one clear. Every
       approximation here errs the same way: a clear one missed, never a false
       one.

       A D1 is then REMOVED from the basis when @RemoveDuplicates is on: its
       issue reads 0, exactly as the pack's own staff basis reads a fat-finger
       issue (S4) as its correction. The shift balances, it is dormant if it
       sold nothing, and everything downstream — segments, T, every flag, the
       balancing — sees the issue that should have been there. */
    IF OBJECT_ID('tempdb..#dn') IS NOT NULL DROP TABLE #dn;

    SELECT r.AreaNo, r.StockItemNo, r.TransactionDate, r.ShiftNo,
           r.QtyIssued AS Q, r.QtyVarRep AS V, CONVERT(bit, 1) AS InWindow
    INTO #dn
    FROM #rep r;

    /* The margin: the day before the window and the day after the last
       complete one, as counted. Read only to make a candidate possible or
       ambiguous — so an unrepaired opening, or an unfinished day, can only
       cost a clear one, never create one. */
    DECLARE @m INT = 0, @mFrom DATE, @mTo DATE;

    /* Two single-day ranges rather than one OR, so each is a seek on
       PumpIT's (SSBranchId, TransactionDate, …) key. */
    WHILE @m < 2
    BEGIN
        SET @mFrom = CASE @m WHEN 0 THEN DATEADD(day, -1, @FromDate) ELSE DATEADD(day, 1, @CompleteTo) END;
        SET @mTo   = DATEADD(day, 1, @mFrom);
        SET @m     = @m + 1;

        ;WITH items AS (SELECT DISTINCT AreaNo, StockItemNo FROM #rep)
        INSERT INTO #dn (AreaNo, StockItemNo, TransactionDate, ShiftNo, Q, V, InWindow)
        SELECT l.AreaNo, l.StockItemNo, CONVERT(date, l.TransactionDate), l.ShiftNo,
               CONVERT(decimal(18,4), ISNULL(l.QtyIssued, 0)),
               CONVERT(decimal(18,4), ISNULL(l.QtyComputer, 0)
                   - (ISNULL(CASE WHEN @UseOriginalCounts = 1 THEN l.QtyOpen_Original ELSE l.QtyOpen END, 0)
                      + ISNULL(l.QtyIssued, 0)
                      - ISNULL(CASE WHEN @UseOriginalCounts = 1 THEN l.QtyClose_Original ELSE l.QtyClose END, 0))),
               CONVERT(bit, 0)
        FROM agora.vw_StockReconLine l
        JOIN items i ON i.AreaNo = l.AreaNo AND i.StockItemNo = l.StockItemNo
        WHERE l.BranchId = @BranchId
          AND l.TransactionDate >= @mFrom AND l.TransactionDate < @mTo;
    END

    IF OBJECT_ID('tempdb..#dp') IS NOT NULL DROP TABLE #dp;

    SELECT d.AreaNo, d.StockItemNo, d.TransactionDate, d.ShiftNo, d.Q, d.V, d.InWindow,
           ROW_NUMBER() OVER (PARTITION BY d.AreaNo, d.StockItemNo
                              ORDER BY d.TransactionDate, d.ShiftNo)                    AS Pos,
           /* Whole units to the count (the counts are floats, hence @eps);
              weighed items within 2% of the issue. */
           CONVERT(decimal(18,4), CASE WHEN i.Weighed = 1 THEN 0.02 * ABS(d.Q) ELSE @eps END) AS Tol,
           CONVERT(bit, CASE WHEN d.Q > @eps
                              AND ABS(d.V + d.Q) <= CASE WHEN i.Weighed = 1 THEN 0.02 * d.Q ELSE @eps END
                             THEN 1 ELSE 0 END)                                     AS Exact,
           CONVERT(bit, CASE WHEN d.Q > @eps AND ABS(d.V + d.Q) <= 0.10 * d.Q
                             THEN 1 ELSE 0 END)                                     AS Near
    INTO #dp
    FROM #dn d
    JOIN #item i ON i.AreaNo = d.AreaNo AND i.StockItemNo = d.StockItemNo;

    DROP TABLE #dn;

    /* Every candidate against every twin it could have: an identical issue
       within two shifts and one day. */
    IF OBJECT_ID('tempdb..#dpair') IS NOT NULL DROP TABLE #dpair;

    SELECT c.AreaNo, c.StockItemNo, c.TransactionDate, c.ShiftNo, c.Q, c.Tol, c.Exact,
           t.TransactionDate                                                AS TwinDate,
           t.ShiftNo                                                        AS TwinShift,
           t.V                                                              AS TwinVar,
           t.InWindow                                                       AS TwinInWindow,
           t.Exact                                                          AS TwinExact,
           ABS(DATEDIFF(day, c.TransactionDate, t.TransactionDate))         AS DayGap,
           ABS(t.Pos - c.Pos)                                               AS PosGap,
           CONVERT(bit, CASE WHEN ABS(t.V) <= c.Tol THEN 1 ELSE 0 END)      AS TwinArrived
    INTO #dpair
    FROM #dp c
    JOIN #dp t
      ON t.AreaNo = c.AreaNo AND t.StockItemNo = c.StockItemNo
     AND t.Pos BETWEEN c.Pos - 2 AND c.Pos + 2 AND t.Pos <> c.Pos
     AND ABS(DATEDIFF(day, c.TransactionDate, t.TransactionDate)) <= 1
     AND t.Q > @eps AND ABS(t.Q - c.Q) <= c.Tol
    WHERE c.InWindow = 1 AND c.Near = 1;

    DROP TABLE #dp;

    /* Rule 3's "usual delivery": the share of the item's issues over the
       window and the eight weeks before it that are this size. Read only for
       the candidates whose clear twin is a day away — it decides nothing else. */
    IF OBJECT_ID('tempdb..#dusual') IS NOT NULL DROP TABLE #dusual;

    /* Shaped off #dpair so the item number keeps the source's collation. */
    SELECT TOP 0 AreaNo, StockItemNo, Q, CONVERT(decimal(9,4), 0) AS UsualShare
    INTO #dusual
    FROM #dpair;

    /* Most runs have no such candidate at all, and then the read is skipped. */
    IF EXISTS (SELECT 1 FROM #dpair WHERE TwinArrived = 1 AND TwinInWindow = 1 AND DayGap = 1)
    BEGIN
        ;WITH need AS (
            SELECT DISTINCT AreaNo, StockItemNo, Q, Tol
            FROM #dpair
            WHERE TwinArrived = 1 AND TwinInWindow = 1 AND DayGap = 1
        ),
        iss AS (
            SELECT l.AreaNo, l.StockItemNo, CONVERT(decimal(18,4), l.QtyIssued) AS Q
            FROM agora.vw_StockReconLine l
            JOIN (SELECT DISTINCT AreaNo, StockItemNo FROM need) n
              ON n.AreaNo = l.AreaNo AND n.StockItemNo = l.StockItemNo
            WHERE l.BranchId = @BranchId
              AND l.TransactionDate >= DATEADD(day, -56, @FromDate)
              AND l.TransactionDate <  DATEADD(day, 1, @CompleteTo)
              AND l.QtyIssued > @eps
        )
        INSERT INTO #dusual (AreaNo, StockItemNo, Q, UsualShare)
        SELECT n.AreaNo, n.StockItemNo, n.Q,
               CONVERT(decimal(9,4), AVG(CASE WHEN ABS(s.Q - n.Q) <= n.Tol THEN 1.0 ELSE 0.0 END))
        FROM need n
        JOIN iss s ON s.AreaNo = n.AreaNo AND s.StockItemNo = n.StockItemNo
        GROUP BY n.AreaNo, n.StockItemNo, n.Q;
    END

    /* The verdict, per candidate. */
    IF OBJECT_ID('tempdb..#dup') IS NOT NULL DROP TABLE #dup;

    ;WITH judged AS (
        SELECT p.*,
               CONVERT(bit, CASE WHEN p.TwinArrived = 1 AND p.TwinInWindow = 1
                                  AND (p.DayGap = 0 OR ISNULL(u.UsualShare, 1) < 0.5)
                                 THEN 1 ELSE 0 END)                         AS ClearTwin,
               CONVERT(bit, CASE WHEN p.TwinArrived = 1 AND p.TwinInWindow = 1 AND p.DayGap = 1
                                  AND ISNULL(u.UsualShare, 1) >= 0.5
                                 THEN 1 ELSE 0 END)                         AS UsualTwin
        FROM #dpair p
        LEFT JOIN #dusual u
               ON u.AreaNo = p.AreaNo AND u.StockItemNo = p.StockItemNo AND u.Q = p.Q
    ),
    cand AS (
        SELECT j.AreaNo, j.StockItemNo, j.TransactionDate, j.ShiftNo,
               MAX(j.Q) AS Q, MAX(j.Tol) AS Tol,
               MAX(CONVERT(int, j.Exact))        AS Exact,
               MAX(CONVERT(int, j.ClearTwin))    AS ClearTwin,
               MAX(CONVERT(int, j.UsualTwin))    AS UsualTwin,
               MAX(CONVERT(int, j.TwinArrived))  AS AnyArrived,
               MAX(CONVERT(int, j.TwinExact))    AS Ambiguous,
               MAX(CONVERT(int, f.IsPreparedFood)) AS Food
        FROM judged j
        LEFT JOIN agora.vw_StockAreaFood f
               ON f.BranchId = @BranchId AND f.AreaNo = j.AreaNo
        GROUP BY j.AreaNo, j.StockItemNo, j.TransactionDate, j.ShiftNo
    ),
    tiered AS (
        SELECT c.*,
               CASE WHEN c.Exact = 1 AND c.ClearTwin = 1 AND c.Ambiguous = 0 AND ISNULL(c.Food, 0) = 0
                    THEN 'D1' ELSE 'D2' END                                 AS Tier,
               /* Why a possible one is not clear — the first test it fails, in
                  the order the investigation reported them. */
               CASE WHEN c.Exact = 1 AND c.ClearTwin = 1 AND c.Ambiguous = 0 AND ISNULL(c.Food, 0) = 0
                        THEN CASE WHEN @RemoveDuplicates = 0
                                  THEN N'Not removed: removing duplicates is switched off' END
                    WHEN c.Exact = 1 AND c.ClearTwin = 1 AND c.Ambiguous = 0
                        THEN N'Prepared food: a batch thrown away looks the same'
                    WHEN c.AnyArrived = 0
                        THEN N'Both shifts short: which issue was real is not known'
                    WHEN c.Exact = 0
                        THEN N'Short is near the issue, not equal to it'
                    WHEN c.ClearTwin = 0 AND c.UsualTwin = 1
                        THEN N'The usual delivery size, a day apart'
                    WHEN c.ClearTwin = 0
                        THEN N'The twin that arrived is outside the window'
                    ELSE N'Both sides of a pair are short by the issue'
               END                                                          AS Reason
        FROM cand c
    )
    SELECT t.AreaNo, t.StockItemNo, t.TransactionDate, t.ShiftNo, t.Q, t.Tol, t.Tier, t.Reason,
           tw.TwinDate, tw.TwinShift, tw.TwinVar
    INTO #dup
    FROM tiered t
    /* The twin to show: the one that makes it clear, else one that arrived,
       else the nearest — same day before a day apart, then the closer shift. */
    OUTER APPLY (
        SELECT TOP 1 j.TwinDate, j.TwinShift, j.TwinVar
        FROM judged j
        WHERE j.AreaNo = t.AreaNo AND j.StockItemNo = t.StockItemNo
          AND j.TransactionDate = t.TransactionDate AND j.ShiftNo = t.ShiftNo
        ORDER BY j.ClearTwin DESC, j.TwinArrived DESC, j.TwinInWindow DESC, j.DayGap, j.PosGap,
                 j.TwinDate, j.TwinShift
    ) tw;

    DROP TABLE #dpair;
    DROP TABLE #dusual;

    UPDATE r SET
        r.DupTier      = d.Tier,
        r.DupQty       = d.Q,
        r.DupTol       = d.Tol,
        r.DupReason    = d.Reason,
        r.DupTwinDate  = d.TwinDate,
        r.DupTwinShift = d.TwinShift,
        r.DupTwinVar   = d.TwinVar
    FROM #rep r
    JOIN #dup d
      ON d.AreaNo = r.AreaNo AND d.StockItemNo = r.StockItemNo
     AND d.TransactionDate = r.TransactionDate AND d.ShiftNo = r.ShiftNo;

    DROP TABLE #dup;

    /* Removed from the basis: the issue reads 0, and the variance and the
       dormant test follow. QtyVar — as the staff counted it — is left alone;
       it is the "before" every screen shows. */
    IF @RemoveDuplicates = 1
        UPDATE #rep SET
            IssueCut  = QtyIssued,
            QtyVarRep = QtyVarRep + QtyIssued,
            QtyIssued = 0,
            IsDormant = CONVERT(bit, CASE WHEN ABS(OpenRep - QtyClose) < @eps
                                           AND ABS(QtyPOS)            < @eps
                                          THEN 1 ELSE 0 END)
        WHERE DupTier = 'D1';

    /* Segments and sequence numbers. A repaired break starts a new segment, so
       the closing before it is the last closing of the segment before — pinned,
       exactly as the latest count is. A KEPT break does not: the segment, and
       the balancing, run straight through it. SegSeq counts active shifts inside the
       segment; ActiveSeq and ActiveLen are the whole chain's, for the screen
       and for the denominators of N1, N2 and L1. */
    IF OBJECT_ID('tempdb..#gate') IS NOT NULL DROP TABLE #gate;

    ;WITH segd AS (
        SELECT r.*,
               SUM(CASE WHEN r.LinkBroken = 1 AND r.BreakKept = 0 THEN 1 ELSE 0 END) OVER (
                   PARTITION BY r.AreaNo, r.StockItemNo
                   ORDER BY r.TransactionDate, r.ShiftNo
                   ROWS UNBOUNDED PRECEDING)                                  AS SegNo
        FROM #rep r
    )
    SELECT s.*,
           SUM(CASE WHEN s.IsDormant = 0 THEN 1 ELSE 0 END) OVER (
               PARTITION BY s.AreaNo, s.StockItemNo, s.SegNo
               ORDER BY s.TransactionDate, s.ShiftNo
               ROWS UNBOUNDED PRECEDING)                                      AS SegSeq,
           SUM(CASE WHEN s.IsDormant = 0 THEN 1 ELSE 0 END) OVER (
               PARTITION BY s.AreaNo, s.StockItemNo, s.SegNo)                 AS SegActiveLen,
           SUM(s.QtyVarRep) OVER (PARTITION BY s.AreaNo, s.StockItemNo, s.SegNo) AS SegT,
           SUM(CASE WHEN s.IsDormant = 0 THEN 1 ELSE 0 END) OVER (
               PARTITION BY s.AreaNo, s.StockItemNo
               ORDER BY s.TransactionDate, s.ShiftNo
               ROWS UNBOUNDED PRECEDING)                                      AS ActiveSeq,
           SUM(CASE WHEN s.IsDormant = 0 THEN 1 ELSE 0 END) OVER (
               PARTITION BY s.AreaNo, s.StockItemNo)                          AS ActiveLen,
           COUNT(*) OVER (PARTITION BY s.AreaNo, s.StockItemNo)               AS ChainShifts
    INTO #gate
    FROM segd s;

    /* The chain, whole: its repaired total, what it sold and was issued, how
       many days it was live on, and whether any of its segments ends over. */
    IF OBJECT_ID('tempdb..#agg') IS NOT NULL DROP TABLE #agg;

    SELECT g.AreaNo, g.StockItemNo,
           SUM(g.QtyVarRep)                                                  AS T,
           SUM(g.QtyIssued)                                                  AS ChainIssues,
           SUM(g.QtyPOS)                                                     AS ChainPOS,
           COUNT(DISTINCT CASE WHEN g.IsDormant = 0 THEN g.TransactionDate END) AS ActiveDays,
           SUM(CASE WHEN ABS(g.QtyClose - g.OpenRep) < @eps
                     AND g.QtyIssued < @eps
                     AND g.QtyPOS    > @eps THEN 1 ELSE 0 END)               AS NoCountShifts,
           MAX(CASE WHEN g.SegT > @eps THEN 1 ELSE 0 END)                    AS AnySegmentOver
    INTO #agg
    FROM #gate g
    GROUP BY g.AreaNo, g.StockItemNo;

    /* ------------------------------------------------------------- stage 3
       The algorithm, per segment, on active shifts only. The nested CASE is a
       clamp written without GREATEST/LEAST: the band is 0..T when T is
       negative and T..0 when T is positive. */
    IF OBJECT_ID('tempdb..#apply') IS NOT NULL DROP TABLE #apply;

    ;WITH act AS
    (
        SELECT g.AreaNo, g.StockItemNo, g.SegNo, g.SegSeq, g.SegActiveLen, g.SegT AS T,
               SUM(g.QtyVarRep) OVER (
                   PARTITION BY g.AreaNo, g.StockItemNo, g.SegNo
                   ORDER BY g.SegSeq
                   ROWS UNBOUNDED PRECEDING)                               AS P
        FROM #gate g
        WHERE g.IsDormant = 0
    ),
    env AS
    (
        SELECT act.*,
               MAX(act.P) OVER (
                   PARTITION BY act.AreaNo, act.StockItemNo, act.SegNo
                   ORDER BY act.SegSeq
                   ROWS BETWEEN CURRENT ROW AND UNBOUNDED FOLLOWING)       AS M
        FROM act
    ),
    sol AS
    (
        SELECT env.AreaNo, env.StockItemNo, env.SegNo, env.SegSeq,
               CASE WHEN env.SegSeq = env.SegActiveLen
                    THEN CONVERT(decimal(18,4), 0)     /* the latest count of the segment stands */
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
       Give every row its amendment. Joined on SegSeq inside the segment, so an
       active row collects its own and a dormant row collects the one belonging
       to the active shift before it. A LEADING dormant row has SegSeq = 0,
       matches nothing, and falls to zero through the ISNULL. */
    joined AS
    (
        SELECT g.*,
               ISNULL(s.AmendClose, CONVERT(decimal(18,4), 0))             AS AmendClose,
               a.T                                                         AS ChainNetVar,
               a.ChainIssues, a.ChainPOS, a.ActiveDays, a.NoCountShifts, a.AnySegmentOver,
               i.Mult, i.Weighed
        FROM #gate g
        JOIN #agg a
          ON a.AreaNo = g.AreaNo AND a.StockItemNo = g.StockItemNo
        JOIN #item i
          ON i.AreaNo = g.AreaNo AND i.StockItemNo = g.StockItemNo
        LEFT JOIN sol s
               ON s.AreaNo      = g.AreaNo
              AND s.StockItemNo = g.StockItemNo
              AND s.SegNo       = g.SegNo
              AND s.SegSeq      = g.SegSeq
    ),
    /* ----------------------------------------------------------- stage 5
       Rebuild the chain. New closing is the counted closing plus the
       amendment; new opening is the previous row's new closing — the chain
       rule restated, and what repairs every break. The first row takes the
       closing of the shift before the window where there is one. */
    chained AS
    (
        SELECT j.*,
               CONVERT(decimal(18,4), j.QtyClose + j.AmendClose)           AS QtyCloseNew,
               CONVERT(decimal(18,4),
                   ISNULL(LAG(j.QtyClose + j.AmendClose) OVER (
                       PARTITION BY j.AreaNo, j.StockItemNo
                       ORDER BY j.TransactionDate, j.ShiftNo), j.OpenRep)) AS QtyOpenNew,
               /* Rule 5's test, and the pack's near_mult(): within 10% of k
                  whole packs, k >= 1, for a real pack (2 or more) on an item
                  that is not weighed. */
               CASE WHEN j.Mult >= 2 AND j.Weighed = 0 AND j.QtyVarRep > @eps
                     AND ROUND(j.QtyVarRep / NULLIF(j.Mult, 0), 0) >= 1
                     AND ABS(j.QtyVarRep - ROUND(j.QtyVarRep / NULLIF(j.Mult, 0), 0) * j.Mult) <= 0.10 * j.Mult
                    THEN 1 ELSE 0 END                                      AS PackOver
        FROM joined j
    ),
    /* ----------------------------------------------------------- stage 6
       Score it, and raise every flag that should stop a chain being written.
       Every test reads the REPAIRED opening: it is the stock that was there. */
    scored AS
    (
        SELECT ch.*,
               CONVERT(decimal(18,4),
                   ch.QtyPOS - (ch.QtyOpenNew + ch.QtyIssued - ch.QtyCloseNew)) AS QtyVarNew,

               /* U1 — the till sold more than the shift could possibly hold. */
               CONVERT(bit, CASE WHEN ch.QtyPOS - (ch.OpenRep + ch.QtyIssued) > @eps THEN 1 ELSE 0 END)
                                                                           AS FlagSoldMoreThanOnHand,
               /* U2 — it closed holding more than it ever received. */
               CONVERT(bit, CASE WHEN ch.QtyClose - (ch.OpenRep + ch.QtyIssued) > @eps THEN 1 ELSE 0 END)
                                                                           AS FlagCloseExceedsOnHand,
               /* U4 — a segment ends over. No amendment can remove it, and
                  pushing it across a pinned closing is what the rule forbids. */
               CONVERT(bit, ch.AnySegmentOver)                             AS FlagNetOver,
               /* F2 — issued, nothing sold, count unchanged. It LOOKS dormant
                  and is not, so it must never be folded away. */
               CONVERT(bit, CASE WHEN ch.IsDormant = 0
                                  AND ABS(ch.OpenRep - ch.QtyClose) < @eps
                                  AND ch.QtyIssued > @eps
                                  AND ABS(ch.QtyPOS) < @eps
                                 THEN 1 ELSE 0 END)                        AS FlagIssueWentNowhere,
               /* F1 — the plausibility caps, both halves. */
               CONVERT(bit, CASE WHEN ABS(ch.AmendClose) > @MaxAmendment THEN 1 ELSE 0 END)
                                                                           AS FlagBigAmendment,
               CONVERT(bit, CASE WHEN ch.OpenRep + ch.QtyIssued > 0
                                  AND ABS(ch.AmendClose) > @MaxAmendmentPct * (ch.OpenRep + ch.QtyIssued)
                                 THEN 1 ELSE 0 END)                        AS FlagPctAmendment,
               CONVERT(bit, CASE WHEN ch.QtyClose + ch.AmendClose < -@eps THEN 1 ELSE 0 END)
                                                                           AS FlagNegativeClose,
               CONVERT(bit, CASE WHEN ch.ActiveLen < @MinShiftsInChain THEN 1 ELSE 0 END)
                                                                           AS FlagShortChain,
               /* Rule 3 of ours, checked rather than assumed. */
               CONVERT(bit, CASE WHEN ch.IsDormant = 1
                                  AND ABS(ch.QtyCloseNew - ch.QtyOpenNew) > @eps
                                 THEN 1 ELSE 0 END)                        AS FlagDormantMoved,
               /* S1 — an over of a whole pack: an issue not written up. On
                  the shift that has it; the chain is blocked by stage 7. */
               CONVERT(bit, ch.PackOver)                                   AS FlagPackOver,
               /* E1 — the pack's "no till sales linked": the stock fell by
                  more than max(3, a pack) over at least two days and the till
                  shows a tenth of that or less. The selling PLU or recipe is
                  not reducing this item, so the short is false. */
               CONVERT(bit, CASE WHEN ch.ChainNetVar < -CASE WHEN ch.Mult > 3 THEN ch.Mult ELSE 3 END
                                  AND ch.ChainPOS <= 0.10 * ABS(ch.ChainNetVar)
                                  AND ch.ActiveDays >= 2
                                 THEN 1 ELSE 0 END)                        AS FlagNoPosLink
        FROM chained ch
    ),
    /* ----------------------------------------------------------- stage 7
       One bad shift blocks its whole chain, so a chain is never balanced half
       way around a fault. A repaired break does NOT block: it is repaired. */
    blocked AS
    (
        SELECT s.*,
               CONVERT(bit, MAX(CONVERT(int, s.FlagNetOver) + CONVERT(int, s.FlagSoldMoreThanOnHand)
                              + CONVERT(int, s.FlagCloseExceedsOnHand) + CONVERT(int, s.FlagIssueWentNowhere)
                              + CONVERT(int, s.FlagBigAmendment) + CONVERT(int, s.FlagPctAmendment)
                              + CONVERT(int, s.FlagNegativeClose) + CONVERT(int, s.FlagShortChain)
                              + CONVERT(int, s.FlagDormantMoved) + CONVERT(int, s.FlagPackOver)
                              + CONVERT(int, s.FlagNoPosLink)
                              /* A possible duplicate holds its chain back only
                                 when config says D2 blocks — see the parameter. */
                              + CASE WHEN @BlockPossibleDuplicates = 1 AND s.DupTier = 'D2' THEN 1 ELSE 0 END)
                   OVER (PARTITION BY s.AreaNo, s.StockItemNo))            AS ChainBlocked
        FROM scored s
    ),
    /* A BLOCKED CHAIN CARRIES NO PROPOSAL AT ALL — not even the opening
       repairs. Its "after" figures are its counted figures, because anything
       else describes a world the press cannot produce. */
    effective AS
    (
        SELECT b.*,
               CASE WHEN b.ChainBlocked = 1 THEN CONVERT(decimal(18,4), 0) ELSE b.AmendClose  END AS EffAmend,
               CASE WHEN b.ChainBlocked = 1 THEN b.QtyOpen  ELSE b.QtyOpenNew  END               AS EffOpenNew,
               CASE WHEN b.ChainBlocked = 1 THEN b.QtyClose ELSE b.QtyCloseNew END               AS EffCloseNew,
               CASE WHEN b.ChainBlocked = 1 THEN b.QtyVar   ELSE b.QtyVarNew   END               AS EffVarNew
        FROM blocked b
    ),
    ranked AS
    (
        SELECT e.*,
               SUM(CASE WHEN e.IsDormant = 0 AND e.EffVarNew < -@eps THEN 1 ELSE 0 END)
                   OVER (PARTITION BY e.AreaNo, e.StockItemNo)             AS ShortShiftsAfter,
               /*
                * DOES THIS ROW MOVE AT ALL — against what PumpIT holds NOW.
                *
                * Compared with the counts the preview worked from, a row whose
                * answer is its own count "does not move" — and if somebody had
                * already balanced it by hand, the hand figure stays in PumpIT
                * beside Agora's repaired neighbours and the chain in the source
                * breaks exactly where the commit was meant to mend it. So the
                * target is compared with the live pair, and a hand amendment
                * the rules do not produce is put back (outcome "Restored").
                * Two columns, not one: a row's closing can be pinned while its
                * opening still has to follow the chain.
                */
               CONVERT(bit, CASE WHEN e.ChainBlocked = 1 THEN 0
                                 WHEN ABS(e.EffCloseNew - e.QtyCloseNow) > @tol
                                   OR ABS(e.EffOpenNew  - e.QtyOpenNow)  > @tol
                                 THEN 1 ELSE 0 END)                        AS RowMoves,
               /* S3 — a short of a whole pack, after balancing. Reported. */
               CONVERT(bit, CASE WHEN e.Mult >= 2 AND e.Weighed = 0 AND -e.EffVarNew > @eps
                                  AND ROUND(-e.EffVarNew / NULLIF(e.Mult, 0), 0) >= 1
                                  AND ABS(-e.EffVarNew - ROUND(-e.EffVarNew / NULLIF(e.Mult, 0), 0) * e.Mult) <= 0.10 * e.Mult
                                 THEN 1 ELSE 0 END)                        AS PackShort,
               /* S5 — an issue that is not a whole number of packs. */
               CONVERT(bit, CASE WHEN e.Mult >= 2 AND e.Weighed = 0 AND e.QtyIssued > @eps
                                  AND ABS(e.QtyIssued - ROUND(e.QtyIssued / NULLIF(e.Mult, 0), 0) * e.Mult) > @eps
                                 THEN 1 ELSE 0 END)                        AS IssueOffPack,
               /* What the HAND balancing already in PumpIT did to this shift,
                  as the pack audits it. Staff basis: original counts with the
                  opening repaired to the original previous closing, and a
                  fat-finger issue (123456) neutralised as the pack does.
                  PumpIT as it stands, so the issue as KEYED there — the pack
                  knows nothing of a duplicate Agora has not yet removed. */
               CONVERT(decimal(18,4), CASE WHEN e.PrevCloseOrig IS NULL THEN 0
                                           ELSE e.QtyOpenOrig - e.PrevCloseOrig END) AS BreakOrig,
               CONVERT(bit, CASE WHEN e.QtyIssuedOrig > 20
                                  AND e.QtyIssuedOrig > 5 * CASE WHEN e.QtyIssuedKeyed > 1 THEN e.QtyIssuedKeyed ELSE 1 END
                                 THEN 1 ELSE 0 END)                        AS MisKeyed
        FROM effective e
    ),
    audited AS
    (
        SELECT r.*,
               CONVERT(decimal(18,4),
                   r.QtyPOSOrig - (r.QtyOpenOrig
                                   + CASE WHEN r.MisKeyed = 1 THEN r.QtyIssuedKeyed ELSE r.QtyIssuedOrig END
                                   - r.QtyCloseOrig) + r.BreakOrig)        AS StaffVar,
               CONVERT(decimal(18,4),
                   r.QtyPOS - (r.QtyOpenNow + r.QtyIssuedKeyed - r.QtyCloseNow)) AS HandVar
        FROM ranked r
    )
    SELECT a.*,
           CASE
               /* A break no bigger than @KeepBreaksUpTo is not one the chain
                  stops at, so neither of these reads it. */
               WHEN ABS(a.BreakOrig) > @split AND ABS(a.QtyOpenNow - a.PrevCloseNow) > @tol   THEN 'S13'
               WHEN ABS(a.BreakOrig) > @split AND ABS(a.PrevCloseNow - a.PrevCloseOrig) > @tol THEN 'S12'
               WHEN a.StaffVar > 0.5 AND a.HandVar < -0.5                                    THEN 'S7'
               WHEN a.StaffVar < 0   AND a.HandVar < a.StaffVar - 0.5                        THEN 'S8'
               WHEN a.IsLastInWindow = 1 AND @AtDataEnd = 1
                    AND ABS(a.QtyCloseNow - a.QtyCloseOrig) > @tol                           THEN 'S11'
               WHEN ABS(a.QtyIssuedKeyed - a.QtyIssuedOrig) > @tol AND a.MisKeyed = 1         THEN 'S4'
               WHEN a.QtyIssuedKeyed > a.QtyIssuedOrig + @tol                                THEN 'S4a'
               WHEN a.QtyIssuedKeyed < a.QtyIssuedOrig - @tol                                THEN 'S4b'
           END                                                              AS AuditCode,
           CONVERT(bit, 0)                                                  AS RestoresHand,
           CONVERT(bit, 0)                                                  AS FlagAuditConflict,
           /* The commit writes this row's issue to 0 — a clear duplicate. Set
              by stage 7f. */
           CONVERT(bit, 0)                                                  AS IssueWrite
    INTO #apply
    FROM audited a;

    /* ------------------------------------------------------------- stage 7d
       A HAND AMENDMENT THE CLIENT'S RULES FORBID IS REVERSED, even on a chain
       Agora will not balance.

       The pack says what to do with each: S7 and S8 "reverse the amendment",
       S11 "restore the latest count", S12 "restore the closing; amend the
       opening instead", S13 "fix the opening". A balanceable chain gets that
       for free — its answer is compared with PumpIT and every difference is
       written. A blocked chain carried no proposal at all, so the breach stayed
       in PumpIT: on Engen Bethlehem, 1–21 Sep, 190 of 255 chains.

       So a blocked chain that carries one of those five is put back to the
       STAFF COUNTS, every opening repaired to the previous closing — the
       pack's own staff basis, which by construction trips none of its checks —
       and is NOT balanced: the reason it is blocked still stands and it stays
       on the report. S4, S4a and S4b are not here: an issue captured at
       balancing is a missing issue captured, which the pack accepts. */
    ;WITH breached AS (
        SELECT AreaNo, StockItemNo
        FROM #apply
        WHERE AuditCode IN ('S7', 'S8', 'S11', 'S12', 'S13')
        GROUP BY AreaNo, StockItemNo
    )
    UPDATE a SET
        a.RestoresHand = 1,
        a.EffAmend     = 0,
        a.EffOpenNew   = a.OpenRep,
        a.EffCloseNew  = a.QtyClose,
        a.EffVarNew    = a.QtyVarRep,
        a.RowMoves     = CASE WHEN ABS(a.OpenRep  - a.QtyOpenNow)  > @tol
                                OR ABS(a.QtyClose - a.QtyCloseNow) > @tol THEN 1 ELSE 0 END
    FROM #apply a
    JOIN breached b ON b.AreaNo = a.AreaNo AND b.StockItemNo = a.StockItemNo
    WHERE a.ChainBlocked = 1;

    /* ------------------------------------------------------------- stage 7e
       AGORA NEVER HANDS THE CLIENT A NEW AUDIT FLAG.

       The pack audits PumpIT every week, Agora's writes included. So every
       chain about to be written — balanced, or put back by 7d — is audited as
       it WOULD stand after the commit, the pack's way: S13 an opening that
       does not meet the previous closing, S12 a previous closing moved at a
       break, S7/S8 the variance against the staff basis, S11 the latest count.
       Where the written state trips a check the row does not trip today — any
       check, even on a row that trips a different one now — the whole chain
       is withdrawn: blocked, reported, nothing written.

       On real data (Engen Bethlehem, Sep 2026) every such row had an ISSUE
       changed at balancing and a count moved to compensate. Agora writes
       counts — and an issue only where it was keyed twice (D1, stage 2b) —
       and with the issue edit in place no set of counts satisfies every check
       at once — so the honest answer is to leave it for a person, not to
       trade one flag for another.

       "After" reads the BASIS issue, which is 0 on a clear duplicate: a chain
       written here writes that issue with it (stage 7f). "Now" reads the issue
       as keyed. */
    ;WITH post AS (
        SELECT a.AreaNo, a.StockItemNo, a.TransactionDate, a.ShiftNo,
               LAG(a.EffCloseNew, 1, a.PrevCloseNow) OVER (
                   PARTITION BY a.AreaNo, a.StockItemNo
                   ORDER BY a.TransactionDate, a.ShiftNo)                   AS PrevCloseP,
               a.EffOpenNew, a.EffCloseNew,
               CONVERT(decimal(18,4), a.QtyPOS - (a.EffOpenNew + a.QtyIssued - a.EffCloseNew)) AS VarP,
               a.StaffVar, a.HandVar, a.BreakOrig, a.PrevCloseOrig, a.PrevCloseNow,
               a.QtyOpenNow, a.QtyCloseNow, a.QtyCloseOrig, a.IsLastInWindow
        FROM #apply a
        WHERE a.ChainBlocked = 0 OR a.RestoresHand = 1
    ),
    /* Check by check, not "any flag": trading the S11 a shift has today for an
       S7 it does not have is still handing the client a new flag. */
    judged AS (
        SELECT p.*,
               /* @split, not @tol, for the reason the audit above uses it: a
                  closing that moves at a KEPT break is the chain balancing
                  through it, which is the whole point of keeping it. */
               CASE WHEN ABS(p.BreakOrig) > @split AND ABS(p.EffOpenNew - p.PrevCloseP)     > @tol THEN 1 ELSE 0 END AS S13After,
               CASE WHEN ABS(p.BreakOrig) > @split AND ABS(p.QtyOpenNow - p.PrevCloseNow)   > @tol THEN 1 ELSE 0 END AS S13Now,
               CASE WHEN ABS(p.BreakOrig) > @split AND ABS(p.PrevCloseP - p.PrevCloseOrig)  > @tol THEN 1 ELSE 0 END AS S12After,
               CASE WHEN ABS(p.BreakOrig) > @split AND ABS(p.PrevCloseNow - p.PrevCloseOrig) > @tol THEN 1 ELSE 0 END AS S12Now,
               CASE WHEN p.StaffVar > 0.5 AND p.VarP    < -0.5                 THEN 1 ELSE 0 END AS S7After,
               CASE WHEN p.StaffVar > 0.5 AND p.HandVar < -0.5                 THEN 1 ELSE 0 END AS S7Now,
               CASE WHEN p.StaffVar < 0   AND p.VarP    < p.StaffVar - 0.5     THEN 1 ELSE 0 END AS S8After,
               CASE WHEN p.StaffVar < 0   AND p.HandVar < p.StaffVar - 0.5     THEN 1 ELSE 0 END AS S8Now,
               CASE WHEN p.IsLastInWindow = 1 AND @AtDataEnd = 1
                         AND ABS(p.EffCloseNew - p.QtyCloseOrig) > @tol        THEN 1 ELSE 0 END AS S11After,
               CASE WHEN p.IsLastInWindow = 1 AND @AtDataEnd = 1
                         AND ABS(p.QtyCloseNow - p.QtyCloseOrig) > @tol        THEN 1 ELSE 0 END AS S11Now
        FROM post p
    )
    UPDATE a SET a.FlagAuditConflict = 1
    FROM #apply a
    JOIN judged j
      ON j.AreaNo = a.AreaNo AND j.StockItemNo = a.StockItemNo
     AND j.TransactionDate = a.TransactionDate AND j.ShiftNo = a.ShiftNo
    WHERE (j.S13After = 1 AND j.S13Now = 0) OR (j.S12After = 1 AND j.S12Now = 0)
       OR (j.S7After  = 1 AND j.S7Now  = 0) OR (j.S8After  = 1 AND j.S8Now  = 0)
       OR (j.S11After = 1 AND j.S11Now = 0);

    ;WITH conflicted AS (
        SELECT AreaNo, StockItemNo FROM #apply WHERE FlagAuditConflict = 1 GROUP BY AreaNo, StockItemNo
    )
    UPDATE a SET
        a.ChainBlocked = 1,
        a.RestoresHand = 0,
        a.RowMoves     = 0,
        a.EffAmend     = 0,
        a.EffOpenNew   = a.QtyOpen,
        a.EffCloseNew  = a.QtyClose,
        a.EffVarNew    = a.QtyVar
    FROM #apply a
    JOIN conflicted c ON c.AreaNo = a.AreaNo AND c.StockItemNo = a.StockItemNo;

    /* ------------------------------------------------------------- stage 7f
       WHERE A CLEAR DUPLICATE IS WRITTEN.

       On a chain the commit writes — balanced, or put back to the staff counts
       by 7d — always: those counts were worked out with the issue gone, and
       7e audited the chain with it gone.

       On a chain it does NOT write, the duplicate is still a duplicate — it is
       not balancing, it is an issue that was keyed twice — so it is written on
       its own, the counts untouched. But only where PumpIT holds that shift
       short by exactly the issue TODAY, so the write leaves it at nil and
       nothing else moves. Where somebody has already moved the counts to hide
       the short, taking the issue out alone would turn the shift into an over;
       that one is reported, not written. */
    UPDATE a SET
        a.IssueWrite = 1,
        a.EffVarNew  = CASE WHEN a.ChainBlocked = 1 AND a.RestoresHand = 0
                            THEN a.QtyVar + a.IssueCut ELSE a.EffVarNew END
    FROM #apply a
    WHERE a.IssueCut > @eps
      AND (   a.ChainBlocked = 0
           OR a.RestoresHand = 1
           OR ABS(a.HandVar + a.IssueCut) <= a.DupTol);

    /* Not written, so the row stands as keyed: its issue is there, and a
       shift with an issue is not dormant whatever the basis said. */
    UPDATE #apply
       SET DupReason = N'Not removed: PumpIT no longer holds this short and the chain is blocked',
           IsDormant = 0
    WHERE IssueCut > @eps AND IssueWrite = 0;

    /* And the chain's count of shifts left short (L1) follows what the row
       now ends as — on a blocked chain only, where 7f is what decided it. */
    ;WITH touched AS (
        SELECT DISTINCT AreaNo, StockItemNo
        FROM #apply
        WHERE IssueCut > @eps AND ChainBlocked = 1 AND RestoresHand = 0
    ),
    shorts AS (
        SELECT a.AreaNo, a.StockItemNo,
               SUM(CASE WHEN a.IsDormant = 0 AND a.EffVarNew < -@eps THEN 1 ELSE 0 END) AS n
        FROM #apply a
        JOIN touched t ON t.AreaNo = a.AreaNo AND t.StockItemNo = a.StockItemNo
        GROUP BY a.AreaNo, a.StockItemNo
    )
    UPDATE a SET a.ShortShiftsAfter = s.n
    FROM #apply a
    JOIN shorts s ON s.AreaNo = a.AreaNo AND s.StockItemNo = a.StockItemNo;

    /* ------------------------------------------------------------- stage 7c
       The labels, resolved ONCE. Stored on the line for the reason SellPrice
       is: a run is a record of what was true when it was made. The employee is
       a LIST — a short on a shift two people worked cannot be attributed to
       either — and agora.vw_StockReconShiftEmployee is the only place that
       says who was on a shift (v1__14b). */
    IF OBJECT_ID('tempdb..#emp') IS NOT NULL DROP TABLE #emp;

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
      AND se.TransactionDate <  DATEADD(day, 1, @CompleteTo);

    /* ------------------------------------------------------------- stage 8
       Record it. One INSERT, ordered so the line numbers read as a chain.
       WouldAmend is 1 only where the row's answer differs from PumpIT on a
       chain that is clear — or on a blocked one being put back to the staff
       counts (stage 7d) — and writing it trips no audit check that is not
       tripped today (7e); or where a clear duplicate issue is written (7f).
       CountsMove says which of the two moves the counts; there is no other
       path from "blocked" to "written". */
    INSERT INTO agora.StockReconRunLine (
        BranchId, RunId, [LineNo], AreaNo, StockItemNo, TransactionDate, ShiftNo, SellPrice,
        QtyOpen, QtyIssued, QtyClose, QtyPOS, QtyVar,
        QtyOpenNew, QtyCloseNew, QtyVarNew, AmendClose,
        IsDormant, ActiveSeq, ActiveLen, ChainNetVar,
        FlagNetOver, FlagSoldMoreThanOnHand, FlagCloseExceedsOnHand, FlagIssueWentNowhere,
        FlagBigAmendment, FlagPctAmendment, FlagNegativeClose, FlagShortChain,
        FlagDormantMoved, FlagChainBroken, ChainBlocked,
        PrevClose, ChainBreak, QtyOpenNow, QtyCloseNow, QtyIssuedOrig, IssueMultiple,
        FlagPackOver, FlagNoPosLink, RestoresHand, FlagAuditConflict, AuditCode, BreakKept,
        DupTier, DupTwinDate, DupTwinShift, DupTwinVar, DupQty, DupReason, QtyIssuedNew, CountsMove,
        ExceptionCode, Outcome, WouldAmend, Selected, CommitState,
        ItemDescription, POSCode, StockLocation, AreaDescription,
        EmployeeCodes, EmployeeNames, EmployeeCount,
        CreatedAt, CreatedBy)
    SELECT
        @BranchId,
        @RunId,
        ROW_NUMBER() OVER (ORDER BY a.AreaNo, a.StockItemNo, a.TransactionDate, a.ShiftNo),
        a.AreaNo, a.StockItemNo, a.TransactionDate, a.ShiftNo, a.SellPrice,
        /* The issue as keyed: what the shift was given on paper. A removed
           duplicate shows in QtyIssuedNew. */
        a.QtyOpen, a.QtyIssuedKeyed, a.QtyClose, a.QtyPOS, a.QtyVar,
        a.EffOpenNew, a.EffCloseNew, a.EffVarNew, a.EffAmend,
        a.IsDormant, a.ActiveSeq, a.ActiveLen, a.ChainNetVar,
        a.FlagNetOver, a.FlagSoldMoreThanOnHand, a.FlagCloseExceedsOnHand, a.FlagIssueWentNowhere,
        a.FlagBigAmendment, a.FlagPctAmendment, a.FlagNegativeClose, a.FlagShortChain,
        a.FlagDormantMoved, a.LinkBroken, a.ChainBlocked,
        a.PrevClose, a.ChainBreak, a.QtyOpenNow, a.QtyCloseNow, a.QtyIssuedOrig, NULLIF(a.Mult, 0),
        a.FlagPackOver, a.FlagNoPosLink, a.RestoresHand, a.FlagAuditConflict, a.AuditCode, a.BreakKept,
        a.DupTier, a.DupTwinDate, a.DupTwinShift, a.DupTwinVar, a.DupQty, a.DupReason,
        CASE WHEN a.IssueWrite = 1 THEN CONVERT(decimal(18,4), 0) END,
        a.RowMoves,

        /* The class, ranked most serious first; a line is reported under the
           first test it trips. Codes are the pack's where the pack defines the
           test (S10a, S10b, S1, S3, S5, E1) and letters it does not use where
           the test is ours (U, F, N, L, D) — see config('stockrecon.exceptions').
           D2 and D1 sit under the row-level unrecorded issues and above the
           chain-wide classes: they are this shift's own explanation of its
           short, and a chain that ends over would otherwise hide every one. */
        CASE
            WHEN a.ChainBreak < -@tol                                                     THEN 'S10a'
            WHEN a.FlagNoPosLink = 1                                                      THEN 'E1'
            WHEN a.FlagPackOver = 1                                                       THEN 'S1'
            WHEN a.FlagSoldMoreThanOnHand = 1                                             THEN 'U1'
            WHEN a.FlagCloseExceedsOnHand = 1                                             THEN 'U2'
            WHEN a.DupTier = 'D2'                                                         THEN 'D2'
            WHEN a.DupTier = 'D1'                                                         THEN 'D1'
            WHEN a.FlagNetOver = 1 AND a.ChainIssues < @eps AND a.ChainPOS > @eps          THEN 'U3'
            WHEN a.FlagNetOver = 1                                                        THEN 'U4'
            WHEN a.ChainBreak > @tol                                                      THEN 'S10b'
            WHEN a.QtyOpen < -@eps OR a.QtyClose < -@eps
                 OR a.FlagNegativeClose = 1 OR a.FlagBigAmendment = 1
                 OR a.FlagPctAmendment = 1                                                THEN 'F1'
            WHEN a.FlagIssueWentNowhere = 1                                               THEN 'F2'
            WHEN a.FlagAuditConflict = 1                                                  THEN 'F3'
            WHEN a.PackShort = 1                                                          THEN 'S3'
            WHEN a.IssueOffPack = 1                                                       THEN 'S5'
            WHEN a.NoCountShifts * 2 > a.ActiveLen AND a.ActiveLen > 0                     THEN 'N1'
            WHEN a.ActiveLen * 2 < a.ChainShifts AND a.ChainShifts >= 6                    THEN 'N2'
            WHEN a.ShortShiftsAfter * 2 > a.ActiveLen AND a.ActiveLen >= 6                 THEN 'L1'
            ELSE NULL
        END,

        /* Plain language, "Label: explanation" — the screen shows the label and
           hovers the rest (StockReconRunLine::outcomeLabel()). What blocks the
           chain first, then what happens to this row. */
        CASE
            /* This shift's own news first: the issue goes, whatever else the
               chain does. "(D1)" is what the audit trail's reason reads. */
            WHEN a.IssueWrite = 1             THEN 'Duplicate issue removed (D1): captured twice, the issue goes to nil'
            WHEN a.RestoresHand = 1 AND a.RowMoves = 1
                                              THEN 'Hand amendment reversed: back to the count, the chain is not balanced'
            WHEN a.FlagPackOver = 1           THEN 'Missed issue sheet: an over of a whole pack, captured not balanced'
            WHEN a.FlagNoPosLink = 1          THEN 'No till sales linked: stock falls, the till shows nothing, charge nobody'
            WHEN a.FlagSoldMoreThanOnHand = 1 THEN 'Unrecorded issue, proven: the till sold more than the shift held'
            WHEN a.FlagCloseExceedsOnHand = 1 THEN 'Unrecorded issue: closed holding more than it ever received'
            WHEN a.FlagNetOver = 1 AND a.ChainIssues < @eps AND a.ChainPOS > @eps
                                              THEN 'Unrecorded issue: selling all window with no issues captured'
            WHEN a.FlagNetOver = 1            THEN 'Unrecorded issue: net over for the window, cannot be balanced'
            WHEN a.QtyOpen < -@eps OR a.QtyClose < -@eps
                                              THEN 'Data fault: a count below zero'
            WHEN a.FlagNegativeClose = 1      THEN 'Data fault: balancing would drive the closing below zero'
            WHEN a.FlagBigAmendment = 1 OR a.FlagPctAmendment = 1
                                              THEN 'Implausible amendment: the correction is too large to be a miscount'
            /* Before F2: a possible duplicate IS an issue that went nowhere,
               and this says what to do about it. */
            WHEN a.DupTier = 'D2' AND a.EffVarNew < -@eps
                                              THEN 'Possible duplicate issue (D2): check the issue book before charging'
            WHEN a.FlagIssueWentNowhere = 1   THEN 'Issue went nowhere: issued, nothing sold, count unchanged'
            WHEN a.FlagAuditConflict = 1      THEN 'Audit conflict: an issue changed at balancing, counts cannot fix it'
            WHEN a.FlagShortChain = 1         THEN 'Chain too short: not enough active shifts in the window'
            WHEN a.ChainBlocked = 1           THEN 'Held back: another shift on this item blocks the chain'
            /* The qty leads, so it is on the pill and in the extract. At most
               74 characters against the column's 80. */
            WHEN a.BreakKept = 1              THEN N'Break ' + FORMAT(a.ChainBreak, '+0.###;-0.###')
                                                   + N' kept: opening set to the previous closing, chain runs through'
            WHEN a.ChainBreak < -@tol         THEN 'Opening repaired: raised to the previous closing, this shift carries it'
            WHEN a.ChainBreak >  @tol         THEN 'Opening repaired: lowered to the previous closing'
            WHEN a.IsDormant = 1              THEN 'Dormant: opening and closing held equal, no stock moved'
            /* The residual short leads, whether or not this row was touched:
               it is the figure a branch is answerable for. */
            WHEN a.EffVarNew < -@eps AND a.RowMoves = 1
                                              THEN 'Balanced, short remains: accountable'
            WHEN a.EffVarNew < -@eps          THEN 'Short: accountable, no amendment available'
            WHEN ABS(a.EffAmend) > @eps       THEN 'Balanced to zero'
            WHEN a.RowMoves = 1 AND ABS(a.EffOpenNew - a.QtyOpen) < @tol
                                              THEN 'Restored: a hand amendment in PumpIT goes back to the count'
            WHEN a.RowMoves = 1               THEN 'Opening only: follows the amended closing before it'
            ELSE 'No change needed'
        END,

        CONVERT(bit, CASE WHEN a.RowMoves = 1 OR a.IssueWrite = 1 THEN 1 ELSE 0 END),
        CONVERT(bit, CASE WHEN a.RowMoves = 1 OR a.IssueWrite = 1 THEN 1 ELSE 0 END),
        'pending',

        m.StockItemDescription,
        m.POSCode,
        m.StockLocation,
        ar.AreaDescription,
        emp.EmployeeCodes,
        emp.EmployeeNames,
        ISNULL(emp.EmployeeCount, 0),

        SYSDATETIME(), @UserId
    FROM #apply a
    /* OUTER APPLY, NOT A JOIN: STK_StockMaster is keyed by branch AND
       Location, and a join on the item alone multiplies the shift ON AN
       INSERT (v1__14d). Same product either way, so TOP 1 is safe. */
    OUTER APPLY (SELECT TOP 1 m.StockItemDescription, m.POSCode, m.StockLocation
                   FROM agora.vw_StockMaster m
                  WHERE m.BranchId = @BranchId AND m.StockItemNo = a.StockItemNo
                  ORDER BY CASE WHEN m.AreaNo = a.AreaNo THEN 0 ELSE 1 END, m.StockLocation) m
    LEFT JOIN agora.vw_StockArea ar
           ON ar.BranchId = @BranchId AND ar.AreaNo = a.AreaNo
    LEFT JOIN #emp emp
           ON emp.TransactionDate = a.TransactionDate
          AND emp.ShiftNo = a.ShiftNo
          AND emp.AreaNo  = a.AreaNo;

    /* ------------------------------------------------------------- stage 9
       The header, from the same pass. Every "after" figure is the EFFECTIVE
       one. Every aggregate is ISNULL'd: SUM() over an empty window is NULL,
       and an empty window is an ordinary answer. */
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
        r.CompleteTo        = @CompleteTo,
        r.HeldBackRows      = @HeldBackRows,
        r.RepairedOpenings  = t.RepairedOpenings,
        r.KeptBreaks        = t.KeptBreaks,
        r.DuplicatesClear   = t.DuplicatesClear,
        r.DuplicatesPossible = t.DuplicatesPossible,
        r.AuditRows         = t.AuditRows,
        r.UpdatedAt         = SYSDATETIME(),
        r.UpdatedBy         = @UserId
    FROM agora.StockReconRun r
    CROSS JOIN (
        SELECT
            COUNT(*)                                                                    AS TotalRows,
            ISNULL(SUM(CASE WHEN IsDormant = 1 THEN 1 ELSE 0 END), 0)                   AS DormantRows,
            ISNULL(SUM(CASE WHEN RowMoves = 1 OR IssueWrite = 1 THEN 1 ELSE 0 END), 0)   AS AmendedRows,
            ISNULL(SUM(CASE WHEN DupTier = 'D1' THEN 1 ELSE 0 END), 0)                  AS DuplicatesClear,
            ISNULL(SUM(CASE WHEN DupTier = 'D2' THEN 1 ELSE 0 END), 0)                  AS DuplicatesPossible,
            ISNULL(SUM(CASE WHEN QtyVar    >  @eps THEN 1 ELSE 0 END), 0)               AS OverRowsBefore,
            ISNULL(SUM(CASE WHEN EffVarNew >  @eps THEN 1 ELSE 0 END), 0)               AS OverRowsAfter,
            ISNULL(SUM(CASE WHEN QtyVar    < -@eps THEN 1 ELSE 0 END), 0)               AS ShortRowsBefore,
            ISNULL(SUM(CASE WHEN EffVarNew < -@eps THEN 1 ELSE 0 END), 0)               AS ShortRowsAfter,
            CONVERT(decimal(18,3), ISNULL(SUM(CASE WHEN QtyVar    >  @eps THEN  QtyVar    ELSE 0 END), 0)) AS OverUnitsBefore,
            CONVERT(decimal(18,3), ISNULL(SUM(CASE WHEN EffVarNew >  @eps THEN  EffVarNew ELSE 0 END), 0)) AS OverUnitsAfter,
            CONVERT(decimal(18,3), ISNULL(SUM(CASE WHEN QtyVar    < -@eps THEN -QtyVar    ELSE 0 END), 0)) AS ShortUnitsBefore,
            CONVERT(decimal(18,3), ISNULL(SUM(CASE WHEN EffVarNew < -@eps THEN -EffVarNew ELSE 0 END), 0)) AS ShortUnitsAfter,
            CONVERT(decimal(18,3), ISNULL(SUM(ABS(EffAmend)), 0))                       AS UnitsAmended,
            CONVERT(decimal(18,2), ISNULL(SUM(CASE WHEN EffVarNew < -@eps THEN -EffVarNew * SellPrice ELSE 0 END), 0)) AS ShortValueAfter,
            ISNULL(SUM(CASE WHEN LinkBroken = 1 AND (ChainBlocked = 0 OR RestoresHand = 1) THEN 1 ELSE 0 END), 0) AS RepairedOpenings,
            ISNULL(SUM(CASE WHEN AuditCode IS NOT NULL THEN 1 ELSE 0 END), 0)           AS AuditRows,
            /* Of the repaired openings above — the same rows — the ones the
               chain ran through. */
            ISNULL(SUM(CASE WHEN BreakKept = 1 AND (ChainBlocked = 0 OR RestoresHand = 1) THEN 1 ELSE 0 END), 0) AS KeptBreaks
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
    DROP TABLE #agg;
    DROP TABLE #gate;
    DROP TABLE #rep;
    DROP TABLE #item;
    DROP TABLE #pred;
    DROP TABLE #chain;

    SELECT CONVERT(bit, 1)                      AS Ok,
           'PREVIEWED'                          AS Code,
           'The balancing was computed and recorded. Nothing was written to PumpIT.'
             + CASE WHEN @HeldBackRows > 0
                    THEN ' ' + CONVERT(nvarchar(12), @HeldBackRows) + ' shift line(s) from '
                         + CONVERT(nvarchar(10), DATEADD(day, 1, @CompleteTo), 23)
                         + ' held back: the recon is not complete on that day.'
                    ELSE '' END                 AS [Message],
           CONVERT(bigint, @RunId)              AS Id;
END
