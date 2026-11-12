/* Put back one press of an employee edit (T023). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's go-ahead, PM decision 01a1ceba-4d74
   (12 Nov 2026). The reversal of usp_People_SaveEmployee and
   usp_People_SaveEmployeeAreas, from their own ledger, agora.PeopleWrite:

     employee     [PumpIT].dbo.BRN_Employee IsActive / PostNo / NickName set
                  back to PriorValue, on the one row, by its primary key
     area-add     the BRN_EmployeeArea row it inserted is deleted
     area-remove  the row it deleted is inserted again (BranchId,
                  EmployeeCode, AreaNo, MIST_EOD from RowJson; PumpIT gives
                  it a new Autoidx)

   All rows of the press (@WriteId) or none, in one transaction; each ledger
   row is marked reversed with who, when and why.

   Refuses (AGORA:{Code}):
     WRITES_OFF, REASON_REQUIRED, NOT_FOUND (also a site outside
     @AllowedBranchIds)
     DENIED            @AuthorizedEmployee / @AuthorizedAreas does not cover
                       what the press wrote (people.employees.edit,
                       people.areas.edit)
     ALREADY_REVERSED  nothing of this press is still in force
     NOT_REVERSIBLE    the press added a post (usp_People_AddPost): taking a
                       post away is not a named write
     MOVED             PumpIT no longer holds what the press wrote — somebody
                       changed it since — so putting the prior back would undo
                       their change too. Nothing is written.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/People/Services/EmployeeWriteService. Deployed by v1__03pa. */
CREATE OR ALTER PROCEDURE agora.usp_People_ReverseWrite
    @BranchId INT,
    @WriteId UNIQUEIDENTIFIER,
    @Reason NVARCHAR(400),
    @UserId INT,
    @AuthorizedEmployee BIT = 0,
    @AuthorizedAreas BIT = 0,
    @WriteMode VARCHAR(10) = 'off',
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @ReasonMin INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing employees is switched off (people.write_mode). Nothing was written.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the change is being put back — it is kept with the reversal.', 1;
    IF NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId)
        THROW 51000, 'AGORA:NOT_FOUND:There is no such change at a site you may see.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.PeopleWrite WHERE BranchId = @BranchId AND WriteId = @WriteId)
        THROW 51000, 'AGORA:NOT_FOUND:There is no such change at a site you may see.', 1;
    IF EXISTS (SELECT 1 FROM agora.PeopleWrite WHERE BranchId = @BranchId AND WriteId = @WriteId AND Verb = 'post-add')
        THROW 51000, 'AGORA:NOT_REVERSIBLE:A post added to PumpIT is not taken away from Agora. Retire it in PumpIT if it is wrong.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.PeopleWrite WHERE BranchId = @BranchId AND WriteId = @WriteId AND State = 'active')
        THROW 51000, 'AGORA:ALREADY_REVERSED:This change has already been put back.', 1;
    IF (EXISTS (SELECT 1 FROM agora.PeopleWrite WHERE BranchId = @BranchId AND WriteId = @WriteId AND Verb = 'employee')
        AND ISNULL(@AuthorizedEmployee, 0) = 0)
       OR (EXISTS (SELECT 1 FROM agora.PeopleWrite WHERE BranchId = @BranchId AND WriteId = @WriteId AND Verb LIKE 'area-%')
        AND ISNULL(@AuthorizedAreas, 0) = 0)
        THROW 51000, 'AGORA:DENIED:You may not put this change back.', 1;

    DECLARE @Rows TABLE (Id BIGINT PRIMARY KEY, Verb VARCHAR(12), EmployeeCode NVARCHAR(11), AreaNo INT, Field NVARCHAR(40),
                         PriorValue NVARCHAR(100), NewValue NVARCHAR(100), RowJson NVARCHAR(1000));
    DECLARE @Count INT;

    BEGIN TRY
    BEGIN TRANSACTION;

    INSERT INTO @Rows
    SELECT w.Id, w.Verb, w.EmployeeCode, w.AreaNo, w.Field, w.PriorValue, w.NewValue, w.RowJson
    FROM agora.PeopleWrite w WITH (UPDLOCK, HOLDLOCK)
    WHERE w.BranchId = @BranchId AND w.WriteId = @WriteId AND w.State = 'active';
    SET @Count = @@ROWCOUNT;

    IF @Count = 0
        THROW 51000, 'AGORA:ALREADY_REVERSED:This change has already been put back.', 1;

    /* The employee columns: PumpIT must still hold exactly what was written. */
    DECLARE @Code NVARCHAR(11) = (SELECT TOP 1 EmployeeCode FROM @Rows WHERE Verb = 'employee');
    IF @Code IS NOT NULL
    BEGIN
        DECLARE @CurActive BIT, @CurPost SMALLINT, @CurNick NVARCHAR(35), @Found NVARCHAR(11);
        SELECT @Found = e.EmployeeCode, @CurActive = e.IsActive, @CurPost = e.PostNo, @CurNick = e.NickName
        FROM [PumpIT].dbo.BRN_Employee e WITH (UPDLOCK, HOLDLOCK)
        WHERE e.SSBranchId = @BranchId AND e.EmployeeCode = @Code;

        IF @Found IS NULL OR EXISTS (
            SELECT 1 FROM @Rows r WHERE r.Verb = 'employee' AND ISNULL(r.NewValue, N'') <> ISNULL(CASE r.Field
                WHEN N'IsActive' THEN CONVERT(NVARCHAR(100), CONVERT(INT, @CurActive))
                WHEN N'PostNo'   THEN CONVERT(NVARCHAR(100), @CurPost)
                WHEN N'NickName' THEN CONVERT(NVARCHAR(100), NULLIF(LTRIM(RTRIM(@CurNick)), N'')) END, N''))
            THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has changed the employee since. Nothing was put back.', 1;

        UPDATE e
           SET IsActive = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Verb = 'employee' AND r.Field = N'IsActive')
                               THEN (SELECT CONVERT(BIT, r.PriorValue) FROM @Rows r WHERE r.Verb = 'employee' AND r.Field = N'IsActive')
                               ELSE e.IsActive END,
               PostNo   = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Verb = 'employee' AND r.Field = N'PostNo')
                               THEN (SELECT CONVERT(SMALLINT, r.PriorValue) FROM @Rows r WHERE r.Verb = 'employee' AND r.Field = N'PostNo')
                               ELSE e.PostNo END,
               NickName = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Verb = 'employee' AND r.Field = N'NickName')
                               THEN (SELECT CONVERT(NVARCHAR(35), r.PriorValue) FROM @Rows r WHERE r.Verb = 'employee' AND r.Field = N'NickName')
                               ELSE e.NickName END
          FROM [PumpIT].dbo.BRN_Employee e
         WHERE e.SSBranchId = @BranchId AND e.EmployeeCode = @Code;
    END

    /* The areas: an added row must still be there, a removed one still gone. */
    IF EXISTS (SELECT 1 FROM @Rows r WHERE r.Verb = 'area-add' AND NOT EXISTS (
                   SELECT 1 FROM [PumpIT].dbo.BRN_EmployeeArea a WITH (UPDLOCK, HOLDLOCK)
                   WHERE a.BranchId = @BranchId AND a.EmployeeCode = r.EmployeeCode AND a.AreaNo = r.AreaNo))
       OR EXISTS (SELECT 1 FROM @Rows r WHERE r.Verb = 'area-remove' AND EXISTS (
                   SELECT 1 FROM [PumpIT].dbo.BRN_EmployeeArea a WITH (UPDLOCK, HOLDLOCK)
                   WHERE a.BranchId = @BranchId AND a.EmployeeCode = r.EmployeeCode AND a.AreaNo = r.AreaNo))
        THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has changed the areas since. Nothing was put back.', 1;

    DELETE a
      FROM [PumpIT].dbo.BRN_EmployeeArea a
      JOIN @Rows r ON r.Verb = 'area-add' AND a.BranchId = @BranchId
                  AND a.Autoidx = TRY_CONVERT(INT, JSON_VALUE(r.RowJson, '$.Autoidx'))
                  AND a.EmployeeCode = r.EmployeeCode AND a.AreaNo = r.AreaNo;

    IF @@ROWCOUNT <> (SELECT COUNT(*) FROM @Rows WHERE Verb = 'area-add')
        THROW 51000, 'AGORA:MOVED:An area this change added is no longer the row it wrote. Nothing was put back.', 1;

    INSERT INTO [PumpIT].dbo.BRN_EmployeeArea (BranchId, EmployeeCode, AreaNo, MIST_EOD)
    SELECT @BranchId, ISNULL(JSON_VALUE(r.RowJson, '$.EmployeeCode'), r.EmployeeCode), r.AreaNo,
           JSON_VALUE(r.RowJson, '$.MIST_EOD')
    FROM @Rows r
    WHERE r.Verb = 'area-remove';

    UPDATE w
       SET State = 'reversed', ReversedAt = SYSUTCDATETIME(), ReversedBy = @UserId, ReversalReason = @Reason,
           UpdatedAt = SYSUTCDATETIME(), UpdatedBy = @UserId
      FROM agora.PeopleWrite w
      JOIN @Rows r ON r.Id = w.Id
     WHERE w.BranchId = @BranchId;

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(BIT, 1) AS Ok, 'REVERSED' AS Code,
           CONCAT(@Count, N' change(s) put back in PumpIT.') AS Message, @WriteId AS WriteId;
END
