/* Change one employee in PumpIT's BRN_Employee (T023). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's go-ahead, PM decision 01a1ceba-4d74
   (12 Nov 2026). PumpIT keeps trading on these rows, so an edit in Agora
   writes them back rather than keeping a copy that drifts.

   WRITES exactly [PumpIT].dbo.BRN_Employee.IsActive, .PostNo and .NickName on
   ONE row, by its primary key (SSBranchId, EmployeeCode), in one
   transaction — and one agora.PeopleWrite row per column it changed, with
   the prior value as it was stored and the new one, so
   agora.usp_People_ReverseWrite can put it back. Nothing else in PumpIT is
   touched: not the name, not the dates, not the code.

   Refuses (AGORA:{Code}):
     WRITES_OFF      @WriteMode is not 'live' (config people.write_mode)
     DENIED          the caller lacks people.employees.edit (@Authorized)
     REASON_REQUIRED fewer than @ReasonMin characters of reason
     NOT_FOUND       no such employee, or a site outside @AllowedBranchIds
     SYSTEM_ACCOUNT  a PUMPIT / ADMIN row — not a person
     STALE           the row changed since the form was drawn: the
                     @Expected* values are what the form showed
     UNKNOWN_POST    @PostNo is not a post at this branch (BRN_EmployeePost)
   Nothing changed is not a refusal: Code NOTHING_CHANGED, nothing written.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/People/Services/EmployeeWriteService. Deployed by v1__03pa. */
CREATE OR ALTER PROCEDURE agora.usp_People_SaveEmployee
    @BranchId INT,
    @EmployeeCode NVARCHAR(11),
    @IsActive BIT,
    @PostNo SMALLINT,
    @NickName NVARCHAR(35) = NULL,
    @ExpectedIsActive BIT,
    @ExpectedPostNo SMALLINT,
    @ExpectedNickName NVARCHAR(35) = NULL,
    @Reason NVARCHAR(400),
    @UserId INT,
    @Authorized BIT = 0,
    @WriteMode VARCHAR(10) = 'off',
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @ReasonMin INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @EmployeeCode = LTRIM(RTRIM(@EmployeeCode));
    SET @NickName = NULLIF(LTRIM(RTRIM(@NickName)), N'');
    SET @ExpectedNickName = NULLIF(LTRIM(RTRIM(@ExpectedNickName)), N'');
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing employees is switched off (people.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change employees.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the employee is being changed — it is kept with the change.', 1;
    IF NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId)
        THROW 51000, 'AGORA:NOT_FOUND:There is no such employee at a site you may see.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @CurActive BIT, @CurPost SMALLINT, @CurNick NVARCHAR(35), @Code NVARCHAR(11), @Surname NVARCHAR(20), @First NVARCHAR(35);

    SELECT @CurActive = e.IsActive, @CurPost = e.PostNo, @CurNick = e.NickName, @Code = e.EmployeeCode,
           @Surname = e.Surname, @First = e.FirstName
    FROM [PumpIT].dbo.BRN_Employee e WITH (UPDLOCK, HOLDLOCK)
    WHERE e.SSBranchId = @BranchId AND e.EmployeeCode = @EmployeeCode;

    IF @Code IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such employee at a site you may see.', 1;
    IF UPPER(LTRIM(RTRIM(@Code))) = N'PUMPIT' OR UPPER(LTRIM(RTRIM(@Surname))) = N'ADMIN' OR UPPER(LTRIM(RTRIM(ISNULL(@First, N'')))) = N'PUMPIT'
        THROW 51000, 'AGORA:SYSTEM_ACCOUNT:This is one of PumpIT''s system accounts, not a person. It is not changed from Agora.', 1;
    IF @CurActive <> @ExpectedIsActive OR @CurPost <> @ExpectedPostNo
       OR ISNULL(NULLIF(LTRIM(RTRIM(@CurNick)), N''), N'') <> ISNULL(@ExpectedNickName, N'')
        THROW 51000, 'AGORA:STALE:This employee was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;
    IF @PostNo <> @CurPost AND NOT EXISTS (
            SELECT 1 FROM [PumpIT].dbo.BRN_EmployeePost p WHERE p.SSBranchId = @BranchId AND p.PostNo = @PostNo)
        THROW 51000, 'AGORA:UNKNOWN_POST:That post does not exist at this site.', 1;

    INSERT INTO agora.PeopleWrite (BranchId, WriteId, Verb, PumpItTable, EmployeeCode, PostNo, Field, PriorValue, NewValue,
                                   Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT @BranchId, @WriteId, 'employee', 'BRN_Employee', @EmployeeCode, @PostNo, c.Field, c.PriorValue, c.NewValue,
           @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM (VALUES
            (N'IsActive', CONVERT(NVARCHAR(100), CONVERT(INT, @CurActive)), CONVERT(NVARCHAR(100), CONVERT(INT, @IsActive)),
             CASE WHEN @CurActive <> @IsActive THEN 1 ELSE 0 END),
            (N'PostNo', CONVERT(NVARCHAR(100), @CurPost), CONVERT(NVARCHAR(100), @PostNo),
             CASE WHEN @CurPost <> @PostNo THEN 1 ELSE 0 END),
            (N'NickName', CONVERT(NVARCHAR(100), @CurNick), CONVERT(NVARCHAR(100), @NickName),
             CASE WHEN ISNULL(NULLIF(LTRIM(RTRIM(@CurNick)), N''), N'') <> ISNULL(@NickName, N'') THEN 1 ELSE 0 END)
         ) c (Field, PriorValue, NewValue, IsChanged)
    WHERE c.IsChanged = 1;

    SET @Changed = @@ROWCOUNT;

    IF @Changed > 0
        UPDATE e
           SET IsActive = @IsActive,
               PostNo   = @PostNo,
               NickName = CASE WHEN ISNULL(NULLIF(LTRIM(RTRIM(@CurNick)), N''), N'') <> ISNULL(@NickName, N'')
                               THEN @NickName ELSE e.NickName END
          FROM [PumpIT].dbo.BRN_Employee e
         WHERE e.SSBranchId = @BranchId AND e.EmployeeCode = @EmployeeCode;

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(BIT, 1) AS Ok,
           CASE WHEN @Changed = 0 THEN 'NOTHING_CHANGED' ELSE 'SAVED' END AS Code,
           CASE WHEN @Changed = 0 THEN N'Nothing changed — the form matched PumpIT.'
                ELSE CONCAT(@Changed, N' change(s) written to PumpIT, each kept with its prior value.') END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
