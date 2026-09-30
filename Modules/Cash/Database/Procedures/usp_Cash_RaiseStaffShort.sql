/* ============================================================================
   agora.usp_Cash_RaiseStaffShort

   Raise a staff short: money a named person is answerable for, waiting for an
   approver — PumpIT's own staff-short capture, ported, with a reason code.

   ** ONE OF THE PROCEDURES IN AGORA THAT WRITE TO THE CUSTOMER'S ESTATE.
   ** Approved by Ryan on 27 Sep 2026 (PM decisions 01a0e22a-97be /
   ** 01a0e22a-9aa9: staff shorts write the legacy BRN_StaffShorts, and ONLY
   ** through the Cash staff-shorts procedures). What it writes, and nothing
   ** else:
   **
   **   SS_UniqueNumber   UPDATE NextUniqueNumber (+1), SectionId 2 only — the
   **                     staff-short counter, as dbo.sp_GenerateStaffShortsNo
   **   BRN_StaffShorts   INSERT one row:
   **                       SSBranchId, StaffShortsNo, EmployeeCode, AmountShort,
   **                       Note, Reason, TransactionDate, CreatedByUserId,
   **                       Approved (0), ApprovedDate (NULL),
   **                       ApprovedByUserId (NULL), IsDeclined (0),
   **                       DeclinedReason (NULL), DeclinedByUserId (0),
   **                       DeclinedDate (NULL), IsSelectedForDailyBanking (0),
   **                       CreateDateTime
   **
   ** Both, and agora.StaffShort and agora.StaffShortEvent with exactly what was
   ** written, in ONE transaction. Read this header before changing a line.

   THE PORT
     dbo.sp_GenerateStaffShortsNo   the number is SS_UniqueNumber.NextUniqueNumber
                                    for SectionId 2, and the counter moves on by
                                    one. The legacy reads and then updates with
                                    no lock; here it is one UPDATE ... OUTPUT
                                    under UPDLOCK, HOLDLOCK inside the raise's
                                    transaction, so a number is never handed out
                                    twice and never burned by a raise that fails.
                                    The numbers are unique across EVERY site on
                                    the customer's instance (4,851 for 4,851
                                    rows, 27 Sep 2026), so a number any site
                                    already holds is refused, not only this one.
     the INSERT                     PumpIT has no insert procedure: its client
                                    writes the row itself, in exactly the shape
                                    above (every column of every row checked,
                                    27 Sep 2026: a waiting short carries
                                    ApprovedByUserId NULL and DeclinedByUserId 0).

   WHAT IS WRITTEN WHERE THE LEGACY WROTE FREE TEXT
     Reason            the code's Label ('Pump short', 'Cashier short', 'Stock
                       loss', 'Other') — one of four words, where 2,619
                       different texts are on the customer's rows
     Note              the free note, or the label when there is none (the
                       legacy never leaves Note empty: 4,851 of 4,851)
     TransactionDate   the trading day the short belongs to, at midnight. The
                       legacy client writes the moment of capture; Agora is
                       told the day, and a cash-bag match's short is dated by
                       the cashier's last bag (the recon contract).
     CreatedByUserId   the person's PumpIT user id (agora.User.LegacyUserId),
                       NULL for somebody PumpIT never knew; Agora's own user is
                       on agora.StaffShort and the event.

   THE RULES, in order:
     · @WriteMode must be 'live'. The screens pass cash.staffshort.write_mode
       (a literal); a cash-bag match passes Recon's stamp mode, so a
       journalled match never gets here.
     · A site, a person, the day, and an amount above nil in rands and cents.
       The day may not be in the future.
     · The reason is one of agora.StaffShortReason's active codes; 'other'
       needs a note.
     · The person is on PumpIT's employee list at the site
       (BRN_Employee) — the legacy's approval list, its Daily Banking pool and
       the deduction schedule all join it, so a short against anyone else could
       never be approved, posted or deducted.
     · The cashup it is for (till and shift) is optional, and both or neither.
     · No enabled trigger on BRN_StaffShorts or SS_UniqueNumber.
     · A short raised from a cash-bag match (@Source 'recon') is raised once
       per line of the split (@SourceId, agora.ReconAllocation.Id).
     · From a screen, the same person, amount, day and reason still waiting
       for approval is refused, naming the number it would duplicate — a
       second press, not a second short.
     · Nothing is ever deleted. A short raised wrongly is declined.

   CALLED FROM ANOTHER PROCEDURE (agora.usp_Recon_ManualMatch): pass
   @Quiet = 1 and read the number from @StaffShortsNo OUTPUT. With @Quiet = 1
   no status row is returned, so the caller's own status row stays the first
   result set its caller reads. The transaction nests inside the caller's, and
   a refusal here rolls the caller's work back with it — a match that cannot
   raise its short is not made.

   TWO CLOCKS: Agora's rows are stamped SYSUTCDATETIME(); PumpIT's CreateDateTime
   keeps GETDATE(), which is what the customer's own screens read.

   Returns (unless @Quiet) the writer status row (Ok, Code, Message, Id = the
   staff-short number) with StaffShortsNo, EmployeeCode, EmployeeName,
   AmountShort, ReasonCode, ReasonLabel, TransactionDate and SaveRef.

   Refusals: CASH_SHORT_USER · CASH_WRITES_OFF · CASH_SHORT_KEY ·
             CASH_SHORT_AMOUNT · CASH_SHORT_DATE · CASH_SHORT_SOURCE ·
             CASH_SHORT_CASHUP · CASH_SHORT_REASON · CASH_SHORT_NOTE ·
             CASH_SHORT_EMPLOYEE · CASH_PUMPIT_TRIGGER · CASH_SHORT_DUPLICATE ·
             CASH_SHORT_COUNTER · CASH_SHORT_NUMBER_TAKEN

     EXEC agora.usp_Cash_RaiseStaffShort @BranchId = 999, @EmployeeCode = 'TSS01',
          @AmountShort = 120.00, @TransactionDate = '2026-09-20', @ReasonCode = 'pump_short',
          @Note = 'Pump 4, night shift', @TillNo = 51, @ShiftNo = 3, @WriteMode = 'live', @UserId = 1;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_RaiseStaffShort]
    @BranchId         INT,
    @EmployeeCode     NVARCHAR(50),
    @AmountShort      MONEY,
    @TransactionDate  DATE,
    @ReasonCode       VARCHAR(20),
    @Note             NVARCHAR(400) = NULL,
    @TillNo           INT           = NULL,
    @ShiftNo          INT           = NULL,
    @Source           VARCHAR(10)   = 'screen',
    @SourceId         BIGINT        = NULL,
    @SourceRef        NVARCHAR(100) = NULL,
    @WriteMode        NVARCHAR(10)  = 'off',
    @UserId           INT,
    @Quiet            BIT           = 0,
    @StaffShortsNo    INT           = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @EmployeeCode = NULLIF(LTRIM(RTRIM(@EmployeeCode)), N'');
    SET @Note         = NULLIF(LTRIM(RTRIM(@Note)), N'');
    SET @ReasonCode   = NULLIF(LTRIM(RTRIM(@ReasonCode)), '');
    SET @Source       = ISNULL(NULLIF(LTRIM(RTRIM(@Source)), ''), 'screen');
    SET @SourceRef    = NULLIF(LTRIM(RTRIM(@SourceRef)), N'');
    SET @StaffShortsNo = NULL;

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_USER:A staff short is raised by a person, and none was given.', 1;
    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:CASH_WRITES_OFF:Raising staff shorts in PumpIT is switched off. Nothing was written.', 1;
    IF @BranchId IS NULL OR @EmployeeCode IS NULL OR @TransactionDate IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_KEY:A staff short names the site, the person answerable for it and the day it belongs to. Give all three.', 1;
    IF LEN(@EmployeeCode) > 11
        THROW 51000, 'AGORA:CASH_SHORT_EMPLOYEE:An employee code is at most 11 characters in PumpIT. Nothing was written.', 1;
    IF @AmountShort IS NULL OR @AmountShort <= 0 OR ROUND(@AmountShort, 2) <> @AmountShort
        THROW 51000, 'AGORA:CASH_SHORT_AMOUNT:Give the amount short, above nil, in rands and cents.', 1;
    IF @TransactionDate > CONVERT(DATE, GETDATE())
        THROW 51000, 'AGORA:CASH_SHORT_DATE:A short belongs to a day that has happened. Give the day of the shift it came from.', 1;
    IF @Source NOT IN ('screen', 'phone', 'recon') OR (@Source = 'recon' AND @SourceId IS NULL)
        THROW 51000, 'AGORA:CASH_SHORT_SOURCE:A short is raised from the staff-shorts screen, the phone, or a cash-bag match''s split (with the line it came from).', 1;
    IF (@TillNo IS NULL AND @ShiftNo IS NOT NULL) OR (@TillNo IS NOT NULL AND @ShiftNo IS NULL)
       OR @TillNo <= 0 OR @ShiftNo <= 0
        THROW 51000, 'AGORA:CASH_SHORT_CASHUP:Name both the till and the shift of the cashup the short is for, or neither.', 1;

    DECLARE @Label NVARCHAR(60), @NeedsNote BIT;

    SELECT TOP 1 @Label = r.Label, @NeedsNote = r.NeedsNote
    FROM agora.StaffShortReason r
    WHERE r.Code = @ReasonCode AND r.IsActive = 1 AND r.DeletedAt IS NULL
    ORDER BY r.Id;

    IF @Label IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_REASON:Choose the reason from the list — pump short, cashier short, stock loss or other.', 1;
    IF @NeedsNote = 1 AND @Note IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_NOTE:"Other" says nothing on its own. Add a note saying what the short is for.', 1;

    DECLARE @Msg NVARCHAR(400), @EmployeeName NVARCHAR(120);

    SELECT TOP 1 @EmployeeName = ISNULL(NULLIF(LTRIM(RTRIM(ISNULL(e.Surname, N'') + N' ' + ISNULL(e.FirstName, N''))), N''), e.EmployeeCode)
    FROM [PumpIT].dbo.BRN_Employee e
    WHERE e.SSBranchId = @BranchId AND e.EmployeeCode = @EmployeeCode;

    IF @EmployeeName IS NULL
    BEGIN
        SET @Msg = CONCAT('AGORA:CASH_SHORT_EMPLOYEE:', @EmployeeCode, ' is not on PumpIT''s employee list at this site, so a short against it could never be approved, posted to a cashup or deducted. Nothing was written.');
        THROW 51000, @Msg, 1;
    END

    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id IN (OBJECT_ID('[PumpIT].dbo.BRN_StaffShorts'), OBJECT_ID('[PumpIT].dbo.SS_UniqueNumber'))
                 AND t.is_disabled = 0)
        THROW 51000, 'AGORA:CASH_PUMPIT_TRIGGER:A trigger is enabled on a PumpIT table this raise writes (BRN_StaffShorts or SS_UniqueNumber). Agora does not write through one. Nothing was written.', 1;

    DECLARE @ErpUserId INT = (SELECT TOP 1 u.LegacyUserId FROM agora.[User] u WHERE u.Id = @UserId);
    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Ref UNIQUEIDENTIFIER = NEWID();
    DECLARE @At DATETIME = @TransactionDate;
    DECLARE @PumpNote NVARCHAR(400) = ISNULL(@Note, @Label);
    DECLARE @No INT;
    DECLARE @Alloc TABLE (Prior INT, New INT);

    BEGIN TRY
        BEGIN TRANSACTION;

        /* ---- 1. Not raised already ---------------------------------------- */
        IF @SourceId IS NOT NULL
        BEGIN
            DECLARE @Already INT = (SELECT TOP 1 a.StaffShortsNo
                                    FROM agora.StaffShort a WITH (UPDLOCK, HOLDLOCK)
                                    WHERE a.BranchId = @BranchId AND a.Source = @Source AND a.SourceId = @SourceId);
            IF @Already IS NOT NULL
            BEGIN
                SET @Msg = CONCAT('AGORA:CASH_SHORT_DUPLICATE:Staff short ', @Already, ' was already raised from this line. Nothing was written.');
                THROW 51000, @Msg, 1;
            END
        END
        ELSE
        BEGIN
            DECLARE @Twin INT = (
                SELECT TOP 1 s.StaffShortsNo
                FROM agora.StaffShort a WITH (UPDLOCK, HOLDLOCK)
                JOIN [PumpIT].dbo.BRN_StaffShorts s
                  ON s.SSBranchId = a.BranchId AND s.StaffShortsNo = a.StaffShortsNo
                WHERE a.BranchId = @BranchId AND a.EmployeeCode = @EmployeeCode AND a.AmountShort = @AmountShort
                  AND a.TransactionDate = @TransactionDate AND a.ReasonCode = @ReasonCode
                  AND s.Approved = 0 AND s.IsDeclined = 0
                ORDER BY s.StaffShortsNo DESC);

            IF @Twin IS NOT NULL
            BEGIN
                SET @Msg = CONCAT('AGORA:CASH_SHORT_DUPLICATE:Staff short ', @Twin, ' already charges ', @EmployeeName, ' R',
                                  FORMAT(@AmountShort, 'N2'), ' for this day and reason, and is waiting for approval. Nothing was written.');
                THROW 51000, @Msg, 1;
            END
        END

        /* ---- 2. The number: dbo.sp_GenerateStaffShortsNo, under lock ------ */
        UPDATE u
           SET u.NextUniqueNumber = u.NextUniqueNumber + 1
        OUTPUT deleted.NextUniqueNumber, inserted.NextUniqueNumber INTO @Alloc (Prior, New)
        FROM [PumpIT].dbo.SS_UniqueNumber u WITH (UPDLOCK, HOLDLOCK)
        WHERE u.SectionId = 2;

        IF (SELECT COUNT(*) FROM @Alloc) <> 1
            THROW 51000, 'AGORA:CASH_SHORT_COUNTER:PumpIT''s staff-short counter (SS_UniqueNumber, section 2) is missing or held twice, so no number can be given. Nothing was written.', 1;

        SELECT @No = Prior FROM @Alloc;

        IF EXISTS (SELECT 1 FROM [PumpIT].dbo.BRN_StaffShorts WHERE StaffShortsNo = @No)
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_SHORT_NUMBER_TAKEN:PumpIT''s staff-short counter gave ', @No,
                              ', which a short already carries — the counter is behind the table. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        /* ---- 3. The short, as PumpIT's client writes it ------------------- */
        -- INSERT SSBranchId, StaffShortsNo, EmployeeCode, AmountShort, Note, Reason, TransactionDate,
        --        CreatedByUserId, Approved, ApprovedDate, ApprovedByUserId, IsDeclined, DeclinedReason,
        --        DeclinedByUserId, DeclinedDate, IsSelectedForDailyBanking, CreateDateTime
        INSERT INTO [PumpIT].dbo.BRN_StaffShorts
            (SSBranchId, StaffShortsNo, EmployeeCode, AmountShort, Note, Reason, TransactionDate, CreatedByUserId,
             Approved, ApprovedDate, ApprovedByUserId, IsDeclined, DeclinedReason, DeclinedByUserId, DeclinedDate,
             IsSelectedForDailyBanking, CreateDateTime)
        VALUES
            (@BranchId, @No, @EmployeeCode, @AmountShort, @PumpNote, @Label, @At, @ErpUserId,
             0, NULL, NULL, 0, NULL, 0, NULL,
             0, GETDATE());

        /* ---- 4. Agora's record, and the press ------------------------------ */
        INSERT INTO agora.StaffShort
            (BranchId, StaffShortsNo, EmployeeCode, AmountShort, TransactionDate, ReasonCode, Note, TillNo, ShiftNo,
             Source, SourceId, SourceRef, CreatedAt, CreatedBy)
        VALUES
            (@BranchId, @No, @EmployeeCode, @AmountShort, @TransactionDate, @ReasonCode, @Note, @TillNo, @ShiftNo,
             @Source, @SourceId, @SourceRef, @Now, @UserId);

        DECLARE @Writes NVARCHAR(MAX) = (
            SELECT
                JSON_QUERY((SELECT 'SS_UniqueNumber' AS [table], 2 AS sectionId, a.Prior AS prior, a.New AS new
                            FROM @Alloc a FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS counter,
                JSON_QUERY((
                    SELECT 'insert' AS [action], 'BRN_StaffShorts' AS [table],
                           JSON_QUERY((SELECT @BranchId AS SSBranchId, @No AS StaffShortsNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS [key],
                           JSON_QUERY((SELECT @EmployeeCode AS EmployeeCode, @AmountShort AS AmountShort, @PumpNote AS Note,
                                              @Label AS Reason, CONVERT(CHAR(10), @TransactionDate, 23) AS TransactionDate,
                                              @ErpUserId AS CreatedByUserId, 0 AS Approved, 0 AS IsDeclined,
                                              0 AS DeclinedByUserId, 0 AS IsSelectedForDailyBanking
                                       FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS new
                    FOR JSON PATH)) AS [rows]
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER);

        INSERT INTO agora.StaffShortEvent
            (BranchId, StaffShortsNo, [Event], EmployeeCode, Amount, ReasonCode, TransactionDate, TillNo, ShiftNo,
             WriteMode, SaveRef, WritesJson, Note, CreatedAt, CreatedBy)
        VALUES
            (@BranchId, @No, 'raised', @EmployeeCode, @AmountShort, @ReasonCode, @TransactionDate, @TillNo, @ShiftNo,
             'live', @Ref, @Writes, COALESCE(@Note, @SourceRef), @Now, @UserId);

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SET @StaffShortsNo = @No;

    IF ISNULL(@Quiet, 0) = 0
        SELECT
            CAST(1 AS BIT)                                          AS Ok,
            'CASH_SHORT_RAISED'                                     AS Code,
            CONVERT(NVARCHAR(400), CONCAT(
                'Staff short ', @No, ' raised in PumpIT: R', FORMAT(@AmountShort, 'N2'), ' against ', @EmployeeName,
                ' (', @EmployeeCode, '), ', LOWER(@Label), ', ', CONVERT(CHAR(10), @TransactionDate, 23),
                '. It waits for an approver.'))                     AS Message,
            @No                                                     AS Id,
            @No                                                     AS StaffShortsNo,
            @EmployeeCode                                           AS EmployeeCode,
            @EmployeeName                                           AS EmployeeName,
            @AmountShort                                            AS AmountShort,
            @ReasonCode                                             AS ReasonCode,
            @Label                                                  AS ReasonLabel,
            @TransactionDate                                        AS TransactionDate,
            @Ref                                                    AS SaveRef;
END
