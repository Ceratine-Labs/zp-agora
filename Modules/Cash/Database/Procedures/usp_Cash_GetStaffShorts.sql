/* ============================================================================
   agora.usp_Cash_GetStaffShorts

   WHAT IT IS FOR
   The Staff shorts screen for one site (desk and phone): what is waiting for
   an approver, what is approved and not yet on a cashup (the port of
   dbo.sp_Load_ApprovedStaffShorts), what was raised in the period, and the
   lists a raise chooses from.

   WHO READS IT
   Cash → Staff shorts (app.cash.staffshorts, app.cash.staffshorts.phone).

   Every short comes off agora.vw_CashStaffShort — one row per BRN_StaffShorts
   row, with its code (Agora's own, else the legacy spelling's alias, else
   'other'), the note kept, where it stands and the cashup it was posted to.
   Names are looked up TOP 1: the raiser and decider as Agora knows them (the
   press on agora.StaffShortEvent), else as PumpIT does (SS_Users by the
   legacy user id), so a label never multiplies a row.

   Result sets:
     1  the shorts: every one WAITING or APPROVED (unposted) at the site,
        whatever its date — they are the work — and every other one dated in
        the period; waiting first, then approved, then newest
     2  the reasons a raise chooses from (agora.StaffShortReason, active)
     3  the people at the site (BRN_Employee), active first
     4  one row: counts and totals — waiting, approved and unposted, posted and
        declined in the period — and the server's clock
     5  the trail of @StaffShortsNo when one is named (agora.StaffShortEvent),
        oldest first; empty otherwise
     6  the site's tills and 7 its shifts (BRN_Tills, BRN_Shifts) — the cashup
        a short is raised for, or posted to, is named by them

   READ-ONLY.

     EXEC agora.usp_Cash_GetStaffShorts @BranchId = 6, @DateFrom = '2026-09-14', @DateTo = '2026-09-27';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GetStaffShorts]
    @BranchId       INT,
    @DateFrom       DATE = NULL,
    @DateTo         DATE = NULL,
    @StaffShortsNo  INT  = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @BranchId IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_KEY:Choose the site.', 1;

    SET @DateTo   = ISNULL(@DateTo, CONVERT(DATE, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -13, @DateTo));

    DECLARE @From DATETIME = @DateFrom, @To DATETIME = DATEADD(day, 1, CONVERT(DATETIME, @DateTo));

    SELECT
        s.BranchId, s.StaffShortsNo, s.EmployeeCode, s.EmployeeName, s.OnEmployeeList, s.AmountShort,
        s.TransactionAt, s.TransactionDate, s.ReasonCode, s.ReasonLabel, s.ReasonFrom, s.Note, s.LegacyReason,
        s.[Status], s.Approved, s.ApprovedDate, s.IsDeclined, s.DeclinedReason, s.DeclinedDate,
        s.PostedTillNo, s.PostedShiftNo, s.PostedDate, s.RaisedIn, s.ForDate, s.ForTillNo, s.ForShiftNo,
        s.Source, s.SourceId, s.SourceRef, s.CapturedAt, s.RaisedMailAt, s.DecidedMailAt,
        COALESCE(ru.UserName, rl.UserName)                                   AS RaisedBy,
        COALESCE(du.UserName, dl.UserName)                                   AS DecidedBy,
        COALESCE(de.DeclineNote, s.DeclinedReason)                           AS DeclineReasonFull,
        CONVERT(BIT, CASE WHEN s.[Status] = 'Waiting' THEN 1 ELSE 0 END)    AS CanDecide,
        CONVERT(BIT, CASE WHEN s.[Status] = 'Approved' AND s.OnEmployeeList = 1 AND ISNULL(s.Source, '') <> 'recon'
                          THEN 1 ELSE 0 END)                                AS CanPost,
        CONVERT(BIT, CASE WHEN s.[Status] = 'Approved' THEN 1 ELSE 0 END)  AS CanWithdraw
    FROM agora.vw_CashStaffShort s
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = s.RaisedByUserId) ru
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.vw_ErpUser u WHERE u.ErpUserId = s.CreatedByUserId) rl
    OUTER APPLY (SELECT TOP 1 e.CreatedBy, CASE WHEN e.[Event] = 'declined' THEN e.Note END AS DeclineNote
                 FROM agora.StaffShortEvent e
                 WHERE e.BranchId = s.BranchId AND e.StaffShortsNo = s.StaffShortsNo AND e.[Event] IN ('approved', 'declined')
                 ORDER BY e.Id DESC) de
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = de.CreatedBy) du
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.vw_ErpUser u
                 WHERE u.ErpUserId = CASE WHEN s.IsDeclined = 1 THEN NULLIF(s.DeclinedByUserId, 0) ELSE s.ApprovedByUserId END) dl
    WHERE s.BranchId = @BranchId
      AND (   s.[Status] IN ('Waiting', 'Approved')
           OR (s.TransactionAt >= @From AND s.TransactionAt < @To)
           OR s.StaffShortsNo = @StaffShortsNo)
    ORDER BY CASE s.[Status] WHEN 'Waiting' THEN 0 WHEN 'Approved' THEN 1 ELSE 2 END,
             s.TransactionAt DESC, s.StaffShortsNo DESC;

    SELECT r.Code, r.Label, r.Description, r.NeedsNote, r.SortOrder
    FROM agora.StaffShortReason r
    WHERE r.IsActive = 1 AND r.DeletedAt IS NULL
    ORDER BY r.SortOrder, r.Label;

    SELECT e.EmployeeCode, e.EmployeeName, e.IsActive, e.IsPumpAttendant
    FROM agora.vw_Employee e
    WHERE e.BranchId = @BranchId
    ORDER BY CASE WHEN e.IsActive = 1 THEN 0 ELSE 1 END, e.EmployeeName, e.EmployeeCode;

    SELECT
        SUM(CASE WHEN s.[Status] = 'Waiting' THEN 1 ELSE 0 END)                                          AS Waiting,
        CONVERT(MONEY, ISNULL(SUM(CASE WHEN s.[Status] = 'Waiting' THEN ISNULL(s.AmountShort, 0) ELSE 0 END), 0))   AS WaitingAmount,
        SUM(CASE WHEN s.[Status] = 'Approved' THEN 1 ELSE 0 END)                                         AS Approved,
        CONVERT(MONEY, ISNULL(SUM(CASE WHEN s.[Status] = 'Approved' THEN ISNULL(s.AmountShort, 0) ELSE 0 END), 0))  AS ApprovedAmount,
        SUM(CASE WHEN s.[Status] = 'Posted' AND s.TransactionAt >= @From AND s.TransactionAt < @To THEN 1 ELSE 0 END) AS Posted,
        CONVERT(MONEY, ISNULL(SUM(CASE WHEN s.[Status] = 'Posted' AND s.TransactionAt >= @From AND s.TransactionAt < @To
                                       THEN ISNULL(s.AmountShort, 0) ELSE 0 END), 0))                               AS PostedAmount,
        SUM(CASE WHEN s.[Status] = 'Declined' AND s.TransactionAt >= @From AND s.TransactionAt < @To THEN 1 ELSE 0 END) AS Declined,
        CONVERT(DATETIME, GETDATE())                                                                     AS ServerNow
    FROM agora.vw_CashStaffShort s
    WHERE s.BranchId = @BranchId;

    SELECT e.Id, e.[Event], e.Amount, e.ReasonCode, e.TransactionDate, e.TillNo, e.ShiftNo, e.Note, e.CreatedAt,
           u.UserName AS ByUser
    FROM agora.StaffShortEvent e
    OUTER APPLY (SELECT TOP 1 x.UserName FROM agora.[User] x WHERE x.Id = e.CreatedBy) u
    WHERE e.BranchId = @BranchId AND e.StaffShortsNo = @StaffShortsNo
    ORDER BY e.Id;

    SELECT t.TillNo, LTRIM(RTRIM(t.TillDescription)) AS TillDescription
    FROM agora.vw_Till t
    WHERE t.BranchId = @BranchId
    ORDER BY t.TillNo;

    SELECT s.ShiftNo, LTRIM(RTRIM(s.ShiftDescription)) AS ShiftDescription
    FROM agora.vw_Shift s
    WHERE s.BranchId = @BranchId
    ORDER BY s.ShiftNo;
END
