/* ============================================================================
   agora.usp_Cash_ProposeZreadAllocation

   WHAT IT IS FOR
   A Z-read arrives from the till with a terminal and an operator ID and
   nothing else. This proposes the rest — which till, which shift, which
   person — from what the SAME SITE has done before, and says how sure it is
   and why. Agora proposes; a person disposes.

   WHO READS IT
   The ZREAD Allocation screen (through agora.usp_Cash_GridZread), the row
   panel behind each Z-read, and agora.usp_Cash_AutoAllocate, which stages the
   proposals at or above the auto-accept threshold. It writes nothing.

   ONE ROW PER Z-READ IN SCOPE (one site, one period, optionally one POS
   location), whether or not it is allocated: an allocated row still gets a
   proposal, so "what would the system have said" can be read beside "what the
   branch did" — which is what @Replay is for.

   THE HISTORY
   Every Z-read at the site in the @HistoryDays before @DateFrom and up to the
   end of the period, whose EFFECTIVE allocation is complete (agora.vw_CashZread
   states that rule once: Agora's allocation over the legacy row's). Keyed by
   Location as well as operator, because operator 4 on ARCH and operator 4 on
   AURA are different people.

   @Replay = 1 is the blind test: the history stops the day BEFORE @DateFrom,
   so nothing in the period can teach the proposer about itself, and every row
   in the period is proposed as though nobody had allocated it. HistoryMatch
   then says whether the proposal agrees with what the branch actually did:
   match (till, shift and person all agree), differs (a proposed part does
   not), incomplete (what was proposed agrees, but the shift or till was left
   to a person), none (no person proposed). NULL on a Z-read nobody allocated.

   THE PRIORS (plan T036), in order, first one that holds wins:

     certain  this terminal + this operator ID has carried the SAME till, shift
              and person on >= @CertainShare of its prior Z-reads (0.75).
              The whole allocation is proposed, and the certainty is about all
              three — not just the person.
     likely   this operator ID has been the same PERSON on >= @LikelyShare of
              its prior Z-reads at this location (0.60). The till is the one
              this terminal usually is; the shift is proposed only when...
     (shift)  ...this operator ID has run the same shift on >= @ShiftShare of
              its prior Z-reads (0.60), or, failing that...
     (5th)    ...THE TWO-A-DAY RULE (Ryan, 27 Sep 2026): the terminal printed
              exactly two Z-reads that day, so the first by log file is the Day
              shift (@DayShiftNo) and the second the Night (@NightShiftNo) —
              at every location except @SequenceExcluded (ARCH, whose LOGFILE is
              a per-operator counter rather than a sequence). A fallback: it
              never overrides the operator's own history, never touches a
              'certain' proposal, and says in ShiftEvidence that it was used.
              Otherwise the shift is left for a person.
     review   no settled person for the operator ID, but its shift is known
              (from either rule above) and somebody usually works this
              terminal on that shift.
     manual   nothing to go on. Returned as a first-class answer, with what
              little is known (the till, perhaps the shift), because an
              operator ID the site has never allocated is itself a finding.

   Ties inside a prior go to the most recent, then to the lowest code, so the
   same history always gives the same answer.

   ShiftSource says where the proposed shift came from: 'pattern' (the certain
   allocation), 'operator' (its shift history), 'sequence' (the two-a-day
   rule), or NULL (left to a person).

   ChangedInPumpIt is 1 when Agora's allocation was stamped onto PumpIT and
   somebody has allocated the Z-read differently on the PumpIT screen since —
   the later allocation is the one this reports (agora.vw_CashZread).

   IsComplete says whether the proposal names all three. Only a complete
   proposal can be staged by Auto-allocate or accepted as it stands.

   Reads:  agora.vw_CashZread, agora.ZreadAllocation (staged rows),
           agora.vw_Till, agora.vw_Shift, agora.vw_Employee — every label by
           OUTER APPLY TOP 1, so a lookup can never change a row count.
   Writes: nothing.

   Refusals:
     AGORA:CASH_ZREAD_SITE    no site
     AGORA:CASH_ZREAD_RANGE   no dates, the wrong way round, or more than 93 days
     AGORA:CASH_ZREAD_PRIORS  a share outside (0, 1], or history outside 7..730 days

     EXEC agora.usp_Cash_ProposeZreadAllocation @BranchId = 9,
          @DateFrom = '2026-08-21', @DateTo = '2026-08-31', @Replay = 1;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_ProposeZreadAllocation]
    @BranchId      INT,
    @DateFrom      DATE,
    @DateTo        DATE,
    @Location      NVARCHAR(10)  = NULL,
    @Replay        BIT           = 0,
    @CertainShare  DECIMAL(5,4)  = 0.75,
    @LikelyShare   DECIMAL(5,4)  = 0.60,
    @ShiftShare    DECIMAL(5,4)  = 0.60,
    @HistoryDays   INT           = 90,
    @DayShiftNo    INT           = 1,
    @NightShiftNo  INT           = 3,
    @SequenceExcluded NVARCHAR(200) = 'ARCH'
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @BranchId IS NULL
        THROW 51000, 'AGORA:CASH_ZREAD_SITE:Choose a site. A Z-read is allocated from what that site has done before.', 1;

    IF @DateFrom IS NULL OR @DateTo IS NULL OR @DateTo < @DateFrom OR DATEDIFF(day, @DateFrom, @DateTo) > 92
        THROW 51000, 'AGORA:CASH_ZREAD_RANGE:Give a period of up to 93 days, from before to.', 1;

    IF @CertainShare NOT BETWEEN 0.0001 AND 1 OR @LikelyShare NOT BETWEEN 0.0001 AND 1
       OR @ShiftShare NOT BETWEEN 0.0001 AND 1 OR @HistoryDays NOT BETWEEN 7 AND 730
        THROW 51000, 'AGORA:CASH_ZREAD_PRIORS:Each share must be above 0 and at most 1, and the history 7 to 730 days.', 1;

    SET @Location = NULLIF(LTRIM(RTRIM(@Location)), '');

    DECLARE @HistFrom DATE = DATEADD(day, -@HistoryDays, @DateFrom);
    DECLARE @HistTo   DATE = CASE WHEN @Replay = 1 THEN @DateFrom ELSE DATEADD(day, 1, @DateTo) END;
    DECLARE @PeriodTo DATE = DATEADD(day, 1, @DateTo);

    /* The two-a-day rule needs both shifts to exist at the site, and it is off
       for the excluded locations. Empty-string entries are dropped, the same
       STRING_SPLIT trap every grid procedure guards against. */
    DECLARE @NoSequence TABLE (Location NVARCHAR(10) PRIMARY KEY);
    INSERT INTO @NoSequence (Location)
    SELECT DISTINCT LTRIM(RTRIM(v.value)) FROM STRING_SPLIT(ISNULL(@SequenceExcluded, ''), ',') v
    WHERE LTRIM(RTRIM(v.value)) <> '';

    DECLARE @DayName NVARCHAR(100) = (SELECT TOP 1 ShiftDescription FROM agora.vw_Shift
                                      WHERE BranchId = @BranchId AND ShiftNo = @DayShiftNo ORDER BY ShiftDescription);
    DECLARE @NightName NVARCHAR(100) = (SELECT TOP 1 ShiftDescription FROM agora.vw_Shift
                                        WHERE BranchId = @BranchId AND ShiftNo = @NightShiftNo ORDER BY ShiftDescription);
    DECLARE @SequenceOn BIT = CASE WHEN @DayName IS NOT NULL AND @NightName IS NOT NULL THEN 1 ELSE 0 END;

    /* The site's Z-reads over history and period, read once. ZReadAt is the
       source's own DATETIME, so this range seeks the clustered key. */
    SELECT z.BranchId, z.ZReadDate, z.Location, z.EodCounter, z.TerminalNo, z.LogFile, z.PosUserId,
           z.Sales, z.LegacyTillNo, z.LegacyShiftNo, z.LegacyEmployeeCode,
           z.AllocationId, z.TillNo, z.ShiftNo, z.EmployeeCode, z.AllocatedBy, z.IsAllocated, z.IsPartlyAllocated,
           z.ChangedInPumpItAfterAgora,
           /* Where this reading sits in its terminal's day, by log file —
              numerically, because '9999' sorts after '10000' as text. The
              range covers whole days, so every Z-read of a day is here. */
           ROW_NUMBER() OVER (PARTITION BY z.ZReadDate, z.Location, z.TerminalNo
                              ORDER BY TRY_CONVERT(BIGINT, z.LogFile), z.LogFile) AS DaySeq,
           COUNT(*) OVER (PARTITION BY z.ZReadDate, z.Location, z.TerminalNo) AS DayCount,
           FIRST_VALUE(z.LogFile) OVER (PARTITION BY z.ZReadDate, z.Location, z.TerminalNo
                                        ORDER BY TRY_CONVERT(BIGINT, z.LogFile), z.LogFile) AS DayFirstLog,
           LAST_VALUE(z.LogFile) OVER (PARTITION BY z.ZReadDate, z.Location, z.TerminalNo
                                       ORDER BY TRY_CONVERT(BIGINT, z.LogFile), z.LogFile
                                       ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING) AS DayLastLog
    INTO #zp_z
    FROM agora.vw_CashZread z
    WHERE z.BranchId = @BranchId
      AND z.ZReadAt >= @HistFrom AND z.ZReadAt < @PeriodTo;

    /* What the priors learn from. */
    SELECT h.Location, h.TerminalNo, h.PosUserId, h.TillNo, h.ShiftNo, h.EmployeeCode, h.ZReadDate
    INTO #zp_h
    FROM #zp_z h
    WHERE h.IsAllocated = 1
      AND h.ZReadDate >= @HistFrom AND h.ZReadDate < @HistTo;

    /* certain — terminal + operator -> the whole allocation */
    SELECT Location, TerminalNo, PosUserId, TillNo, ShiftNo, EmployeeCode,
           COUNT(*) AS N,
           SUM(COUNT(*)) OVER (PARTITION BY Location, TerminalNo, PosUserId) AS Total,
           ROW_NUMBER() OVER (PARTITION BY Location, TerminalNo, PosUserId
                              ORDER BY COUNT(*) DESC, MAX(ZReadDate) DESC, EmployeeCode, ShiftNo, TillNo) AS Rn
    INTO #zp_k
    FROM #zp_h GROUP BY Location, TerminalNo, PosUserId, TillNo, ShiftNo, EmployeeCode;

    /* likely — operator -> person */
    SELECT Location, PosUserId, EmployeeCode,
           COUNT(*) AS N,
           SUM(COUNT(*)) OVER (PARTITION BY Location, PosUserId) AS Total,
           ROW_NUMBER() OVER (PARTITION BY Location, PosUserId
                              ORDER BY COUNT(*) DESC, MAX(ZReadDate) DESC, EmployeeCode) AS Rn
    INTO #zp_u
    FROM #zp_h GROUP BY Location, PosUserId, EmployeeCode;

    /* shift — operator -> shift */
    SELECT Location, PosUserId, ShiftNo,
           COUNT(*) AS N,
           SUM(COUNT(*)) OVER (PARTITION BY Location, PosUserId) AS Total,
           ROW_NUMBER() OVER (PARTITION BY Location, PosUserId
                              ORDER BY COUNT(*) DESC, MAX(ZReadDate) DESC, ShiftNo) AS Rn
    INTO #zp_s
    FROM #zp_h GROUP BY Location, PosUserId, ShiftNo;

    /* the till a terminal usually is */
    SELECT Location, TerminalNo, TillNo,
           COUNT(*) AS N,
           SUM(COUNT(*)) OVER (PARTITION BY Location, TerminalNo) AS Total,
           ROW_NUMBER() OVER (PARTITION BY Location, TerminalNo
                              ORDER BY COUNT(*) DESC, MAX(ZReadDate) DESC, TillNo) AS Rn
    INTO #zp_t
    FROM #zp_h GROUP BY Location, TerminalNo, TillNo;

    /* review — who usually works this terminal on that shift */
    SELECT Location, TerminalNo, ShiftNo, EmployeeCode,
           COUNT(*) AS N,
           SUM(COUNT(*)) OVER (PARTITION BY Location, TerminalNo, ShiftNo) AS Total,
           ROW_NUMBER() OVER (PARTITION BY Location, TerminalNo, ShiftNo
                              ORDER BY COUNT(*) DESC, MAX(ZReadDate) DESC, EmployeeCode) AS Rn
    INTO #zp_ts
    FROM #zp_h GROUP BY Location, TerminalNo, ShiftNo, EmployeeCode;

    /* The scope, with each prior's winner beside it. The shift a non-certain
       proposal carries is the operator's own when that is settled, else the
       two-a-day rule's (eShift); review looks for the usual person on it. */
    SELECT
        z.*,
        k.TillNo AS kTill, k.ShiftNo AS kShift, k.EmployeeCode AS kEmp, k.N AS kN, k.Total AS kTotal,
        u.EmployeeCode AS uEmp, u.N AS uN, u.Total AS uTotal,
        sh.sShift, sh.qShift, COALESCE(sh.sShift, sh.qShift) AS eShift,
        s.ShiftNo AS sTop, s.N AS sN, s.Total AS sTotal,
        t.TillNo AS tTill, t.N AS tN, t.Total AS tTotal,
        CONVERT(nvarchar(10), CASE
            WHEN k.N >= @CertainShare * k.Total THEN 'certain'
            WHEN u.N >= @LikelyShare  * u.Total THEN 'likely'
            WHEN COALESCE(sh.sShift, sh.qShift) IS NOT NULL AND ts.EmployeeCode IS NOT NULL THEN 'review'
            ELSE 'manual' END) AS Confidence,
        ts.EmployeeCode AS tsEmp, ts.N AS tsN, ts.Total AS tsTotal
    INTO #zp_p
    FROM #zp_z z
    LEFT JOIN #zp_k k ON k.Location = z.Location AND k.TerminalNo = z.TerminalNo AND k.PosUserId = z.PosUserId AND k.Rn = 1
    LEFT JOIN #zp_u u ON u.Location = z.Location AND u.PosUserId = z.PosUserId AND u.Rn = 1
    LEFT JOIN #zp_s s ON s.Location = z.Location AND s.PosUserId = z.PosUserId AND s.Rn = 1
    LEFT JOIN #zp_t t ON t.Location = z.Location AND t.TerminalNo = z.TerminalNo AND t.Rn = 1
    CROSS APPLY (
        SELECT
            CASE WHEN s.N >= @ShiftShare * s.Total THEN s.ShiftNo END AS sShift,
            CASE WHEN @SequenceOn = 1 AND z.DayCount = 2
                      AND NOT EXISTS (SELECT 1 FROM @NoSequence n WHERE n.Location = z.Location)
                 THEN CASE z.DaySeq WHEN 1 THEN @DayShiftNo ELSE @NightShiftNo END END AS qShift
    ) sh
    LEFT JOIN #zp_ts ts ON ts.Location = z.Location AND ts.TerminalNo = z.TerminalNo AND ts.Rn = 1
                       AND ts.ShiftNo = COALESCE(sh.sShift, sh.qShift)
    WHERE z.ZReadDate >= @DateFrom AND z.ZReadDate <= @DateTo
      AND (@Location IS NULL OR z.Location = @Location);

    SELECT
        p.BranchId,
        p.ZReadDate,
        p.Location,
        p.EodCounter,
        p.TerminalNo,
        p.LogFile,
        p.PosUserId,
        CONVERT(decimal(18,2), p.Sales) AS Sales,

        /* Where the Z-read stands. A staged proposal outranks an allocation for
           display, because it is what Update ZREAD is about to write. */
        CONVERT(nvarchar(10), CASE WHEN st.Id IS NOT NULL THEN 'staged'
                                   WHEN p.IsAllocated = 1 THEN 'allocated'
                                   ELSE 'open' END) AS [State],
        p.AllocatedBy,
        p.IsPartlyAllocated,
        p.AllocationId,

        /* What it carries now — or, when staged, what it will carry. */
        COALESCE(st.TillNo, p.TillNo)             AS TillNo,
        COALESCE(st.ShiftNo, p.ShiftNo)           AS ShiftNo,
        COALESCE(st.EmployeeCode, p.EmployeeCode) AS EmployeeCode,
        ct.TillDescription                        AS TillDescription,
        cs.ShiftDescription                       AS ShiftDescription,
        ce.EmployeeName                           AS EmployeeName,

        p.LegacyTillNo, p.LegacyShiftNo, p.LegacyEmployeeCode,
        st.Id AS StagedId, st.Source AS StagedSource, st.Confidence AS StagedConfidence,

        /* The proposal. */
        x.ProposedTillNo,
        x.ProposedShiftNo,
        x.ProposedEmployeeCode,
        pt.TillDescription   AS ProposedTillDescription,
        ps.ShiftDescription  AS ProposedShiftDescription,
        pe.EmployeeName      AS ProposedEmployeeName,
        p.Confidence,
        CONVERT(nvarchar(400), CASE p.Confidence
            WHEN 'certain' THEN
                'Terminal ' + p.TerminalNo + ', operator ID ' + p.PosUserId + ': '
                + ISNULL(pt.TillDescription, 'till ' + CONVERT(nvarchar(10), p.kTill)) + ' · '
                + ISNULL(ps.ShiftDescription, 'shift ' + CONVERT(nvarchar(10), p.kShift)) + ' · '
                + ISNULL(pe.EmployeeName, p.kEmp)
                + ' on ' + CONVERT(nvarchar(10), p.kN) + ' of ' + CONVERT(nvarchar(10), p.kTotal)
                + ' prior Z-reads (' + CONVERT(nvarchar(10), CONVERT(int, ROUND(100.0 * p.kN / p.kTotal, 0))) + '%).'
            WHEN 'likely' THEN
                'Operator ID ' + p.PosUserId + ' has been ' + ISNULL(pe.EmployeeName, p.uEmp)
                + ' on ' + CONVERT(nvarchar(10), p.uN) + ' of ' + CONVERT(nvarchar(10), p.uTotal)
                + ' prior Z-reads at ' + p.Location
                + ' (' + CONVERT(nvarchar(10), CONVERT(int, ROUND(100.0 * p.uN / p.uTotal, 0))) + '%).'
                + CASE WHEN p.tTill IS NOT NULL
                       THEN ' Terminal ' + p.TerminalNo + ' is usually ' + ISNULL(pt.TillDescription, 'till ' + CONVERT(nvarchar(10), p.tTill))
                            + ' (' + CONVERT(nvarchar(10), p.tN) + ' of ' + CONVERT(nvarchar(10), p.tTotal) + ').'
                       ELSE '' END
            WHEN 'review' THEN
                'Operator ID ' + p.PosUserId + ' has no settled person. The usual operator on terminal '
                + p.TerminalNo + ' for ' + ISNULL(ps.ShiftDescription, 'that shift') + ' is '
                + ISNULL(pe.EmployeeName, p.tsEmp) + ' (' + CONVERT(nvarchar(10), p.tsN) + ' Z-reads).'
            ELSE
                CASE WHEN p.uTotal IS NULL
                     THEN 'No history for operator ID ' + p.PosUserId + ' at ' + p.Location
                          + ' in the ' + CONVERT(nvarchar(10), @HistoryDays) + ' days before. Worth asking who it is.'
                     ELSE 'Operator ID ' + p.PosUserId + ' has been ' + CONVERT(nvarchar(10), uc.People)
                          + ' different people and no one on ' + CONVERT(nvarchar(10), CONVERT(int, @LikelyShare * 100))
                          + '% of its Z-reads. Set it by hand.'
                END
        END) AS Evidence,
        CONVERT(nvarchar(400), CASE
            WHEN p.Confidence = 'certain' THEN NULL
            WHEN p.sShift IS NOT NULL THEN
                'Operator ID ' + p.PosUserId + ' ran ' + ISNULL(ps.ShiftDescription, 'shift ' + CONVERT(nvarchar(10), p.sShift))
                + ' on ' + CONVERT(nvarchar(10), p.sN) + ' of ' + CONVERT(nvarchar(10), p.sTotal) + ' prior Z-reads.'
            WHEN p.qShift IS NOT NULL THEN
                CASE WHEN p.sTotal IS NOT NULL
                     THEN 'Operator ID ' + p.PosUserId + ' splits its shifts (' + ISNULL(sts.ShiftDescription, 'shift ' + CONVERT(nvarchar(10), p.sTop))
                          + ' on ' + CONVERT(nvarchar(10), p.sN) + ' of ' + CONVERT(nvarchar(10), p.sTotal) + '). '
                     ELSE '' END
                + 'Two-a-day rule: terminal ' + p.TerminalNo + ' printed two Z-reads that day and this is the '
                + CASE p.DaySeq WHEN 1 THEN 'first' ELSE 'second' END + ' by log file ('
                + p.DayFirstLog + ' before ' + p.DayLastLog + '), so '
                + ISNULL(ps.ShiftDescription, 'shift ' + CONVERT(nvarchar(10), p.qShift)) + '.'
            WHEN p.sTotal IS NOT NULL THEN
                'Operator ID ' + p.PosUserId + ' splits its shifts (' + ISNULL(sts.ShiftDescription, 'shift ' + CONVERT(nvarchar(10), p.sTop))
                + ' on ' + CONVERT(nvarchar(10), p.sN) + ' of ' + CONVERT(nvarchar(10), p.sTotal) + '), so the shift is left to a person.'
            ELSE 'No shift history for operator ID ' + p.PosUserId + '.'
        END) AS ShiftEvidence,
        CASE p.Confidence WHEN 'certain' THEN p.kN WHEN 'likely' THEN p.uN WHEN 'review' THEN p.tsN END     AS PriorZreads,
        CASE p.Confidence WHEN 'certain' THEN p.kTotal WHEN 'likely' THEN p.uTotal WHEN 'review' THEN p.tsTotal END AS PriorTotal,
        CONVERT(bit, CASE WHEN x.ProposedTillNo IS NOT NULL AND x.ProposedShiftNo IS NOT NULL
                               AND x.ProposedEmployeeCode IS NOT NULL THEN 1 ELSE 0 END) AS IsComplete,

        /* Does the proposal agree with the allocation the Z-read carries?
           Only an honest test under @Replay = 1 — otherwise the row's own
           allocation is part of the history that produced the proposal. */
        CONVERT(nvarchar(10), CASE
            WHEN p.IsAllocated = 0 THEN NULL
            WHEN x.ProposedEmployeeCode IS NULL THEN 'none'
            WHEN x.ProposedEmployeeCode <> p.EmployeeCode OR x.ProposedTillNo <> p.TillNo
                 OR x.ProposedShiftNo <> p.ShiftNo THEN 'differs'
            WHEN x.ProposedTillNo IS NULL OR x.ProposedShiftNo IS NULL THEN 'incomplete'
            ELSE 'match' END) AS HistoryMatch,

        CONVERT(nvarchar(10), CASE
            WHEN p.Confidence = 'certain' THEN 'pattern'
            WHEN p.sShift IS NOT NULL THEN 'operator'
            WHEN p.qShift IS NOT NULL THEN 'sequence' END) AS ShiftSource,

        /* Agora saved and stamped this Z-read, and PumpIT has since been
           allocated differently: the PumpIT allocation is the one shown (the
           latest wins — agora.vw_CashZread), and this says why. */
        p.ChangedInPumpItAfterAgora AS ChangedInPumpIt
    FROM #zp_p p
    CROSS APPLY (
        SELECT
            CASE p.Confidence WHEN 'certain' THEN p.kTill  ELSE p.tTill  END AS ProposedTillNo,
            CASE p.Confidence WHEN 'certain' THEN p.kShift ELSE p.eShift END AS ProposedShiftNo,
            CASE p.Confidence WHEN 'certain' THEN p.kEmp
                              WHEN 'likely'  THEN p.uEmp
                              WHEN 'review'  THEN p.tsEmp END AS ProposedEmployeeCode
    ) x
    OUTER APPLY (
        SELECT TOP 1 a.Id, a.TillNo, a.ShiftNo, a.EmployeeCode, a.Source, a.Confidence
        FROM agora.ZreadAllocation a
        WHERE a.BranchId = p.BranchId AND a.ZReadDate = p.ZReadDate AND a.Location = p.Location
          AND a.TerminalNo = p.TerminalNo AND a.EodCounter = p.EodCounter AND a.LogFile = p.LogFile
          AND a.PosUserId = p.PosUserId AND a.[Status] = 'proposed'
        ORDER BY a.Id DESC
    ) st
    OUTER APPLY (SELECT COUNT(*) AS People FROM #zp_u uu WHERE uu.Location = p.Location AND uu.PosUserId = p.PosUserId) uc
    OUTER APPLY (SELECT TOP 1 v.TillDescription FROM agora.vw_Till v
                 WHERE v.BranchId = p.BranchId AND v.TillNo = x.ProposedTillNo ORDER BY v.TillDescription) pt
    OUTER APPLY (SELECT TOP 1 v.ShiftDescription FROM agora.vw_Shift v
                 WHERE v.BranchId = p.BranchId AND v.ShiftNo = x.ProposedShiftNo ORDER BY v.ShiftDescription) ps
    OUTER APPLY (SELECT TOP 1 v.ShiftDescription FROM agora.vw_Shift v
                 WHERE v.BranchId = p.BranchId AND v.ShiftNo = p.sTop ORDER BY v.ShiftDescription) sts
    OUTER APPLY (SELECT TOP 1 LTRIM(RTRIM(ISNULL(v.FirstName, '') + ' ' + ISNULL(v.Surname, ''))) AS EmployeeName
                 FROM agora.vw_Employee v
                 WHERE v.BranchId = p.BranchId AND v.EmployeeCode = x.ProposedEmployeeCode ORDER BY v.IsActive DESC) pe
    OUTER APPLY (SELECT TOP 1 v.TillDescription FROM agora.vw_Till v
                 WHERE v.BranchId = p.BranchId AND v.TillNo = COALESCE(st.TillNo, p.TillNo) ORDER BY v.TillDescription) ct
    OUTER APPLY (SELECT TOP 1 v.ShiftDescription FROM agora.vw_Shift v
                 WHERE v.BranchId = p.BranchId AND v.ShiftNo = COALESCE(st.ShiftNo, p.ShiftNo) ORDER BY v.ShiftDescription) cs
    OUTER APPLY (SELECT TOP 1 LTRIM(RTRIM(ISNULL(v.FirstName, '') + ' ' + ISNULL(v.Surname, ''))) AS EmployeeName
                 FROM agora.vw_Employee v
                 WHERE v.BranchId = p.BranchId AND v.EmployeeCode = COALESCE(st.EmployeeCode, p.EmployeeCode)
                 ORDER BY v.IsActive DESC) ce
    ORDER BY p.ZReadDate, p.Location, p.TerminalNo, p.EodCounter, p.LogFile, p.PosUserId;
END
