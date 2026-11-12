/*
 * agora.usp_Recon_SaveCriteria — change, park or restore one extraction rule.
 *
 * Writes:  agora.ReconCriteria, and one agora.MasterChange row per position
 *          the write moved (Entity 'ReconCriteria', T027) in the same
 *          transaction — and NOTHING ELSE. In particular it never
 *          touches PumpIT.dbo.BRN_AutoReconCriteria, which is the customer's
 *          table and is read-only to Agora for ever. That is the whole reason
 *          this table exists (Ryan's decision, 8 September 2026).
 *
 * THREE ACTIONS, because reverting is not deleting:
 *
 *   save     write or replace the override for (branch, area, order), and
 *            switch it on
 *   park     switch an override off. The row stays, with the reason it was
 *            made and who made it, and agora.vw_AutoReconCriteria stops seeing
 *            it — so the customer's own row, or nothing at all, is back in
 *            force. A configuration change that vanishes without trace is the
 *            thing this table exists to end.
 *   unpark   switch it back on
 *
 * AN OVERRIDE REPLACES ITS LEGACY ROW ENTIRELY rather than patching it, so
 * every position arrives on every save. A NULL here means "this rule has no
 * such position", never "leave what was there" — see the header of
 * v1__13h_recon_criteria for why a per-column merge cannot express that.
 *
 * THE VALIDATION IS THE PREVIEW'S OWN TEST, run early. A rule whose resolved
 * length is not positive is one every Preview* procedure refuses at run time
 * with "A resolved extraction length is not positive"; refusing it here means
 * the person finds out while they are looking at the form rather than the next
 * time somebody runs a reconciliation.
 *
 * Refusals: REASON_REQUIRED · UNKNOWN_AREA · BAD_PROCESS_ORDER ·
 *           BAD_START · BAD_LENGTH · NOTHING_TO_PARK · RULE_NOT_FOUND ·
 *           RULE_NOT_NAMED
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_SaveCriteria]
    @BranchId            int,
    @ReconArea           nvarchar(20),
    @ProcessOrder        int,
    @Action              varchar(10)   = 'save',
    @BankStartPosition   int           = NULL,
    @BankEndPosition     int           = NULL,
    @BankStartPosition2  int           = NULL,
    @BankEndPosition2    int           = NULL,
    @MopsStartPosition   int           = NULL,
    @MopsEndPosition     int           = NULL,
    @FilterValue         nvarchar(50)  = NULL,
    @FilterStartPosition int           = NULL,
    @FilterEndPosition   int           = NULL,
    @Reason              nvarchar(300) = NULL,
    @CopiedFromBranchId  int           = NULL,
    @LegacyAutoReconId   int           = NULL,
    @UserId              int           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Action = LOWER(ISNULL(NULLIF(LTRIM(RTRIM(@Action)), ''), 'save'));
    SET @Reason = NULLIF(LTRIM(RTRIM(ISNULL(@Reason, ''))), '');
    SET @FilterValue = NULLIF(LTRIM(RTRIM(ISNULL(@FilterValue, ''))), '');

    IF @Reason IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why. A configuration change with no reason is what the legacy estate is full of, and it is the one thing this table exists to end.', 1;

    IF @ReconArea NOT IN ('ABSA', 'FNB', 'CashMachine', 'CashBags', 'SmartATM')
        THROW 51000, 'AGORA:UNKNOWN_AREA:That is not a reconciliation area.', 1;

    /* UTC, the application's clock — see usp_Recon_Commit. agora.MasterChange
       is stamped with the same instant. */
    DECLARE @Now datetime2(0) = SYSUTCDATETIME();

    /*
     * AN OVERRIDE SHADOWS A RULE, AND A RULE IS AN AutoReconId.
     *
     * (BranchId, BankReconArea, ProcessOrder) does not identify one: branch 23
     * has two FNB rules, ids 283 and 293, both at ProcessOrder 1. Keying on
     * the triple meant one override would have shadowed BOTH — silently
     * replacing two of the customer's rules with one, and nothing on the
     * screen would have said so.
     *
     * So an override that REPLACES a rule is found by the id it names, and one
     * that ADDS a rule — the twenty-four-branches case — by the triple, which
     * is unambiguous precisely because no legacy row is there to collide with.
     */
    IF @LegacyAutoReconId IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM agora.vw_LegacyReconCriteria l
                        WHERE l.AutoReconId = @LegacyAutoReconId AND l.BranchId = @BranchId)
        THROW 51000, 'AGORA:RULE_NOT_FOUND:That rule does not exist on this site. It may have been changed in PumpIT since this screen was drawn — reload and try again.', 1;

    IF @LegacyAutoReconId IS NULL
       AND EXISTS (SELECT 1 FROM agora.vw_LegacyReconCriteria l
                    WHERE l.BranchId = @BranchId AND l.BankReconArea = @ReconArea
                      AND l.ProcessOrder = @ProcessOrder)
        THROW 51000, 'AGORA:RULE_NOT_NAMED:This site already has a rule at that process order, so say WHICH one is being overridden. A process order is not unique — one site has two rules sharing one.', 1;

    DECLARE @Existing bigint = (
        SELECT TOP 1 Id FROM agora.ReconCriteria
        WHERE BranchId = @BranchId
          AND ((@LegacyAutoReconId IS NOT NULL AND LegacyAutoReconId = @LegacyAutoReconId)
            OR (@LegacyAutoReconId IS NULL AND LegacyAutoReconId IS NULL
                AND BankReconArea = @ReconArea AND ProcessOrder = @ProcessOrder))
    );

    /* ---- park / unpark: the override must exist -------------------------- */

    IF @Action IN ('park', 'unpark') AND @Existing IS NULL
        THROW 51000, 'AGORA:NOTHING_TO_PARK:There is no Agora override on that rule — what is in force is the customer''s own row, and Agora does not change that.', 1;

    /* ---- save: the preview's own test, run early ------------------------- */

    IF @Action NOT IN ('park', 'unpark')
    BEGIN
        IF @ProcessOrder IS NULL OR @ProcessOrder < 1
            THROW 51000, 'AGORA:BAD_PROCESS_ORDER:Process order starts at 1 — it is the order the rules are tried in.', 1;

        IF @BankStartPosition IS NULL OR @BankStartPosition < 1
            THROW 51000, 'AGORA:BAD_START:The bank extraction has to start at character 1 or later.', 1;

        /*
         * The same reading every preview makes: BANK_EndPosition is a LENGTH in
         * some areas and an END POSITION in others and the column does not say
         * which (finding 9). Read as an end position where that is arithmetically
         * possible, as a length otherwise — and refuse now what a preview would
         * refuse later.
         */
        DECLARE @ResolvedLen int =
            CASE WHEN @BankEndPosition >= @BankStartPosition
                 THEN @BankEndPosition - @BankStartPosition + 1
                 ELSE @BankEndPosition END;

        IF @ResolvedLen IS NULL OR @ResolvedLen <= 0
            THROW 51000, 'AGORA:BAD_LENGTH:That start and end resolve to a length of zero or less, and every preview refuses such a rule at run time. Read as an end position where one is possible, as a length otherwise.', 1;
    END

    /* ---- the write, and one agora.MasterChange row per position it moved -

       Before and after are the rule IN FORCE, as agora.vw_AutoReconCriteria
       resolves it: the active override, else the customer's rule it names.
       So a new override's "before" is the customer's rule, and a park shows
       the customer's positions coming back. The override's own state is one
       more field, Override: None, In force or Parked (T027). One
       transaction, so the change log and the rule cannot disagree. */

    BEGIN TRANSACTION;

    SELECT TOP 1 x.* INTO #Before FROM (
        SELECT 1 AS Pri, r.BANK_StartPosition, r.BANK_EndPosition, r.BANK_StartPosition2, r.BANK_EndPosition2, r.MOPS_StartPosition, r.MOPS_EndPosition, r.FILTER_Value, r.FILTER_StartPosition, r.FILTER_EndPosition
        FROM agora.ReconCriteria r WHERE r.BranchId = @BranchId AND r.Id = @Existing AND r.IsActive = 1
        UNION ALL
        SELECT 2, l.BANK_StartPosition, l.BANK_EndPosition, l.BANK_StartPosition2, l.BANK_EndPosition2, l.MOPS_StartPosition, l.MOPS_EndPosition, l.FILTER_Value, l.FILTER_StartPosition, l.FILTER_EndPosition
        FROM agora.vw_LegacyReconCriteria l
        WHERE @LegacyAutoReconId IS NOT NULL AND l.AutoReconId = @LegacyAutoReconId AND l.BranchId = @BranchId
    ) x ORDER BY x.Pri;
    DECLARE @OverrideBefore nvarchar(20) = ISNULL((
        SELECT CASE WHEN IsActive = 1 THEN N'In force' ELSE N'Parked' END
        FROM agora.ReconCriteria WHERE BranchId = @BranchId AND Id = @Existing), N'None');
    DECLARE @Code varchar(20), @Message nvarchar(400);

    IF @Action IN ('park', 'unpark')
    BEGIN
        UPDATE agora.ReconCriteria
        SET IsActive = CASE WHEN @Action = 'park' THEN 0 ELSE 1 END,
            Reason = @Reason,
            UpdatedAt = @Now,
            UpdatedBy = @UserId
        WHERE Id = @Existing;

        SELECT @Code = CASE WHEN @Action = 'park' THEN 'PARKED' ELSE 'RESTORED' END,
               @Message = CASE WHEN @Action = 'park'
                    THEN N'The override is switched off. What is in force is the customer''s own rule, or nothing where they have none.'
                    ELSE N'The override is switched back on.' END;
    END
    ELSE IF @Existing IS NULL
    BEGIN
        INSERT INTO agora.ReconCriteria
            (BranchId, BankReconArea, ProcessOrder, LegacyAutoReconId,
             BANK_StartPosition, BANK_EndPosition, BANK_StartPosition2, BANK_EndPosition2,
             MOPS_StartPosition, MOPS_EndPosition,
             FILTER_Value, FILTER_StartPosition, FILTER_EndPosition,
             IsActive, Reason, CopiedFromBranchId, CreatedAt, CreatedBy)
        SELECT @BranchId, @ReconArea, @ProcessOrder,
               /* Which of the customer's rules this replaces, when it
                  replaces one. Null means it ADDS a rule the branch never had,
                  which is the case for most of the estate (finding 1).
                  @LegacyAutoReconId when the caller named one — the screen
                  always does, because the row it opened came from the view and
                  carries the id. Falling back to the triple is for a caller
                  that did not, and it is NOT unique: branch 23 has two FNB
                  rules both at ProcessOrder 1, so TOP 1 would pick one of them
                  arbitrarily. Refused above rather than guessed. */
               @LegacyAutoReconId,
               @BankStartPosition, @BankEndPosition, @BankStartPosition2, @BankEndPosition2,
               @MopsStartPosition, @MopsEndPosition,
               @FilterValue, @FilterStartPosition, @FilterEndPosition,
               1, @Reason, @CopiedFromBranchId, @Now, @UserId;

        SET @Existing = SCOPE_IDENTITY();

        SELECT @Code = 'CREATED',
               @Message = N'The override is in force. Every preview and both drills resolve to it from now on.';
    END
    ELSE
    BEGIN
        UPDATE agora.ReconCriteria
        SET BANK_StartPosition   = @BankStartPosition,
            BANK_EndPosition     = @BankEndPosition,
            BANK_StartPosition2  = @BankStartPosition2,
            BANK_EndPosition2    = @BankEndPosition2,
            MOPS_StartPosition   = @MopsStartPosition,
            MOPS_EndPosition     = @MopsEndPosition,
            FILTER_Value         = @FilterValue,
            FILTER_StartPosition = @FilterStartPosition,
            FILTER_EndPosition   = @FilterEndPosition,
            IsActive             = 1,
            Reason               = @Reason,
            CopiedFromBranchId   = @CopiedFromBranchId,
            UpdatedAt            = @Now,
            UpdatedBy            = @UserId
        WHERE Id = @Existing;

        SELECT @Code = 'UPDATED',
               @Message = N'The override is in force. Every preview and both drills resolve to it from now on.';
    END

    SELECT TOP 1 x.* INTO #After FROM (
        SELECT 1 AS Pri, r.BANK_StartPosition, r.BANK_EndPosition, r.BANK_StartPosition2, r.BANK_EndPosition2, r.MOPS_StartPosition, r.MOPS_EndPosition, r.FILTER_Value, r.FILTER_StartPosition, r.FILTER_EndPosition
        FROM agora.ReconCriteria r WHERE r.BranchId = @BranchId AND r.Id = @Existing AND r.IsActive = 1
        UNION ALL
        SELECT 2, l.BANK_StartPosition, l.BANK_EndPosition, l.BANK_StartPosition2, l.BANK_EndPosition2, l.MOPS_StartPosition, l.MOPS_EndPosition, l.FILTER_Value, l.FILTER_StartPosition, l.FILTER_EndPosition
        FROM agora.vw_LegacyReconCriteria l
        WHERE @LegacyAutoReconId IS NOT NULL AND l.AutoReconId = @LegacyAutoReconId AND l.BranchId = @BranchId
    ) x ORDER BY x.Pri;
    DECLARE @OverrideAfter nvarchar(20) = ISNULL((
        SELECT CASE WHEN IsActive = 1 THEN N'In force' ELSE N'Parked' END
        FROM agora.ReconCriteria WHERE BranchId = @BranchId AND Id = @Existing), N'None');

    /* Binary comparison, so a change of case in a filter value is a change. */
    INSERT INTO agora.MasterChange (BranchId, Entity, EntityId, Field, PriorValue, NewValue, Reason, Source, CreatedAt, CreatedBy)
    SELECT @BranchId, N'ReconCriteria', @Existing, c.Field, c.PriorValue, c.NewValue, @Reason, N'edit', @Now, @UserId
    FROM (SELECT 1 AS One) one
    LEFT JOIN #Before b ON 1 = 1
    LEFT JOIN #After a ON 1 = 1
    CROSS APPLY (VALUES
        (N'Override', @OverrideBefore, @OverrideAfter),
        (N'BANK_StartPosition', CONVERT(nvarchar(400), b.BANK_StartPosition), CONVERT(nvarchar(400), a.BANK_StartPosition)),
        (N'BANK_EndPosition', CONVERT(nvarchar(400), b.BANK_EndPosition), CONVERT(nvarchar(400), a.BANK_EndPosition)),
        (N'BANK_StartPosition2', CONVERT(nvarchar(400), b.BANK_StartPosition2), CONVERT(nvarchar(400), a.BANK_StartPosition2)),
        (N'BANK_EndPosition2', CONVERT(nvarchar(400), b.BANK_EndPosition2), CONVERT(nvarchar(400), a.BANK_EndPosition2)),
        (N'MOPS_StartPosition', CONVERT(nvarchar(400), b.MOPS_StartPosition), CONVERT(nvarchar(400), a.MOPS_StartPosition)),
        (N'MOPS_EndPosition', CONVERT(nvarchar(400), b.MOPS_EndPosition), CONVERT(nvarchar(400), a.MOPS_EndPosition)),
        (N'FILTER_Value', CONVERT(nvarchar(400), b.FILTER_Value), CONVERT(nvarchar(400), a.FILTER_Value)),
        (N'FILTER_StartPosition', CONVERT(nvarchar(400), b.FILTER_StartPosition), CONVERT(nvarchar(400), a.FILTER_StartPosition)),
        (N'FILTER_EndPosition', CONVERT(nvarchar(400), b.FILTER_EndPosition), CONVERT(nvarchar(400), a.FILTER_EndPosition))
    ) c (Field, PriorValue, NewValue)
    WHERE c.PriorValue COLLATE Latin1_General_BIN2 <> c.NewValue COLLATE Latin1_General_BIN2
       OR (c.PriorValue IS NULL AND c.NewValue IS NOT NULL)
       OR (c.PriorValue IS NOT NULL AND c.NewValue IS NULL);

    COMMIT TRANSACTION;

    SELECT CONVERT(bit, 1) AS Ok, @Code AS Code, @Message AS Message, @Existing AS Id;
END
