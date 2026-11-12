/* Set the counting areas one employee may be signed on to (T023).
   A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's go-ahead, PM decision 01a1ceba-4d74
   (12 Nov 2026). PumpIT's stock-count sign-on reads BRN_EmployeeArea, so the
   change has to land there.

   @AreasJson is the WHOLE list the person should have, as a JSON array of
   AreaNo; @ExpectedAreasJson is the list the form showed. The difference is
   applied in one transaction:
     an area added    INSERT one [PumpIT].dbo.BRN_EmployeeArea row
                      (BranchId, EmployeeCode, AreaNo; MIST_EOD left NULL)
     an area removed  DELETE that row — the whole row (Autoidx, MIST_EOD)
                      kept in agora.PeopleWrite.RowJson first
   and one agora.PeopleWrite row per area, so agora.usp_People_ReverseWrite can
   put each back. Nothing else is written.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (people.areas.edit), REASON_REQUIRED, NOT_FOUND,
     SYSTEM_ACCOUNT  as usp_People_SaveEmployee
     BAD_AREAS       either list is not a JSON array of numbers
     STALE           the areas PumpIT holds are not the ones the form showed
     UNKNOWN_AREA    an added area is not a counting area at this branch
   Nothing changed: Code NOTHING_CHANGED, nothing written.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/People/Services/EmployeeWriteService. Deployed by v1__03pa. */
CREATE OR ALTER PROCEDURE agora.usp_People_SaveEmployeeAreas
    @BranchId INT,
    @EmployeeCode NVARCHAR(11),
    @AreasJson NVARCHAR(MAX),
    @ExpectedAreasJson NVARCHAR(MAX),
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
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing employee areas is switched off (people.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change employee areas.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the areas are being changed — it is kept with the change.', 1;
    IF NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId)
        THROW 51000, 'AGORA:NOT_FOUND:There is no such employee at a site you may see.', 1;
    IF ISJSON(ISNULL(@AreasJson, N'')) = 0 OR ISJSON(ISNULL(@ExpectedAreasJson, N'')) = 0
       OR EXISTS (SELECT 1 FROM OPENJSON(@AreasJson) WHERE TRY_CONVERT(INT, value) IS NULL OR type <> 2)
       OR EXISTS (SELECT 1 FROM OPENJSON(@ExpectedAreasJson) WHERE TRY_CONVERT(INT, value) IS NULL OR type <> 2)
        THROW 51000, 'AGORA:BAD_AREAS:The areas did not arrive as a list of area numbers.', 1;

    DECLARE @Want TABLE (AreaNo INT PRIMARY KEY);
    INSERT INTO @Want SELECT DISTINCT TRY_CONVERT(INT, value) FROM OPENJSON(@AreasJson);
    DECLARE @Shown TABLE (AreaNo INT PRIMARY KEY);
    INSERT INTO @Shown SELECT DISTINCT TRY_CONVERT(INT, value) FROM OPENJSON(@ExpectedAreasJson);

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Added INT = 0, @Removed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Code NVARCHAR(11), @Surname NVARCHAR(20), @First NVARCHAR(35);
    SELECT @Code = e.EmployeeCode, @Surname = e.Surname, @First = e.FirstName
    FROM [PumpIT].dbo.BRN_Employee e WITH (UPDLOCK, HOLDLOCK)
    WHERE e.SSBranchId = @BranchId AND e.EmployeeCode = @EmployeeCode;

    IF @Code IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such employee at a site you may see.', 1;
    IF UPPER(LTRIM(RTRIM(@Code))) = N'PUMPIT' OR UPPER(LTRIM(RTRIM(@Surname))) = N'ADMIN' OR UPPER(LTRIM(RTRIM(ISNULL(@First, N'')))) = N'PUMPIT'
        THROW 51000, 'AGORA:SYSTEM_ACCOUNT:This is one of PumpIT''s system accounts, not a person. It is not changed from Agora.', 1;

    DECLARE @Have TABLE (Autoidx INT PRIMARY KEY, AreaNo INT, EmployeeCode NVARCHAR(11), MIST_EOD VARCHAR(10));
    INSERT INTO @Have
    SELECT a.Autoidx, a.AreaNo, a.EmployeeCode, a.MIST_EOD
    FROM [PumpIT].dbo.BRN_EmployeeArea a WITH (UPDLOCK, HOLDLOCK)
    WHERE a.BranchId = @BranchId AND a.EmployeeCode = @EmployeeCode;

    IF EXISTS (SELECT AreaNo FROM @Have EXCEPT SELECT AreaNo FROM @Shown)
       OR EXISTS (SELECT AreaNo FROM @Shown EXCEPT SELECT AreaNo FROM @Have)
        THROW 51000, 'AGORA:STALE:This person''s areas were changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    IF EXISTS (SELECT 1 FROM @Want w WHERE NOT EXISTS (SELECT 1 FROM @Have h WHERE h.AreaNo = w.AreaNo)
                                       AND NOT EXISTS (SELECT 1 FROM [PumpIT].dbo.STK_Area s WHERE s.SSBranchId = @BranchId AND s.AreaNo = w.AreaNo))
        THROW 51000, 'AGORA:UNKNOWN_AREA:One of those areas is not a counting area at this site.', 1;

    /* Removed: every row of an area no longer wanted, kept whole first. */
    INSERT INTO agora.PeopleWrite (BranchId, WriteId, Verb, PumpItTable, EmployeeCode, AreaNo, Field, PriorValue, NewValue,
                                   RowJson, Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT @BranchId, @WriteId, 'area-remove', 'BRN_EmployeeArea', @EmployeeCode, h.AreaNo, N'EmployeeArea',
           N'assigned', NULL,
           (SELECT h.Autoidx AS Autoidx, h.EmployeeCode AS EmployeeCode, h.AreaNo AS AreaNo, h.MIST_EOD AS MIST_EOD
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES),
           @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM @Have h
    WHERE NOT EXISTS (SELECT 1 FROM @Want w WHERE w.AreaNo = h.AreaNo);
    SET @Removed = @@ROWCOUNT;

    DELETE a
      FROM [PumpIT].dbo.BRN_EmployeeArea a
      JOIN @Have h ON h.Autoidx = a.Autoidx
     WHERE NOT EXISTS (SELECT 1 FROM @Want w WHERE w.AreaNo = h.AreaNo);

    /* Added. */
    DECLARE @New TABLE (Autoidx INT, AreaNo INT);
    INSERT INTO [PumpIT].dbo.BRN_EmployeeArea (BranchId, EmployeeCode, AreaNo)
    OUTPUT inserted.Autoidx, inserted.AreaNo INTO @New (Autoidx, AreaNo)
    SELECT @BranchId, @EmployeeCode, w.AreaNo
    FROM @Want w
    WHERE NOT EXISTS (SELECT 1 FROM @Have h WHERE h.AreaNo = w.AreaNo);
    SET @Added = @@ROWCOUNT;

    INSERT INTO agora.PeopleWrite (BranchId, WriteId, Verb, PumpItTable, EmployeeCode, AreaNo, Field, PriorValue, NewValue,
                                   RowJson, Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT @BranchId, @WriteId, 'area-add', 'BRN_EmployeeArea', @EmployeeCode, n.AreaNo, N'EmployeeArea',
           NULL, N'assigned', (SELECT n.Autoidx AS Autoidx FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM @New n;

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(BIT, 1) AS Ok,
           CASE WHEN @Added + @Removed = 0 THEN 'NOTHING_CHANGED' ELSE 'SAVED' END AS Code,
           CASE WHEN @Added + @Removed = 0 THEN N'Nothing changed — the areas matched PumpIT.'
                ELSE CONCAT(@Added, N' area(s) added and ', @Removed, N' removed in PumpIT, each kept in the ledger.') END AS Message,
           CASE WHEN @Added + @Removed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
