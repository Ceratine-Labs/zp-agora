/*
 * agora.usp_Recon_CopyCriteria — take one site's working configuration across
 * to another.
 *
 * Writes:  agora.ReconCriteria, and only when @Apply = 1. Never PumpIT.
 *
 * WHY IT EXISTS. Twenty-four of twenty-six branches have no usable extraction
 * rule for at least one area (finding 1), and their statements all come from
 * the same banks in the same formats. The rule that works at Elephant Coast is
 * almost always the rule the other twenty-three need. Retyping it twenty-three
 * times is how a transcription error gets into a reconciliation.
 *
 * IT PREVIEWS BY DEFAULT. @Apply = 0 returns exactly what it WOULD do, row by
 * row, with a verdict on each — and changes nothing. Copying configuration
 * across a site unseen is precisely the kind of bulk change that should never
 * be one press, so the press that shows you is separate from the press that
 * does it.
 *
 * WHAT IT TAKES FROM THE SOURCE is what is IN FORCE there, not what Agora
 * happens to have overridden. A branch whose rules are entirely the customer's
 * own is a perfectly good source — in fact it is the usual one — and reading
 * agora.vw_AutoReconCriteria rather than agora.ReconCriteria is what makes
 * that work.
 *
 * WHAT IT WILL NOT DO without being told twice: overwrite an override the
 * target already has. @Overwrite = 0 reports those as 'kept' and leaves them.
 * A rule somebody deliberately set for a site is worth more than a rule copied
 * in bulk, and the copy is the thing that should give way.
 *
 * Returns one row per rule considered — Verdict is 'would create' /
 * 'would replace' / 'kept' / 'created' / 'replaced' — then a status row.
 *
 * Refusals: REASON_REQUIRED · SAME_BRANCH · UNKNOWN_AREA · SOURCE_EMPTY
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_CopyCriteria]
    @FromBranchId int,
    @ToBranchId   int,
    @ReconArea    nvarchar(20)  = NULL,
    @Overwrite    bit           = 0,
    @Apply        bit           = 0,
    @Reason       nvarchar(300) = NULL,
    @UserId       int           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(ISNULL(@Reason, ''))), '');

    IF @Apply = 1 AND @Reason IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why. Every override carries the reason it was made, and a copied one is no different.', 1;

    IF @FromBranchId = @ToBranchId
        THROW 51000, 'AGORA:SAME_BRANCH:A site cannot be copied onto itself.', 1;

    IF @ReconArea IS NOT NULL AND @ReconArea NOT IN ('ABSA', 'FNB', 'CashMachine', 'CashBags', 'SmartATM')
        THROW 51000, 'AGORA:UNKNOWN_AREA:That is not a reconciliation area.', 1;

    /* What is in force at the source — the customer's rows, Agora's overrides,
       whichever is actually resolving there. */
    DECLARE @Source TABLE (
        BankReconArea nvarchar(20), ProcessOrder int,
        BankStart int, BankEnd int, BankStart2 int, BankEnd2 int,
        MopsStart int, MopsEnd int,
        FilterValue nvarchar(50), FilterStart int, FilterEnd int,
        PRIMARY KEY (BankReconArea, ProcessOrder)
    );

    INSERT INTO @Source
    SELECT v.BankReconArea, v.ProcessOrder,
           v.BANK_StartPosition, v.BANK_EndPosition, v.BANK_StartPosition2, v.BANK_EndPosition2,
           v.MOPS_StartPosition, v.MOPS_EndPosition,
           v.FILTER_Value, v.FILTER_StartPosition, v.FILTER_EndPosition
    FROM agora.vw_AutoReconCriteria v
    WHERE v.BranchId = @FromBranchId
      AND (@ReconArea IS NULL OR v.BankReconArea = @ReconArea);

    IF NOT EXISTS (SELECT 1 FROM @Source)
        THROW 51000, 'AGORA:SOURCE_EMPTY:That site has no rule in force to copy — which usually means it is one of the sites that needs one, rather than one that can give one.', 1;

    /* The verdict per rule, decided before anything is written so the preview
       and the application cannot disagree about what was going to happen. */
    DECLARE @Plan TABLE (
        BankReconArea nvarchar(20), ProcessOrder int, Verdict nvarchar(20),
        BankStart int, BankEnd int, BankStart2 int, BankEnd2 int,
        MopsStart int, MopsEnd int,
        FilterValue nvarchar(50), FilterStart int, FilterEnd int,
        TargetBankStart int, TargetBankEnd int, TargetSource nvarchar(20)
    );

    INSERT INTO @Plan
    SELECT s.BankReconArea, s.ProcessOrder,
           CASE
               /* An override the target already has is only replaced when the
                  operator has said so twice. */
               WHEN o.Id IS NOT NULL AND @Overwrite = 0 THEN 'kept'
               WHEN o.Id IS NOT NULL                    THEN 'replace'
               ELSE 'create'
           END,
           s.BankStart, s.BankEnd, s.BankStart2, s.BankEnd2,
           s.MopsStart, s.MopsEnd, s.FilterValue, s.FilterStart, s.FilterEnd,
           /* What the target resolves to today, so the preview can show what
              is being changed and not merely what it is being changed to. */
           t.BANK_StartPosition, t.BANK_EndPosition,
           CASE WHEN o.Id IS NOT NULL AND o.IsActive = 1 THEN 'Agora override'
                WHEN t.AutoReconId IS NOT NULL           THEN 'Customer rule'
                ELSE 'nothing' END
    FROM @Source s
    LEFT JOIN agora.ReconCriteria o
           ON o.BranchId = @ToBranchId AND o.BankReconArea = s.BankReconArea
          AND o.ProcessOrder = s.ProcessOrder
    LEFT JOIN agora.vw_AutoReconCriteria t
           ON t.BranchId = @ToBranchId AND t.BankReconArea = s.BankReconArea
          AND t.ProcessOrder = s.ProcessOrder;

    DECLARE @Created int = 0, @Replaced int = 0;

    IF @Apply = 1
    BEGIN
        DECLARE @Now datetime2(0) = SYSDATETIME();

        BEGIN TRANSACTION;

        UPDATE o
        SET o.BANK_StartPosition   = p.BankStart,
            o.BANK_EndPosition     = p.BankEnd,
            o.BANK_StartPosition2  = p.BankStart2,
            o.BANK_EndPosition2    = p.BankEnd2,
            o.MOPS_StartPosition   = p.MopsStart,
            o.MOPS_EndPosition     = p.MopsEnd,
            o.FILTER_Value         = p.FilterValue,
            o.FILTER_StartPosition = p.FilterStart,
            o.FILTER_EndPosition   = p.FilterEnd,
            o.IsActive             = 1,
            o.Reason               = @Reason,
            o.CopiedFromBranchId   = @FromBranchId,
            o.UpdatedAt            = @Now,
            o.UpdatedBy            = @UserId
        FROM agora.ReconCriteria o
        JOIN @Plan p ON p.BankReconArea = o.BankReconArea AND p.ProcessOrder = o.ProcessOrder
        WHERE o.BranchId = @ToBranchId AND p.Verdict = 'replace';

        SET @Replaced = @@ROWCOUNT;

        INSERT INTO agora.ReconCriteria
            (BranchId, BankReconArea, ProcessOrder, LegacyAutoReconId,
             BANK_StartPosition, BANK_EndPosition, BANK_StartPosition2, BANK_EndPosition2,
             MOPS_StartPosition, MOPS_EndPosition,
             FILTER_Value, FILTER_StartPosition, FILTER_EndPosition,
             IsActive, Reason, CopiedFromBranchId, CreatedAt, CreatedBy)
        SELECT @ToBranchId, p.BankReconArea, p.ProcessOrder,
               (SELECT TOP 1 l.AutoReconId FROM agora.vw_LegacyReconCriteria l
                 WHERE l.BranchId = @ToBranchId AND l.BankReconArea = p.BankReconArea
                   AND l.ProcessOrder = p.ProcessOrder),
               p.BankStart, p.BankEnd, p.BankStart2, p.BankEnd2,
               p.MopsStart, p.MopsEnd, p.FilterValue, p.FilterStart, p.FilterEnd,
               1, @Reason, @FromBranchId, @Now, @UserId
        FROM @Plan p
        WHERE p.Verdict = 'create';

        SET @Created = @@ROWCOUNT;

        COMMIT TRANSACTION;

        UPDATE @Plan SET Verdict = CASE Verdict WHEN 'create' THEN 'created'
                                                WHEN 'replace' THEN 'replaced'
                                                ELSE Verdict END;
    END
    ELSE
        UPDATE @Plan SET Verdict = CASE Verdict WHEN 'create' THEN 'would create'
                                                WHEN 'replace' THEN 'would replace'
                                                ELSE Verdict END;

    SELECT BankReconArea, ProcessOrder, Verdict,
           BankStart, BankEnd, BankStart2, BankEnd2,
           MopsStart, MopsEnd, FilterValue, FilterStart, FilterEnd,
           TargetBankStart, TargetBankEnd, TargetSource
    FROM @Plan
    ORDER BY BankReconArea, ProcessOrder;

    SELECT CONVERT(bit, 1) AS Ok,
           CASE WHEN @Apply = 1 THEN 'COPIED' ELSE 'PREVIEWED' END AS Code,
           CASE WHEN @Apply = 1
                THEN CONVERT(nvarchar(20), @Created) + ' created, ' + CONVERT(nvarchar(20), @Replaced) + ' replaced, '
                     + CONVERT(nvarchar(20), (SELECT COUNT(*) FROM @Plan WHERE Verdict = 'kept')) + ' left alone.'
                ELSE 'Nothing was written. This is what it would do.' END AS Message,
           CONVERT(bigint, @Created + @Replaced) AS Id;
END
