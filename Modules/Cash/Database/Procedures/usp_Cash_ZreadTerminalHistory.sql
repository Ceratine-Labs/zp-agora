/* ============================================================================
   agora.usp_Cash_ZreadTerminalHistory

   WHAT IT IS FOR
   "Who normally runs this terminal" — the table under a Z-read's proposal in
   its row panel. The evidence line says what the proposer concluded; this is
   what it concluded FROM, so a person overriding it can see the pattern they
   are overriding.

   One row per person, shift and till seen on the terminal, most frequent
   first: how many Z-reads, which operator IDs they came under, and the last
   day seen. Allocated Z-reads only, by the effective allocation
   agora.vw_CashZread states once (Agora's over the legacy row's).

   READ-ONLY.

     EXEC agora.usp_Cash_ZreadTerminalHistory @BranchId = 9, @Location = 'ARCH',
          @TerminalNo = '2', @AsOf = '2026-08-31';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_ZreadTerminalHistory]
    @BranchId     INT,
    @Location     NVARCHAR(10),
    @TerminalNo   NVARCHAR(3),
    @AsOf         DATE,
    @HistoryDays  INT = 90,
    @Top          INT = 8
AS
BEGIN
    SET NOCOUNT ON;

    SET @Location   = ISNULL(LTRIM(RTRIM(@Location)), '');
    SET @TerminalNo = LTRIM(RTRIM(@TerminalNo));
    SET @HistoryDays = CASE WHEN @HistoryDays BETWEEN 7 AND 730 THEN @HistoryDays ELSE 90 END;
    SET @Top = CASE WHEN @Top BETWEEN 1 AND 50 THEN @Top ELSE 8 END;

    DECLARE @From DATE = DATEADD(day, -@HistoryDays, @AsOf);

    SELECT x.EmployeeCode, x.ShiftNo, x.TillNo, x.PosUserId, x.ZReadDate
    INTO #th_z
    FROM agora.vw_CashZread x
    WHERE x.BranchId = @BranchId
      AND x.ZReadAt >= @From AND x.ZReadAt < DATEADD(day, 1, @AsOf)
      AND x.Location = @Location AND x.TerminalNo = @TerminalNo
      AND x.IsAllocated = 1;

    SELECT TOP (@Top)
        h.EmployeeCode,
        e.EmployeeName,
        h.ShiftNo,
        s.ShiftDescription,
        h.TillNo,
        t.TillDescription,
        h.Zreads,
        o.OperatorIds,
        h.LastSeen
    FROM (
        SELECT EmployeeCode, ShiftNo, TillNo, COUNT(*) AS Zreads, MAX(ZReadDate) AS LastSeen
        FROM #th_z
        GROUP BY EmployeeCode, ShiftNo, TillNo
    ) h
    OUTER APPLY (
        SELECT STRING_AGG(CONVERT(NVARCHAR(MAX), d.PosUserId), ', ') WITHIN GROUP (ORDER BY d.PosUserId) AS OperatorIds
        FROM (SELECT DISTINCT z.PosUserId FROM #th_z z
              WHERE z.EmployeeCode = h.EmployeeCode AND z.ShiftNo = h.ShiftNo AND z.TillNo = h.TillNo) d
    ) o
    OUTER APPLY (SELECT TOP 1 LTRIM(RTRIM(ISNULL(v.FirstName, '') + ' ' + ISNULL(v.Surname, ''))) AS EmployeeName
                 FROM agora.vw_Employee v WHERE v.BranchId = @BranchId AND v.EmployeeCode = h.EmployeeCode
                 ORDER BY v.IsActive DESC) e
    OUTER APPLY (SELECT TOP 1 v.ShiftDescription FROM agora.vw_Shift v
                 WHERE v.BranchId = @BranchId AND v.ShiftNo = h.ShiftNo ORDER BY v.ShiftDescription) s
    OUTER APPLY (SELECT TOP 1 v.TillDescription FROM agora.vw_Till v
                 WHERE v.BranchId = @BranchId AND v.TillNo = h.TillNo ORDER BY v.TillDescription) t
    ORDER BY h.Zreads DESC, h.LastSeen DESC, h.EmployeeCode;

    DROP TABLE #th_z;
END
