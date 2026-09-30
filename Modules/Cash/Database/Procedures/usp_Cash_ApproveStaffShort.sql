/* ============================================================================
   agora.usp_Cash_ApproveStaffShort

   Approve a staff short that is waiting — the approver's "yes", as PumpIT's
   own approval list (dbo.sp_SelectUnApprovedStaffShortsByApproverUser) gives
   it. An approved short can then be posted to a cashup's pump-shortages leg
   (agora.usp_Cash_PostStaffShort), which is what the cashup counts and what
   the deduction schedule reads. Approving does not post: in PumpIT every
   posting is a separate act, a minute to days later (27 Sep 2026).

   ** ONE OF THE PROCEDURES IN AGORA THAT WRITE TO THE CUSTOMER'S ESTATE.
   ** Approved by Ryan on 27 Sep 2026 (PM decisions 01a0e22a-97be /
   ** 01a0e22a-9aa9). What it writes, and nothing else:
   **
   **   BRN_StaffShorts   UPDATE Approved (0 -> 1), ApprovedDate (GETDATE()),
   **                     ApprovedByUserId — on the one row, only while it is
   **                     waiting (Approved 0, IsDeclined 0)
   **
   ** and agora.StaffShortEvent with the values it replaced, in ONE transaction.

   WHO MAY APPROVE is the route's and the screen's (cash.staffshort.approve,
   granted to PumpIT's own approver band, BRN_ApproverUserlevel, when the
   permission was seeded). The legacy band restricts nothing by amount or site:
   anybody in it sees and decides every waiting short. ApprovedByUserId is the
   person's PumpIT user id (agora.User.LegacyUserId) — NULL for somebody PumpIT
   never knew; Agora's user is on the event.

   A DECIDED SHORT IS NOT DECIDED AGAIN. Approved or declined — in Agora or in
   PumpIT — is refused, saying which and when.

   Returns the writer status row (Ok, Code, Message, Id = the number) with
   StaffShortsNo, EmployeeCode, AmountShort and SaveRef.

   Refusals: CASH_SHORT_USER · CASH_WRITES_OFF · CASH_SHORT_KEY ·
             CASH_PUMPIT_TRIGGER · CASH_SHORT_NOT_FOUND · CASH_SHORT_DECIDED

     EXEC agora.usp_Cash_ApproveStaffShort @BranchId = 999, @StaffShortsNo = 5000,
          @WriteMode = 'live', @UserId = 1;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_ApproveStaffShort]
    @BranchId       INT,
    @StaffShortsNo  INT,
    @Note           NVARCHAR(400) = NULL,
    @WriteMode      NVARCHAR(10)  = 'off',
    @UserId         INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Note = NULLIF(LTRIM(RTRIM(@Note)), N'');

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_USER:A staff short is approved by a person, and none was given.', 1;
    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:CASH_WRITES_OFF:Deciding staff shorts in PumpIT is switched off. Nothing was written.', 1;
    IF @BranchId IS NULL OR @StaffShortsNo IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_KEY:Name the site and the staff short.', 1;

    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID('[PumpIT].dbo.BRN_StaffShorts') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:CASH_PUMPIT_TRIGGER:A trigger is enabled on BRN_StaffShorts. Agora does not write through one. Nothing was written.', 1;

    DECLARE @ErpUserId INT = (SELECT TOP 1 u.LegacyUserId FROM agora.[User] u WHERE u.Id = @UserId);
    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Ref UNIQUEIDENTIFIER = NEWID();
    DECLARE @Msg NVARCHAR(400);
    DECLARE @Prior TABLE (EmployeeCode NVARCHAR(11), AmountShort MONEY, Approved BIT, ApprovedDate DATETIME,
                          ApprovedByUserId INT, IsDeclined BIT, DeclinedReason NVARCHAR(50), DeclinedDate DATETIME);

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO @Prior (EmployeeCode, AmountShort, Approved, ApprovedDate, ApprovedByUserId, IsDeclined, DeclinedReason, DeclinedDate)
        SELECT LTRIM(RTRIM(s.EmployeeCode)), s.AmountShort, s.Approved, s.ApprovedDate, s.ApprovedByUserId,
               s.IsDeclined, s.DeclinedReason, s.DeclinedDate
        FROM [PumpIT].dbo.BRN_StaffShorts s WITH (UPDLOCK, HOLDLOCK)
        WHERE s.SSBranchId = @BranchId AND s.StaffShortsNo = @StaffShortsNo;

        IF NOT EXISTS (SELECT 1 FROM @Prior)
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_SHORT_NOT_FOUND:Staff short ', @StaffShortsNo, ' is not in PumpIT for this site. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        IF EXISTS (SELECT 1 FROM @Prior WHERE IsDeclined = 1 OR Approved = 1)
        BEGIN
            SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_SHORT_DECIDED:Staff short ', @StaffShortsNo, ' was ',
                                            CASE WHEN IsDeclined = 1
                                                 THEN CONCAT('declined', ISNULL(' on ' + CONVERT(CHAR(10), DeclinedDate, 23), ''),
                                                             ISNULL(' (' + LTRIM(RTRIM(DeclinedReason)) + ')', ''))
                                                 ELSE CONCAT('approved', ISNULL(' on ' + CONVERT(CHAR(10), ApprovedDate, 23), '')) END,
                                            ' already. A decided short is not decided again. Nothing was written.')
                        FROM @Prior);
            THROW 51000, @Msg, 1;
        END

        -- UPDATE Approved, ApprovedDate, ApprovedByUserId — only while waiting
        UPDATE s
           SET s.Approved = 1, s.ApprovedDate = GETDATE(), s.ApprovedByUserId = @ErpUserId
        FROM [PumpIT].dbo.BRN_StaffShorts s
        WHERE s.SSBranchId = @BranchId AND s.StaffShortsNo = @StaffShortsNo
          AND s.Approved = 0 AND s.IsDeclined = 0;

        IF @@ROWCOUNT <> 1
            THROW 51000, 'AGORA:CASH_SHORT_DECIDED:The short was decided while this ran. Nothing was written.', 1;

        INSERT INTO agora.StaffShortEvent
            (BranchId, StaffShortsNo, [Event], EmployeeCode, Amount, ReasonCode, WriteMode, SaveRef, WritesJson, Note,
             CreatedAt, CreatedBy)
        SELECT @BranchId, @StaffShortsNo, 'approved', p.EmployeeCode, p.AmountShort,
               (SELECT TOP 1 a.ReasonCode FROM agora.StaffShort a WHERE a.BranchId = @BranchId AND a.StaffShortsNo = @StaffShortsNo),
               'live', @Ref,
               (SELECT 'update' AS [action], 'BRN_StaffShorts' AS [table],
                       JSON_QUERY((SELECT @BranchId AS SSBranchId, @StaffShortsNo AS StaffShortsNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS [key],
                       JSON_QUERY((SELECT p.Approved AS Approved, p.ApprovedDate AS ApprovedDate, p.ApprovedByUserId AS ApprovedByUserId
                                   FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS prior,
                       JSON_QUERY((SELECT s.Approved AS Approved, s.ApprovedDate AS ApprovedDate, s.ApprovedByUserId AS ApprovedByUserId
                                   FROM [PumpIT].dbo.BRN_StaffShorts s
                                   WHERE s.SSBranchId = @BranchId AND s.StaffShortsNo = @StaffShortsNo
                                   FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS new
                FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
               @Note, @Now, @UserId
        FROM @Prior p;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT)                                          AS Ok,
        'CASH_SHORT_APPROVED'                                   AS Code,
        CONVERT(NVARCHAR(400), (SELECT CONCAT('Staff short ', @StaffShortsNo, ' approved: R', FORMAT(p.AmountShort, 'N2'),
                                              ' against ', p.EmployeeCode,
                                              '. It is posted when it is put on a cashup''s pump shortages.')
                                FROM @Prior p))                 AS Message,
        @StaffShortsNo                                          AS Id,
        @StaffShortsNo                                          AS StaffShortsNo,
        (SELECT EmployeeCode FROM @Prior)                       AS EmployeeCode,
        (SELECT AmountShort FROM @Prior)                        AS AmountShort,
        @Ref                                                    AS SaveRef;
END
