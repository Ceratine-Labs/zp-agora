/* Change one merchant or terminal number's description, or switch it on or
   off, in PumpIT (T022). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08), and his answer of 12 Nov 2026 on the active flag ("build
   it with a guard"). WRITES exactly the Description, and IsActive where the
   table has one, of ONE row of ONE of [PumpIT].dbo.SS_Branch_ABSA_MerchantNo,
   SS_Branch_SMARTATM_TerminalNo or SS_Branch_CASHDEVICE_TerminalNo, by its
   own id within its site, in one transaction, and one agora.MasterWrite row
   per column changed, so agora.usp_Masters_ReverseWrite can put it back.

   NEVER THE NUMBER. It is what the bank's and the device suppliers'
   settlement files arrive under (sp_Check_SMARTATM_TerminalNo,
   sp_Check_CASHDEVICE_TerminalNo match it). Never an INSERT or a DELETE.

   THE ACTIVE FLAG MOVES DEPOSITS. sp_Check_SMARTATM_TerminalNo assigns a
   Smart ATM file to a site only while exactly ONE active row carries its
   number. Switched on here, that number's deposits are assigned to this
   site; switched off, they are left unassigned (or go to the one other site
   still carrying it). So a number is never switched on while another active
   row of the same table carries it — at any site — and the form's
   confirmation says where deposits will land. SS_Branch_CASHDEVICE_TerminalNo
   has no IsActive column: a cash-device row is passed @IsActive NULL.

   @Key is the listing's TerminalKey: ABSA:{ABSAMerchantNoId},
   SMARTATM:{SMARTATMTerminalNoId} or CASHDEVICE:{CASHDEVICETerminalNoId}.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (masters.terminals.edit), REASON_REQUIRED, NOT_FOUND
     (also a site outside @AllowedBranchIds, or a row at another site)
     DESCRIPTION  blank, or longer than PumpIT's 50 characters
     NO_FLAG      an active flag sent for a cash-device number, which has none
     TERMINAL_ACTIVE_ELSEWHERE
                  switching a number on while another active row carries the
                  same number; the message names the site(s)
     TRIGGER_ON   a PumpIT trigger on the table is switched on
     STALE        the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pd. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveTerminal
    @BranchId INT,
    @Key NVARCHAR(200),
    @Description NVARCHAR(200),
    @IsActive BIT = NULL,
    @ExpectedDescription NVARCHAR(400),
    @ExpectedIsActive BIT = NULL,
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

    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');
    SET @Description = NULLIF(LTRIM(RTRIM(@Description)), N'');
    DECLARE @Colon INT = CHARINDEX(N':', ISNULL(@Key, N''));
    DECLARE @Kind NVARCHAR(20) = UPPER(LEFT(@Key, NULLIF(@Colon, 0) - 1));
    DECLARE @Id INT = TRY_CONVERT(INT, SUBSTRING(@Key, @Colon + 1, 200));
    DECLARE @Table SYSNAME = CASE @Kind WHEN N'ABSA' THEN N'SS_Branch_ABSA_MerchantNo'
                                       WHEN N'SMARTATM' THEN N'SS_Branch_SMARTATM_TerminalNo'
                                       WHEN N'CASHDEVICE' THEN N'SS_Branch_CASHDEVICE_TerminalNo' END;

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change merchant and terminal numbers.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the number is being changed — it is kept with the change.', 1;
    IF @Table IS NULL OR @Id IS NULL
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:There is no such merchant or terminal number at a site you may see.', 1;
    IF @Description IS NULL OR LEN(@Description) > 50
        THROW 51000, 'AGORA:DESCRIPTION:A number needs a description, at most 50 characters (PumpIT''s limit).', 1;
    IF @Kind = N'CASHDEVICE' AND (@IsActive IS NOT NULL OR @ExpectedIsActive IS NOT NULL)
        THROW 51000, 'AGORA:NO_FLAG:A cash-device number has no active flag in PumpIT. Nothing was written.', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.' + @Table) AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for this table, which would write beyond it. Nothing was written.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Found BIT, @Cur NVARCHAR(50), @Number NVARCHAR(12), @CurActive BIT;
    IF @Kind = N'ABSA'
        SELECT @Found = 1, @Cur = a.[Description], @Number = a.ABSA_MerchantNo, @CurActive = a.IsActive
        FROM [PumpIT].dbo.SS_Branch_ABSA_MerchantNo a WITH (UPDLOCK, HOLDLOCK)
        WHERE a.ABSAMerchantNoId = @Id AND a.SSBranchId = @BranchId;
    ELSE IF @Kind = N'SMARTATM'
        SELECT @Found = 1, @Cur = s.[Description], @Number = s.SMARTATM_TerminalNo, @CurActive = s.IsActive
        FROM [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo s WITH (UPDLOCK, HOLDLOCK)
        WHERE s.SMARTATMTerminalNoId = @Id AND s.SSBranchId = @BranchId;
    ELSE
        SELECT @Found = 1, @Cur = c.[Description], @Number = c.CASHDEVICE_TerminalNo
        FROM [PumpIT].dbo.SS_Branch_CASHDEVICE_TerminalNo c WITH (UPDLOCK, HOLDLOCK)
        WHERE c.CASHDEVICETerminalNoId = @Id AND c.SSBranchId = @BranchId;

    IF @Found IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such merchant or terminal number at a site you may see.', 1;
    -- A flag left out of the form leaves it as it is.
    IF @Kind <> N'CASHDEVICE' AND @IsActive IS NULL
        SELECT @IsActive = @CurActive, @ExpectedIsActive = ISNULL(@ExpectedIsActive, @CurActive);
    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> ISNULL(LTRIM(RTRIM(@ExpectedDescription)), N'')
       OR (@Kind <> N'CASHDEVICE' AND @CurActive <> @ExpectedIsActive)
        THROW 51000, 'AGORA:STALE:This number was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    DECLARE @Renamed BIT = IIF(ISNULL(LTRIM(RTRIM(@Cur)), N'') <> @Description, 1, 0);
    DECLARE @Flipped BIT = IIF(@Kind <> N'CASHDEVICE' AND @CurActive <> @IsActive, 1, 0);

    -- Never two active rows under one number: the other sites, read under a
    -- range lock so a second press cannot switch one on alongside this one.
    IF @Flipped = 1 AND @IsActive = 1
    BEGIN
        DECLARE @Elsewhere NVARCHAR(400);
        IF @Kind = N'ABSA'
            SELECT @Elsewhere = STRING_AGG(CONVERT(NVARCHAR(400), COALESCE(b.Name, CONCAT(N'branch ', o.SSBranchId))), N', ')
            FROM [PumpIT].dbo.SS_Branch_ABSA_MerchantNo o WITH (UPDLOCK, HOLDLOCK)
            LEFT JOIN agora.Branch b ON b.BranchId = o.SSBranchId
            WHERE o.IsActive = 1 AND o.ABSAMerchantNoId <> @Id AND LTRIM(RTRIM(o.ABSA_MerchantNo)) = LTRIM(RTRIM(@Number));
        ELSE
            SELECT @Elsewhere = STRING_AGG(CONVERT(NVARCHAR(400), COALESCE(b.Name, CONCAT(N'branch ', o.SSBranchId))), N', ')
            FROM [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo o WITH (UPDLOCK, HOLDLOCK)
            LEFT JOIN agora.Branch b ON b.BranchId = o.SSBranchId
            WHERE o.IsActive = 1 AND o.SMARTATMTerminalNoId <> @Id AND LTRIM(RTRIM(o.SMARTATM_TerminalNo)) = LTRIM(RTRIM(@Number));

        IF @Elsewhere IS NOT NULL
        BEGIN
            DECLARE @Msg NVARCHAR(2048) = CONCAT(N'AGORA:TERMINAL_ACTIVE_ELSEWHERE:', LTRIM(RTRIM(@Number)),
                N' is already active at ', @Elsewhere, N'. Switch it off there first: PumpIT assigns its deposits to a site only while one row carries it.');
            THROW 51000, @Msg, 1;
        END
    END

    INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                   PriorValue, NewValue, Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT @BranchId, @WriteId, N'Terminal', @Table, CONCAT(@Kind, N':', @Id),
           CONCAT(CASE @Kind WHEN N'ABSA' THEN N'ABSA merchant ' WHEN N'SMARTATM' THEN N'Smart ATM terminal ' ELSE N'Cash device terminal ' END,
                  LTRIM(RTRIM(@Number))),
           (SELECT @BranchId AS SSBranchId, @Kind AS Kind, @Id AS Id FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           c.Field, c.PriorValue, c.NewValue, @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM (VALUES
            (N'Description', CONVERT(NVARCHAR(400), @Cur), CONVERT(NVARCHAR(400), @Description), @Renamed),
            (N'IsActive', CONVERT(NVARCHAR(10), CONVERT(INT, @CurActive)), CONVERT(NVARCHAR(10), CONVERT(INT, @IsActive)), @Flipped)
         ) c (Field, PriorValue, NewValue, IsChanged)
    WHERE c.IsChanged = 1;
    SET @Changed = @@ROWCOUNT;

    IF @Changed > 0
    BEGIN
        IF @Kind = N'ABSA'
            UPDATE [PumpIT].dbo.SS_Branch_ABSA_MerchantNo
               SET [Description] = CASE WHEN @Renamed = 1 THEN @Description ELSE [Description] END, IsActive = @IsActive
             WHERE ABSAMerchantNoId = @Id AND SSBranchId = @BranchId;
        ELSE IF @Kind = N'SMARTATM'
            UPDATE [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo
               SET [Description] = CASE WHEN @Renamed = 1 THEN @Description ELSE [Description] END, IsActive = @IsActive
             WHERE SMARTATMTerminalNoId = @Id AND SSBranchId = @BranchId;
        ELSE
            UPDATE [PumpIT].dbo.SS_Branch_CASHDEVICE_TerminalNo SET [Description] = @Description
             WHERE CASHDEVICETerminalNoId = @Id AND SSBranchId = @BranchId;
    END

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
                ELSE CONCAT(@Changed, N' change(s) written to PumpIT''s ', @Table, N', each kept with its prior value.') END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
