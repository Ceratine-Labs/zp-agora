/* Add a post at one branch to PumpIT's BRN_EmployeePost (T023).
   A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's go-ahead, PM decision 01a1ceba-4d74
   (12 Nov 2026).

   WRITES one INSERT into [PumpIT].dbo.BRN_EmployeePost (SSBranchId, PostNo,
   PostDescription, StockControl, CreateDateTime — PumpIT's own local clock,
   as PumpIT stamps its rows) and one agora.PeopleWrite row. Nothing else.

   The number is agora.usp_People_NextPost's rule — the branch's highest
   PostNo plus one, or 1 — taken HERE, inside the transaction, under
   UPDLOCK + HOLDLOCK on the branch's posts, so two people adding a post at
   once cannot be given the same number. usp_People_NextPost itself only
   shows the number and reserves nothing.

   A post added here is not removed by usp_People_ReverseWrite: a post can be
   held by somebody the moment it exists, and taking it away is a DELETE no
   one has named. It is refused there (NOT_REVERSIBLE).

   Refuses (AGORA:{Code}):
     WRITES_OFF, REASON_REQUIRED, NOT_FOUND (a site outside @AllowedBranchIds)
     DENIED          the caller lacks people.posts.save
     NAME_REQUIRED   no description
     DUPLICATE_POST  the branch already has a post of that name
     NO_NUMBER       the branch has used every SMALLINT post number

   Returns one row (Ok, Code, Message, WriteId, PostNo).
   Called by Modules/People/Services/EmployeeWriteService. Deployed by v1__03pa. */
CREATE OR ALTER PROCEDURE agora.usp_People_AddPost
    @BranchId INT,
    @PostDescription NVARCHAR(50),
    @StockControl BIT = 0,
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

    SET @PostDescription = NULLIF(LTRIM(RTRIM(@PostDescription)), N'');
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Adding posts is switched off (people.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not add posts.', 1;
    IF @PostDescription IS NULL
        THROW 51000, 'AGORA:NAME_REQUIRED:Name the post.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the post is being added — it is kept with the change.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:That is not a site you may see.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Next INT;

    BEGIN TRY
    BEGIN TRANSACTION;

    SELECT @Next = ISNULL(MAX(p.PostNo) + 1, 1)
    FROM [PumpIT].dbo.BRN_EmployeePost p WITH (UPDLOCK, HOLDLOCK)
    WHERE p.SSBranchId = @BranchId;

    IF EXISTS (SELECT 1 FROM [PumpIT].dbo.BRN_EmployeePost p
               WHERE p.SSBranchId = @BranchId AND LTRIM(RTRIM(p.PostDescription)) = @PostDescription)
        THROW 51000, 'AGORA:DUPLICATE_POST:This site already has a post of that name.', 1;
    IF @Next > 32767
        THROW 51000, 'AGORA:NO_NUMBER:This site has no post numbers left.', 1;

    INSERT INTO [PumpIT].dbo.BRN_EmployeePost (SSBranchId, PostNo, PostDescription, StockControl, CreateDateTime)
    VALUES (@BranchId, CONVERT(SMALLINT, @Next), @PostDescription, ISNULL(@StockControl, 0), GETDATE());

    INSERT INTO agora.PeopleWrite (BranchId, WriteId, Verb, PumpItTable, PostNo, Field, PriorValue, NewValue,
                                   Reason, WriteMode, State, CreatedAt, CreatedBy)
    VALUES (@BranchId, @WriteId, 'post-add', 'BRN_EmployeePost', CONVERT(SMALLINT, @Next), N'EmployeePost', NULL,
            CONCAT(@PostDescription, CASE WHEN ISNULL(@StockControl, 0) = 1 THEN N' (stock control)' ELSE N'' END),
            @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId);

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(BIT, 1) AS Ok, 'SAVED' AS Code,
           CONCAT(N'Post ', @Next, N' — ', @PostDescription, N' — added in PumpIT.') AS Message,
           @WriteId AS WriteId, CONVERT(SMALLINT, @Next) AS PostNo;
END
