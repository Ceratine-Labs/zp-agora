/* Change a setting's group value, or add, change or remove one site's value
   (T026). Owns the rules.

   Writes agora.Setting and one agora.MasterChange row per change (Entity
   'Setting', EntityId = the Setting row's Id, BranchId = the row's branch),
   atomically. NEVER writes PumpIT.

   @Mode
     edit    change the value of an existing row — the group's
             (@BranchId = @GroupBranchId) or a site's
     create  give a site its own value. A site whose value was removed before
             gets that row back rather than a second one.
     remove  take a site's own value away; it falls back to the group's. The
             row is soft-deleted, never deleted.

   Rules:
     - @Authorized comes from the authenticated permission check:
       setup.settings.edit for edit, .save for create, .delete for remove.
     - The definition is the GROUP row's: type, choices, bounds, whether it
       may be empty and whether a site may override it. A site row copies it.
     - A copy of PumpIT's own value (key pumpit.*) is read-only: Agora reads
       none of them and an edit here would change nothing in PumpIT.
     - The group's row is never created or removed here, only changed.
     - A site may override only where AllowsBranch = 1, and only a branch
       that exists.
     - A value is checked against its type and normalised: int a whole number;
       money to the cent, litres to the millilitre, share 0-1 to four places,
       decimal to four; bool 1/0 (yes/no, true/false accepted); a choice one of
       Choices; and inside MinValue..MaxValue. Empty is NULL, refused unless
       IsNullable.
     - Every change carries a reason of at least 10 characters, and a change
       or removal hands back the RowVer it was opened with (@ExpectedVersion).

   MasterChange Field: 'Value' for an edit (before -> after); 'Override' for a
   site value added (NULL -> value) or removed (value -> NULL).

   Returns (Ok, Code, Message, Id). Read by Modules/Core/Services/SettingService.
   Deployed by v1__01pj. */
CREATE OR ALTER PROCEDURE agora.usp_Core_SaveSetting
    @SettingKey NVARCHAR(120),
    @BranchId INT,
    @Mode NVARCHAR(10),
    @Value NVARCHAR(400) = NULL,
    @ExpectedVersion VARCHAR(18) = NULL,
    @Reason NVARCHAR(400),
    @UserId INT = NULL,
    @Authorized BIT = 0,
    @GroupBranchId INT = 2
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF ISNULL(@Authorized, 0) <> 1
        THROW 51000, 'AGORA:SETTING_DENIED:You do not have permission to change this setting.', 1;
    IF @Mode IS NULL OR @Mode NOT IN (N'edit', N'create', N'remove')
        THROW 51000, 'AGORA:SETTING_MODE:Change a value, add a site''s value or remove one.', 1;

    SET @SettingKey = NULLIF(LTRIM(RTRIM(@SettingKey)), '');
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), '');
    SET @Value = NULLIF(LTRIM(RTRIM(@Value)), '');
    IF @Reason IS NULL OR LEN(@Reason) < 10
        THROW 51000, 'AGORA:SETTING_REASON:Say why, in at least 10 characters.', 1;
    IF @SettingKey LIKE N'pumpit.%'
        THROW 51000, 'AGORA:SETTING_READ_ONLY:This is a copy of PumpIT''s own value. Agora does not read it and does not write PumpIT, so it cannot be changed here.', 1;

    DECLARE @Type NVARCHAR(20), @Description NVARCHAR(400), @Choices NVARCHAR(200), @Min DECIMAL(18, 4), @Max DECIMAL(18, 4),
            @IsNullable BIT, @AllowsBranch BIT, @UsedBy NVARCHAR(200), @GroupValue NVARCHAR(400);

    SELECT @Type = Type, @Description = Description, @Choices = Choices, @Min = MinValue, @Max = MaxValue,
           @IsNullable = IsNullable, @AllowsBranch = AllowsBranch, @UsedBy = UsedBy, @GroupValue = Value
    FROM agora.Setting
    WHERE BranchId = @GroupBranchId AND SettingKey = @SettingKey AND DeletedAt IS NULL;

    IF @Type IS NULL
        THROW 51000, 'AGORA:SETTING_MISSING:There is no such setting.', 1;

    IF @BranchId = @GroupBranchId AND @Mode <> N'edit'
        THROW 51000, 'AGORA:SETTING_GROUP_ROW:The group value can be changed, but not added or removed.', 1;
    IF @BranchId <> @GroupBranchId AND ISNULL(@AllowsBranch, 0) = 0
        THROW 51000, 'AGORA:SETTING_NO_OVERRIDE:This setting has one value for every site. Change the group value instead.', 1;
    IF @BranchId <> @GroupBranchId AND NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId AND DeletedAt IS NULL)
        THROW 51000, 'AGORA:SETTING_BRANCH:Choose a branch that exists.', 1;

    /* The value, checked against the type and normalised. */
    DECLARE @Number DECIMAL(18, 4), @Normal NVARCHAR(400);
    IF @Mode <> N'remove'
    BEGIN
        IF @Value IS NULL AND ISNULL(@IsNullable, 0) = 0
            THROW 51000, 'AGORA:SETTING_REQUIRED:This setting needs a value.', 1;

        IF @Value IS NOT NULL
        BEGIN
            IF @Type = N'int'
            BEGIN
                IF TRY_CONVERT(INT, @Value) IS NULL
                    THROW 51000, 'AGORA:SETTING_TYPE:Give a whole number.', 1;
                SET @Number = TRY_CONVERT(INT, @Value);
                SET @Normal = CONVERT(NVARCHAR(40), CONVERT(INT, @Number));
            END
            ELSE IF @Type IN (N'decimal', N'money', N'litres', N'share')
            BEGIN
                SET @Number = TRY_CONVERT(DECIMAL(18, 4), @Value);
                IF @Number IS NULL
                    THROW 51000, 'AGORA:SETTING_TYPE:Give a number.', 1;
                IF @Type = N'money' AND @Number <> ROUND(@Number, 2)
                    THROW 51000, 'AGORA:SETTING_TYPE:Give an amount to the cent.', 1;
                IF @Type = N'litres' AND @Number <> ROUND(@Number, 3)
                    THROW 51000, 'AGORA:SETTING_TYPE:Give litres to three decimal places at most.', 1;
                IF @Type = N'share' AND (@Number < 0 OR @Number > 1)
                    THROW 51000, 'AGORA:SETTING_RANGE:A share is between 0 and 1: 0.75 is 75 per cent.', 1;
                SET @Normal = CASE @Type
                    WHEN N'money'  THEN CONVERT(NVARCHAR(40), CONVERT(DECIMAL(18, 2), @Number))
                    WHEN N'litres' THEN CONVERT(NVARCHAR(40), CONVERT(DECIMAL(18, 3), @Number))
                    ELSE CONVERT(NVARCHAR(40), @Number) END;
            END
            ELSE IF @Type = N'bool'
            BEGIN
                SET @Normal = CASE LOWER(@Value) WHEN N'1' THEN N'1' WHEN N'true' THEN N'1' WHEN N'yes' THEN N'1'
                                                 WHEN N'0' THEN N'0' WHEN N'false' THEN N'0' WHEN N'no' THEN N'0' END;
                IF @Normal IS NULL
                    THROW 51000, 'AGORA:SETTING_TYPE:Give yes or no.', 1;
            END
            ELSE IF @Type = N'choice'
            BEGIN
                -- Stored as the list spells it, whatever case it arrived in.
                SET @Normal = (SELECT TOP (1) LTRIM(RTRIM(value)) FROM STRING_SPLIT(@Choices, N'|') WHERE LTRIM(RTRIM(value)) = @Value);
                IF @Normal IS NULL
                    THROW 51000, 'AGORA:SETTING_CHOICE:Choose one of the values offered.', 1;
            END
            ELSE
                SET @Normal = @Value;

            IF @Number IS NOT NULL AND ((@Min IS NOT NULL AND @Number < @Min) OR (@Max IS NOT NULL AND @Number > @Max))
            BEGIN
                DECLARE @Range NVARCHAR(400) = CONCAT(N'AGORA:SETTING_RANGE:Give a value from ',
                    FORMAT(@Min, N'0.####'), N' to ', FORMAT(@Max, N'0.####'), N'.');
                THROW 51000, @Range, 1;
            END
        END
    END

    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Id BIGINT, @Version VARCHAR(18), @Old NVARCHAR(400), @Deleted DATETIME2(0), @Changed INT = 0;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @Id = Id, @Version = CONVERT(VARCHAR(18), CONVERT(BINARY(8), RowVer), 1), @Old = Value, @Deleted = DeletedAt
        FROM agora.Setting WITH (UPDLOCK, HOLDLOCK)
        WHERE BranchId = @BranchId AND SettingKey = @SettingKey;

        IF @Mode IN (N'edit', N'remove') AND (@Id IS NULL OR @Deleted IS NOT NULL)
            THROW 51000, 'AGORA:SETTING_MISSING:That value no longer exists. Reload the page.', 1;
        IF @Mode IN (N'edit', N'remove') AND ISNULL(@ExpectedVersion, '') <> @Version
            THROW 51000, 'AGORA:SETTING_STALE:This changed since you opened it. Reload it and make your change again.', 1;
        IF @Mode = N'create' AND @Id IS NOT NULL AND @Deleted IS NULL
            THROW 51000, 'AGORA:SETTING_EXISTS:That site already has its own value. Change it instead.', 1;

        IF @Mode = N'edit'
        BEGIN
            -- NULL is a value of its own here (no tariff), so it is compared as
            -- one; and byte for byte, because the collation is case-insensitive.
            IF (@Old IS NULL AND @Normal IS NOT NULL) OR (@Old IS NOT NULL AND @Normal IS NULL)
               OR CONVERT(VARBINARY(800), @Old) <> CONVERT(VARBINARY(800), @Normal)
            BEGIN
                UPDATE agora.Setting SET Value = @Normal, Source = N'edit', UpdatedAt = @Now, UpdatedBy = @UserId WHERE Id = @Id AND BranchId = @BranchId;
                INSERT INTO agora.MasterChange (BranchId, Entity, EntityId, Field, PriorValue, NewValue, Reason, Source, CreatedAt, CreatedBy)
                VALUES (@BranchId, N'Setting', @Id, N'Value', @Old, @Normal, @Reason, N'edit', @Now, @UserId);
                SET @Changed = 1;
            END
        END
        ELSE IF @Mode = N'create'
        BEGIN
            IF @Id IS NOT NULL
                UPDATE agora.Setting
                SET Value = @Normal, Type = @Type, Description = @Description, Choices = @Choices, MinValue = @Min, MaxValue = @Max,
                    IsNullable = @IsNullable, AllowsBranch = @AllowsBranch, UsedBy = @UsedBy, Source = N'edit',
                    DeletedAt = NULL, DeletedBy = NULL, UpdatedAt = @Now, UpdatedBy = @UserId
                WHERE Id = @Id AND BranchId = @BranchId;
            ELSE
            BEGIN
                INSERT INTO agora.Setting (BranchId, SettingKey, Value, Type, Description, Choices, MinValue, MaxValue,
                                           IsNullable, AllowsBranch, UsedBy, Source, CreatedAt, CreatedBy)
                VALUES (@BranchId, @SettingKey, @Normal, @Type, @Description, @Choices, @Min, @Max,
                        @IsNullable, @AllowsBranch, @UsedBy, N'edit', @Now, @UserId);
                SET @Id = SCOPE_IDENTITY();
            END
            INSERT INTO agora.MasterChange (BranchId, Entity, EntityId, Field, PriorValue, NewValue, Reason, Source, CreatedAt, CreatedBy)
            VALUES (@BranchId, N'Setting', @Id, N'Override', NULL, @Normal, @Reason, N'edit', @Now, @UserId);
            SET @Changed = 1;
        END
        ELSE
        BEGIN
            UPDATE agora.Setting SET DeletedAt = @Now, DeletedBy = @UserId, UpdatedAt = @Now, UpdatedBy = @UserId
            WHERE Id = @Id AND BranchId = @BranchId;
            INSERT INTO agora.MasterChange (BranchId, Entity, EntityId, Field, PriorValue, NewValue, Reason, Source, CreatedAt, CreatedBy)
            VALUES (@BranchId, N'Setting', @Id, N'Override', @Old, NULL, @Reason, N'edit', @Now, @UserId);
            SET @Changed = 1;
        END

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT CONVERT(BIT, 1) AS Ok,
           CASE WHEN @Changed = 0 THEN 'UNCHANGED' WHEN @Mode = N'create' THEN 'CREATED' WHEN @Mode = N'remove' THEN 'REMOVED' ELSE 'SAVED' END AS Code,
           CONVERT(NVARCHAR(400), CASE
               WHEN @Changed = 0 THEN N'Nothing changed, so nothing was saved.'
               WHEN @Mode = N'create' THEN N'The site now has its own value.'
               WHEN @Mode = N'remove' THEN N'The site''s own value was removed; it follows the group value again.'
               WHEN @BranchId = @GroupBranchId THEN N'Group value saved.'
               ELSE N'Site value saved.' END) AS Message,
           @Id AS Id;
END
