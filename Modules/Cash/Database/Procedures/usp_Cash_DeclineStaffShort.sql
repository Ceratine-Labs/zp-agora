/* ============================================================================
   agora.usp_Cash_DeclineStaffShort

   Decline a staff short that is waiting, with a reason — the approver's "no",
   as PumpIT's own approval list gives it. A declined short is never posted and
   never deducted; if it was right after all, it is raised again.

   ** ONE OF THE PROCEDURES IN AGORA THAT WRITE TO THE CUSTOMER'S ESTATE.
   ** Approved by Ryan on 27 Sep 2026 (PM decisions 01a0e22a-97be /
   ** 01a0e22a-9aa9). What it writes, and nothing else:
   **
   **   BRN_StaffShorts   UPDATE IsDeclined (0 -> 1), DeclinedReason (the
   **                     reason's first 50 characters — the column's width),
   **                     DeclinedByUserId, DeclinedDate (GETDATE()) — on the
   **                     one row, only while it is waiting (Approved 0,
   **                     IsDeclined 0)
   **
   ** and agora.StaffShortEvent with the values it replaced and the WHOLE
   ** reason, in ONE transaction.

   Exactly the legacy's decline, column for column (484 declined rows on the
   customer's instance, 27 Sep 2026: Approved 0, ApprovedDate NULL,
   ApprovedByUserId 0, the reason, the user, the date). DeclinedByUserId is the
   person's PumpIT user id (agora.User.LegacyUserId), 0 — the column's own
   default — for somebody PumpIT never knew.

   ALSO CALLED BY agora.usp_Recon_Reverse (Ryan, 27 Sep 2026, PM decision
   01a0e388): reversing a cash-bag match declines the short it raised while
   that short is still waiting, inside the reversal's transaction, with a
   reason naming the reversal. That caller passes @Quiet = 1, so no status row
   is returned and its own stays the first result set. An approved or posted
   short is not declined here, by anybody.

   Returns (unless @Quiet) the writer status row (Ok, Code, Message, Id = the
   number) with StaffShortsNo, EmployeeCode, AmountShort and SaveRef.

   Refusals: CASH_SHORT_USER · CASH_WRITES_OFF · CASH_SHORT_KEY ·
             CASH_SHORT_DECLINE_REASON · CASH_PUMPIT_TRIGGER ·
             CASH_SHORT_NOT_FOUND · CASH_SHORT_DECIDED

     EXEC agora.usp_Cash_DeclineStaffShort @BranchId = 999, @StaffShortsNo = 5000,
          @Reason = 'No slips received', @WriteMode = 'live', @UserId = 1;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_DeclineStaffShort]
    @BranchId       INT,
    @StaffShortsNo  INT,
    @Reason         NVARCHAR(400),
    @WriteMode      NVARCHAR(10)  = 'off',
    @UserId         INT,
    @Quiet          BIT           = 0
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_USER:A staff short is declined by a person, and none was given.', 1;
    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:CASH_WRITES_OFF:Deciding staff shorts in PumpIT is switched off. Nothing was written.', 1;
    IF @BranchId IS NULL OR @StaffShortsNo IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_KEY:Name the site and the staff short.', 1;
    IF @Reason IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_DECLINE_REASON:A decline says why. The person the short was raised against will read it.', 1;

    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID('[PumpIT].dbo.BRN_StaffShorts') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:CASH_PUMPIT_TRIGGER:A trigger is enabled on BRN_StaffShorts. Agora does not write through one. Nothing was written.', 1;

    DECLARE @ErpUserId INT = (SELECT TOP 1 u.LegacyUserId FROM agora.[User] u WHERE u.Id = @UserId);
    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Ref UNIQUEIDENTIFIER = NEWID();
    DECLARE @Msg NVARCHAR(400);
    DECLARE @Column NVARCHAR(50) = LEFT(@Reason, 50);
    DECLARE @Prior TABLE (EmployeeCode NVARCHAR(11), AmountShort MONEY, Approved BIT, ApprovedDate DATETIME,
                          IsDeclined BIT, DeclinedReason NVARCHAR(50), DeclinedByUserId INT, DeclinedDate DATETIME,
                          IsPosted BIT);

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO @Prior (EmployeeCode, AmountShort, Approved, ApprovedDate, IsDeclined, DeclinedReason,
                            DeclinedByUserId, DeclinedDate, IsPosted)
        SELECT LTRIM(RTRIM(s.EmployeeCode)), s.AmountShort, s.Approved, s.ApprovedDate, s.IsDeclined, s.DeclinedReason,
               s.DeclinedByUserId, s.DeclinedDate, ISNULL(s.IsSelectedForDailyBanking, 0)
        FROM [PumpIT].dbo.BRN_StaffShorts s WITH (UPDLOCK, HOLDLOCK)
        WHERE s.SSBranchId = @BranchId AND s.StaffShortsNo = @StaffShortsNo;

        IF NOT EXISTS (SELECT 1 FROM @Prior)
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_SHORT_NOT_FOUND:Staff short ', @StaffShortsNo, ' is not in PumpIT for this site. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        IF EXISTS (SELECT 1 FROM @Prior WHERE IsDeclined = 1 OR Approved = 1 OR IsPosted = 1)
        BEGIN
            SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_SHORT_DECIDED:Staff short ', @StaffShortsNo, ' was ',
                                            CASE WHEN IsDeclined = 1
                                                 THEN CONCAT('declined', ISNULL(' on ' + CONVERT(CHAR(10), DeclinedDate, 23), ''))
                                                 WHEN IsPosted = 1 THEN 'approved and posted to a cashup'
                                                 ELSE CONCAT('approved', ISNULL(' on ' + CONVERT(CHAR(10), ApprovedDate, 23), '')) END,
                                            ' already. Only a short waiting for approval can be declined. Nothing was written.')
                        FROM @Prior);
            THROW 51000, @Msg, 1;
        END

        -- UPDATE IsDeclined, DeclinedReason, DeclinedByUserId, DeclinedDate — only while waiting
        UPDATE s
           SET s.IsDeclined = 1, s.DeclinedReason = @Column, s.DeclinedByUserId = ISNULL(@ErpUserId, 0),
               s.DeclinedDate = GETDATE()
        FROM [PumpIT].dbo.BRN_StaffShorts s
        WHERE s.SSBranchId = @BranchId AND s.StaffShortsNo = @StaffShortsNo
          AND s.Approved = 0 AND s.IsDeclined = 0;

        IF @@ROWCOUNT <> 1
            THROW 51000, 'AGORA:CASH_SHORT_DECIDED:The short was decided while this ran. Nothing was written.', 1;

        INSERT INTO agora.StaffShortEvent
            (BranchId, StaffShortsNo, [Event], EmployeeCode, Amount, ReasonCode, WriteMode, SaveRef, WritesJson, Note,
             CreatedAt, CreatedBy)
        SELECT @BranchId, @StaffShortsNo, 'declined', p.EmployeeCode, p.AmountShort,
               (SELECT TOP 1 a.ReasonCode FROM agora.StaffShort a WHERE a.BranchId = @BranchId AND a.StaffShortsNo = @StaffShortsNo),
               'live', @Ref,
               (SELECT 'update' AS [action], 'BRN_StaffShorts' AS [table],
                       JSON_QUERY((SELECT @BranchId AS SSBranchId, @StaffShortsNo AS StaffShortsNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS [key],
                       JSON_QUERY((SELECT p.IsDeclined AS IsDeclined, p.DeclinedReason AS DeclinedReason,
                                          p.DeclinedByUserId AS DeclinedByUserId, p.DeclinedDate AS DeclinedDate
                                   FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS prior,
                       JSON_QUERY((SELECT s.IsDeclined AS IsDeclined, s.DeclinedReason AS DeclinedReason,
                                          s.DeclinedByUserId AS DeclinedByUserId, s.DeclinedDate AS DeclinedDate
                                   FROM [PumpIT].dbo.BRN_StaffShorts s
                                   WHERE s.SSBranchId = @BranchId AND s.StaffShortsNo = @StaffShortsNo
                                   FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS new
                FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
               @Reason, @Now, @UserId
        FROM @Prior p;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    IF ISNULL(@Quiet, 0) = 0
        SELECT
            CAST(1 AS BIT)                                      AS Ok,
            'CASH_SHORT_DECLINED'                               AS Code,
            CONVERT(NVARCHAR(400), (SELECT CONCAT('Staff short ', @StaffShortsNo, ' declined: R', FORMAT(p.AmountShort, 'N2'),
                                                  ' against ', p.EmployeeCode, ' is not charged. Reason: ', @Reason)
                                    FROM @Prior p))             AS Message,
            @StaffShortsNo                                      AS Id,
            @StaffShortsNo                                      AS StaffShortsNo,
            (SELECT EmployeeCode FROM @Prior)                   AS EmployeeCode,
            (SELECT AmountShort FROM @Prior)                    AS AmountShort,
            @Ref                                                AS SaveRef;
END
