/* PumpIT's SS_SysDefaults and BRN_Config into agora.Setting (T026). One-off,
   re-runnable.

   Reads PumpIT through agora.vw_LegacySysDefault and agora.vw_LegacyBranchConfig
   only (Core v1__01k says what those views leave out, and why: the
   credentials in SS_SysDefaults, and BRN_Config's two import cursors). Writes
   agora.Setting and agora.MasterChange, in one transaction. NEVER writes
   PumpIT.

   What lands where:
     SS_SysDefaults  one row, so each column becomes a GROUP setting
                     (BranchId = @GroupBranchId), key
                     pumpit.SS_SysDefaults.{Column}, type int.
     BRN_Config      one row per branch and name, so each becomes a setting
                     on that branch (BranchId = SSBranchId), key
                     pumpit.BRN_Config.{ConfigDescription}. PumpIT spells the
                     same type three ways; they are folded: S / String ->
                     string (SValue), I / Number -> decimal (IValue),
                     B / Yes\No -> bool (BValue). The names are kept exactly
                     as PumpIT spells them, IncludeDebtorPayment beside
                     IncludeDebtorPayments included — each branch carries one
                     or the other, never both. A row for a branch Agora does
                     not have is skipped and counted.

   Every copied row is Source = 'pumpit', carries LegacySource, and is
   read-only on the Setup screen (agora.usp_Core_SaveSetting refuses it):
   Agora reads none of these, and an edit here would change nothing in
   PumpIT.

   A row already in agora.Setting — the same branch and key, even one removed
   — is never touched, so a re-run changes nothing. Each copied row gets an
   agora.MasterChange row, Source 'migrate'.

   Returns (Ok, Code, Message, Id). Read by Modules/Core/Services/SettingService.
   Deployed by v1__01pj. */
CREATE OR ALTER PROCEDURE agora.usp_Migrate_Setting
    @UserId INT = NULL,
    @GroupBranchId INT = 2
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Source TABLE (
        BranchId INT, SettingKey NVARCHAR(120), Value NVARCHAR(400), Type NVARCHAR(20),
        Description NVARCHAR(400), AllowsBranch BIT, LegacySource NVARCHAR(120));
    DECLARE @Inserted TABLE (Id BIGINT, BranchId INT, Value NVARCHAR(400), LegacySource NVARCHAR(120));
    DECLARE @Skipped INT, @Present INT, @Copied INT;

    INSERT INTO @Source
    SELECT @GroupBranchId, N'pumpit.SS_SysDefaults.' + s.ColumnName, s.Value, N'int', s.Description, 0,
           N'SS_SysDefaults.' + s.ColumnName
    FROM agora.vw_LegacySysDefault s;

    INSERT INTO @Source
    SELECT c.BranchId, N'pumpit.BRN_Config.' + c.ConfigName,
           CASE t.Type
               WHEN N'bool' THEN CASE WHEN c.BValue = 1 THEN N'1' ELSE N'0' END
               WHEN N'decimal' THEN CONVERT(NVARCHAR(40), CONVERT(DECIMAL(18, 4), c.IValue))
               ELSE NULLIF(LTRIM(RTRIM(c.SValue)), N'') END,
           t.Type, NULLIF(LTRIM(RTRIM(c.[Description])), N''), 1, N'BRN_Config.' + c.ConfigName
    FROM agora.vw_LegacyBranchConfig c
    CROSS APPLY (SELECT CASE
        WHEN c.ValueType IN (N'B', N'Yes\No') THEN N'bool'
        WHEN c.ValueType IN (N'I', N'Number') THEN N'decimal'
        ELSE N'string' END AS Type) t
    WHERE EXISTS (SELECT 1 FROM agora.Branch b WHERE b.BranchId = c.BranchId);

    SELECT @Skipped = COUNT(*) FROM agora.vw_LegacyBranchConfig c
    WHERE NOT EXISTS (SELECT 1 FROM agora.Branch b WHERE b.BranchId = c.BranchId);

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @Present = COUNT(*) FROM @Source s
        WHERE EXISTS (SELECT 1 FROM agora.Setting x WITH (UPDLOCK, HOLDLOCK)
                      WHERE x.BranchId = s.BranchId AND x.SettingKey = s.SettingKey);

        INSERT INTO agora.Setting (BranchId, SettingKey, Value, Type, Description, IsNullable, AllowsBranch,
                                   UsedBy, Source, LegacySource, CreatedAt, CreatedBy)
        OUTPUT inserted.Id, inserted.BranchId, inserted.Value, inserted.LegacySource INTO @Inserted
        SELECT s.BranchId, s.SettingKey, s.Value, s.Type, s.Description, 1, s.AllowsBranch,
               NULL, N'pumpit', s.LegacySource, @Now, @UserId
        FROM @Source s
        WHERE NOT EXISTS (SELECT 1 FROM agora.Setting x WHERE x.BranchId = s.BranchId AND x.SettingKey = s.SettingKey);
        SET @Copied = @@ROWCOUNT;

        INSERT INTO agora.MasterChange (BranchId, Entity, EntityId, Field, PriorValue, NewValue, Reason, Source, CreatedAt, CreatedBy)
        SELECT i.BranchId, N'Setting', i.Id, N'Value', NULL, i.Value, N'Copied from PumpIT dbo.' + i.LegacySource,
               N'migrate', @Now, @UserId
        FROM @Inserted i;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT CONVERT(BIT, 1) AS Ok,
           CASE WHEN @Copied = 0 THEN 'UNCHANGED' ELSE 'MIGRATED' END AS Code,
           CONVERT(NVARCHAR(400), CONCAT(@Copied, N' PumpIT settings copied (',
               (SELECT COUNT(*) FROM @Inserted WHERE LegacySource LIKE N'SS[_]SysDefaults.%'), N' from SS_SysDefaults, ',
               (SELECT COUNT(*) FROM @Inserted WHERE LegacySource LIKE N'BRN[_]Config.%'), N' from BRN_Config); ',
               @Present, N' already here; ', @Skipped, N' for branches Agora does not have.')) AS Message,
           CONVERT(BIGINT, @Copied) AS Id;
END
