/* ============================================================================
   agora.usp_Cash_WithdrawStaffShort

   Withdraw an APPROVED staff short that is not yet on a cashup: take the
   approval back and decline it, with a reason. The way out for a short that
   was approved and should not stand — above all one a cash-bag match raised,
   whose match cannot be reversed while an approved short hangs off it
   (usp_Recon_Reverse refuses SHORT_RAISED and says to withdraw it here).

   ** ONE OF THE PROCEDURES IN AGORA THAT WRITE TO THE CUSTOMER'S ESTATE.
   ** Ryan's named go-ahead, 28 Sep 2026 (PM decision 01a0e5b5). What it
   ** writes, and nothing else:
   **
   **   BRN_StaffShorts   UPDATE, on the one row, only while it is approved,
   **                     not declined and on no cashup:
   **                       Approved 1 -> 0, ApprovedDate -> NULL,
   **                       ApprovedByUserId -> 0          (the legacy's own
   **                                                       declined shape: 484
   **                                                       of 484 declined rows)
   **                       IsDeclined 0 -> 1, DeclinedReason (the reason's first
   **                       50 characters — the column's width, as the legacy
   **                       keeps it), DeclinedByUserId, DeclinedDate (GETDATE())
   **
   ** and agora.StaffShortEvent ('withdrawn') with every value it replaced — the
   ** approval included — and the WHOLE reason, in ONE transaction.

   A POSTED SHORT IS NOT WITHDRAWN. Once it is on a cashup's pump-shortages
   line (IsSelectedForDailyBanking, or a BRN_DailyBankingEmployees row carrying
   its number) the cashup counts it and the deduction schedule reads it;
   taking it back is a correction of the cashup, which this is not. Both are
   read under UPDLOCK, HOLDLOCK on the full key, so a post cannot land between
   the check and the write.

   A waiting short is declined, not withdrawn (usp_Cash_DeclineStaffShort); a
   declined one is already not charged.

   The raiser is told, as for any decision: agora.StaffShort.DecidedMailAt is
   cleared in the same transaction so the application sends the decided mail
   again, now saying withdrawn.

   Returns the writer status row (Ok, Code, Message, Id = the number) with
   StaffShortsNo, EmployeeCode, AmountShort and SaveRef.

   Refusals: CASH_SHORT_USER · CASH_WRITES_OFF · CASH_SHORT_KEY ·
             CASH_SHORT_WITHDRAW_REASON · CASH_PUMPIT_TRIGGER ·
             CASH_SHORT_NOT_FOUND · CASH_SHORT_NOT_APPROVED ·
             CASH_SHORT_DECIDED · CASH_SHORT_POSTED

     EXEC agora.usp_Cash_WithdrawStaffShort @BranchId = 999, @StaffShortsNo = 5000,
          @Reason = 'Recon batch reversed: wrong bags', @WriteMode = 'live', @UserId = 1;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_WithdrawStaffShort]
    @BranchId       INT,
    @StaffShortsNo  INT,
    @Reason         NVARCHAR(400),
    @WriteMode      NVARCHAR(10)  = 'off',
    @UserId         INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_USER:A staff short is withdrawn by a person, and none was given.', 1;
    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:CASH_WRITES_OFF:Deciding staff shorts in PumpIT is switched off. Nothing was written.', 1;
    IF @BranchId IS NULL OR @StaffShortsNo IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_KEY:Name the site and the staff short.', 1;
    IF @Reason IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_WITHDRAW_REASON:A withdrawal says why. The person the short was raised against, and whoever raised it, will read it.', 1;

    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID('[PumpIT].dbo.BRN_StaffShorts') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:CASH_PUMPIT_TRIGGER:A trigger is enabled on BRN_StaffShorts. Agora does not write through one. Nothing was written.', 1;

    DECLARE @ErpUserId INT = (SELECT TOP 1 u.LegacyUserId FROM agora.[User] u WHERE u.Id = @UserId);
    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Ref UNIQUEIDENTIFIER = NEWID();
    DECLARE @Msg NVARCHAR(400);
    DECLARE @Prior TABLE (EmployeeCode NVARCHAR(11), AmountShort MONEY, Approved BIT, ApprovedDate DATETIME,
                          ApprovedByUserId INT, IsDeclined BIT, DeclinedReason NVARCHAR(50), DeclinedByUserId INT,
                          DeclinedDate DATETIME, IsSelected BIT);
    DECLARE @OnLeg NVARCHAR(120);

    BEGIN TRY
        BEGIN TRANSACTION;

        /* The row, on its full key, locked to the end. */
        INSERT INTO @Prior (EmployeeCode, AmountShort, Approved, ApprovedDate, ApprovedByUserId, IsDeclined,
                            DeclinedReason, DeclinedByUserId, DeclinedDate, IsSelected)
        SELECT LTRIM(RTRIM(s.EmployeeCode)), s.AmountShort, s.Approved, s.ApprovedDate, s.ApprovedByUserId, s.IsDeclined,
               s.DeclinedReason, s.DeclinedByUserId, s.DeclinedDate, ISNULL(s.IsSelectedForDailyBanking, 0)
        FROM [PumpIT].dbo.BRN_StaffShorts s WITH (UPDLOCK, HOLDLOCK)
        WHERE s.SSBranchId = @BranchId AND s.StaffShortsNo = @StaffShortsNo;

        /* And any pump-shortage line carrying it, so a post cannot slip in. */
        SELECT TOP 1 @OnLeg = CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23))
        FROM [PumpIT].dbo.BRN_DailyBankingEmployees x WITH (UPDLOCK, HOLDLOCK)
        WHERE x.SSBranchId = @BranchId AND x.StaffShortsNo = @StaffShortsNo AND @StaffShortsNo <> 0;

        IF NOT EXISTS (SELECT 1 FROM @Prior)
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_SHORT_NOT_FOUND:Staff short ', @StaffShortsNo, ' is not in PumpIT for this site. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        IF EXISTS (SELECT 1 FROM @Prior WHERE IsSelected = 1) OR @OnLeg IS NOT NULL
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_SHORT_POSTED:Staff short ', @StaffShortsNo, ' is on a cashup',
                              ISNULL(' (the one for ' + @OnLeg + ')', ''),
                              ', which counts it, so it is not withdrawn. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        IF EXISTS (SELECT 1 FROM @Prior WHERE IsDeclined = 1)
        BEGIN
            SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_SHORT_DECIDED:Staff short ', @StaffShortsNo, ' was declined',
                                            ISNULL(' on ' + CONVERT(CHAR(10), DeclinedDate, 23), ''),
                                            ' already, so it is not charged. Nothing was written.')
                        FROM @Prior);
            THROW 51000, @Msg, 1;
        END

        IF EXISTS (SELECT 1 FROM @Prior WHERE Approved = 0)
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_SHORT_NOT_APPROVED:Staff short ', @StaffShortsNo,
                              ' is waiting for approval — decline it instead. Only an approved short is withdrawn. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        -- UPDATE Approved, ApprovedDate, ApprovedByUserId, IsDeclined, DeclinedReason, DeclinedByUserId, DeclinedDate
        UPDATE s
           SET s.Approved = 0, s.ApprovedDate = NULL, s.ApprovedByUserId = 0,
               s.IsDeclined = 1, s.DeclinedReason = LEFT(@Reason, 50), s.DeclinedByUserId = ISNULL(@ErpUserId, 0),
               s.DeclinedDate = GETDATE()
        FROM [PumpIT].dbo.BRN_StaffShorts s
        WHERE s.SSBranchId = @BranchId AND s.StaffShortsNo = @StaffShortsNo
          AND s.Approved = 1 AND s.IsDeclined = 0 AND ISNULL(s.IsSelectedForDailyBanking, 0) = 0;

        IF @@ROWCOUNT <> 1
            THROW 51000, 'AGORA:CASH_SHORT_DECIDED:The short moved while this ran. Nothing was written.', 1;

        INSERT INTO agora.StaffShortEvent
            (BranchId, StaffShortsNo, [Event], EmployeeCode, Amount, ReasonCode, WriteMode, SaveRef, WritesJson, Note,
             CreatedAt, CreatedBy)
        SELECT @BranchId, @StaffShortsNo, 'withdrawn', p.EmployeeCode, p.AmountShort,
               (SELECT TOP 1 a.ReasonCode FROM agora.StaffShort a WHERE a.BranchId = @BranchId AND a.StaffShortsNo = @StaffShortsNo),
               'live', @Ref,
               (SELECT 'update' AS [action], 'BRN_StaffShorts' AS [table],
                       JSON_QUERY((SELECT @BranchId AS SSBranchId, @StaffShortsNo AS StaffShortsNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS [key],
                       JSON_QUERY((SELECT p.Approved AS Approved, p.ApprovedDate AS ApprovedDate, p.ApprovedByUserId AS ApprovedByUserId,
                                          p.IsDeclined AS IsDeclined, p.DeclinedReason AS DeclinedReason,
                                          p.DeclinedByUserId AS DeclinedByUserId, p.DeclinedDate AS DeclinedDate
                                   FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS prior,
                       JSON_QUERY((SELECT s.Approved AS Approved, s.ApprovedDate AS ApprovedDate, s.ApprovedByUserId AS ApprovedByUserId,
                                          s.IsDeclined AS IsDeclined, s.DeclinedReason AS DeclinedReason,
                                          s.DeclinedByUserId AS DeclinedByUserId, s.DeclinedDate AS DeclinedDate
                                   FROM [PumpIT].dbo.BRN_StaffShorts s
                                   WHERE s.SSBranchId = @BranchId AND s.StaffShortsNo = @StaffShortsNo
                                   FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS new
                FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
               @Reason, @Now, @UserId
        FROM @Prior p;

        /* The raiser hears about it again: withdrawn, this time. */
        UPDATE agora.StaffShort
           SET DecidedMailAt = NULL, UpdatedAt = @Now, UpdatedBy = @UserId
         WHERE BranchId = @BranchId AND StaffShortsNo = @StaffShortsNo;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT)                                          AS Ok,
        'CASH_SHORT_WITHDRAWN'                                  AS Code,
        CONVERT(NVARCHAR(400), (SELECT CONCAT('Staff short ', @StaffShortsNo, ' withdrawn: R', FORMAT(p.AmountShort, 'N2'),
                                              ' against ', p.EmployeeCode, ' is no longer approved and is not charged. Reason: ', @Reason)
                                FROM @Prior p))                 AS Message,
        @StaffShortsNo                                          AS Id,
        @StaffShortsNo                                          AS StaffShortsNo,
        (SELECT EmployeeCode FROM @Prior)                       AS EmployeeCode,
        (SELECT AmountShort FROM @Prior)                        AS AmountShort,
        @Ref                                                    AS SaveRef;
END
