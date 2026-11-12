/* Change one merchant or terminal number's description in PumpIT (T022).
   A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). WRITES exactly the Description of ONE row of ONE of
   [PumpIT].dbo.SS_Branch_ABSA_MerchantNo, SS_Branch_SMARTATM_TerminalNo or
   SS_Branch_CASHDEVICE_TerminalNo, by its own id within its site, in one
   transaction, and one agora.MasterWrite row, so
   agora.usp_Masters_ReverseWrite can put it back.

   THE DESCRIPTION ONLY. The number is what the bank's and the device
   suppliers' settlement files arrive under (sp_Check_SMARTATM_TerminalNo,
   sp_Check_CASHDEVICE_TerminalNo match it), so it is never written. The
   active flag is not offered either: those checks attribute a Smart ATM
   file to a site only while exactly ONE active row carries its number, so
   switching one on or off moves where deposits land — a decision for Ryan,
   not a form. Never an INSERT or a DELETE.

   @Key is the listing's TerminalKey: ABSA:{ABSAMerchantNoId},
   SMARTATM:{SMARTATMTerminalNoId} or CASHDEVICE:{CASHDEVICETerminalNoId}.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (masters.terminals.edit), REASON_REQUIRED, NOT_FOUND
     (also a site outside @AllowedBranchIds, or a row at another site)
     DESCRIPTION  blank, or longer than PumpIT's 50 characters
     TRIGGER_ON   a PumpIT trigger on the table is switched on
     STALE        the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pd. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveTerminal
    @BranchId INT,
    @Key NVARCHAR(200),
    @Description NVARCHAR(200),
    @ExpectedDescription NVARCHAR(400),
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
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the description is being changed — it is kept with the change.', 1;
    IF @Table IS NULL OR @Id IS NULL
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:There is no such merchant or terminal number at a site you may see.', 1;
    IF @Description IS NULL OR LEN(@Description) > 50
        THROW 51000, 'AGORA:DESCRIPTION:A number needs a description, at most 50 characters (PumpIT''s limit).', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.' + @Table) AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for this table, which would write beyond it. Nothing was written.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Found BIT, @Cur NVARCHAR(50), @Number NVARCHAR(12);
    IF @Kind = N'ABSA'
        SELECT @Found = 1, @Cur = a.[Description], @Number = a.ABSA_MerchantNo
        FROM [PumpIT].dbo.SS_Branch_ABSA_MerchantNo a WITH (UPDLOCK, HOLDLOCK)
        WHERE a.ABSAMerchantNoId = @Id AND a.SSBranchId = @BranchId;
    ELSE IF @Kind = N'SMARTATM'
        SELECT @Found = 1, @Cur = s.[Description], @Number = s.SMARTATM_TerminalNo
        FROM [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo s WITH (UPDLOCK, HOLDLOCK)
        WHERE s.SMARTATMTerminalNoId = @Id AND s.SSBranchId = @BranchId;
    ELSE
        SELECT @Found = 1, @Cur = c.[Description], @Number = c.CASHDEVICE_TerminalNo
        FROM [PumpIT].dbo.SS_Branch_CASHDEVICE_TerminalNo c WITH (UPDLOCK, HOLDLOCK)
        WHERE c.CASHDEVICETerminalNoId = @Id AND c.SSBranchId = @BranchId;

    IF @Found IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such merchant or terminal number at a site you may see.', 1;
    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> ISNULL(LTRIM(RTRIM(@ExpectedDescription)), N'')
        THROW 51000, 'AGORA:STALE:This number was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> @Description
    BEGIN
        INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                       PriorValue, NewValue, Reason, WriteMode, State, CreatedAt, CreatedBy)
        VALUES (@BranchId, @WriteId, N'Terminal', @Table, CONCAT(@Kind, N':', @Id),
                CONCAT(CASE @Kind WHEN N'ABSA' THEN N'ABSA merchant ' WHEN N'SMARTATM' THEN N'Smart ATM terminal ' ELSE N'Cash device terminal ' END,
                       LTRIM(RTRIM(@Number))),
                (SELECT @BranchId AS SSBranchId, @Kind AS Kind, @Id AS Id FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                N'Description', CONVERT(NVARCHAR(400), @Cur), @Description, @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId);
        SET @Changed = 1;

        IF @Kind = N'ABSA'
            UPDATE [PumpIT].dbo.SS_Branch_ABSA_MerchantNo SET [Description] = @Description
             WHERE ABSAMerchantNoId = @Id AND SSBranchId = @BranchId;
        ELSE IF @Kind = N'SMARTATM'
            UPDATE [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo SET [Description] = @Description
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
                ELSE CONCAT(N'The new description was written to PumpIT''s ', @Table, N', the old one kept with it.') END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
