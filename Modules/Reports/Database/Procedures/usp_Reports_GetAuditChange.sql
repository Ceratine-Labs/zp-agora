/* ============================================================================
   agora.usp_Reports_GetAuditChange

   WHAT IT IS FOR
   One row of the audit trail, opened: the change itself, and what it changed
   in context, so a correction reads the way the customer report reads one
   (Ryan, 2 Oct 2026: "like the report … where it corrects the items and it
   shows what was corrected").

     Result set 1  the change: one row of agora.vw_PumpItChange, with names and,
                   for stock and bank recon, the run it belongs to (RunId).
     Result set 2  its context:
       stock recon  every shift of that item in that run, in order, with the
                    figures PumpIT held before the commit and after it. A shift
                    the commit wrote carries its ledger's prior and new pair; a
                    shift it did not write is the same both sides. The issue
                    too: a clear duplicate the commit set to 0 (D1, 1 Oct 2026)
                    reads its PriorQtyIssued → NewQtyIssued, so the shift's
                    variance after the correction is the nil it was written to
                    be. Variance = sold − (open + issued − close): negative is
                    short.
       bank recon   every row the same batch stamped, the bank side and the
                    deposit side, so the match reads as one thing.
       the rest     nothing; result set 1 says it all (a capture's own detail
                    is its WritesJson, in result set 1).

   @Ledger, @LedgerId   the row's Ledger and LedgerId, as the audit trail
                        gives them.
   @AllowedBranchIds    the sites the person may see; NULL = every site. A
                        change outside them returns nothing.

   READ-ONLY.

     EXEC agora.usp_Reports_GetAuditChange @Ledger = 'StockReconAmendment', @LedgerId = 1;
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GetAuditChange]
    @Ledger            NVARCHAR(40),
    @LedgerId          BIGINT,
    @AllowedBranchIds  NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);
    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;
    DECLARE @All bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    SELECT c.*,
           ISNULL(b.Name, CONCAT(N'Site ', c.BranchId)) AS BranchName,
           cu.UserName AS ChangedByName,
           ru.UserName AS ReversedByName,
           CASE c.Ledger
                WHEN 'StockReconAmendment' THEN (SELECT x.RunId FROM agora.StockReconAmendment x WHERE x.BranchId = c.BranchId AND x.Id = c.LedgerId)
                WHEN 'ReconStamp'          THEN (SELECT x.RunId FROM agora.ReconStamp x WHERE x.BranchId = c.BranchId AND x.Id = c.LedgerId)
           END AS RunId
    INTO #Change
    FROM agora.vw_PumpItChange c
    LEFT JOIN agora.vw_Branch b ON b.BranchId = c.BranchId
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = c.ChangedBy) cu
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = c.ReversedBy) ru
    WHERE c.Ledger = @Ledger AND c.LedgerId = @LedgerId
      AND (@All = 1 OR c.BranchId IN (SELECT BranchId FROM @Allowed));

    SELECT * FROM #Change;

    DECLARE @BranchId int = (SELECT TOP 1 BranchId FROM #Change);

    IF @BranchId IS NULL
    BEGIN
        SELECT CAST(NULL AS int) AS Nothing WHERE 1 = 0;
        RETURN;
    END

    IF @Ledger = 'StockReconAmendment'
    BEGIN
        DECLARE @RunId bigint, @AreaNo int, @Item nvarchar(20), @ThisLine bigint;
        SELECT @RunId = a.RunId, @AreaNo = a.AreaNo, @Item = a.StockItemNo, @ThisLine = a.RunLineId
        FROM agora.StockReconAmendment a WHERE a.BranchId = @BranchId AND a.Id = @LedgerId;

        SELECT rl.Id                                         AS RunLineId,
               rl.TransactionDate,
               rl.ShiftNo,
               CASE rl.ShiftNo WHEN 1 THEN N'Day' WHEN 2 THEN N'Afternoon' WHEN 3 THEN N'Night'
                               ELSE CONCAT(N'Shift ', rl.ShiftNo) END AS ShiftName,
               rl.SellPrice,
               CONVERT(decimal(18,3), ISNULL(a.PriorQtyOpen,  ISNULL(rl.QtyOpenNow,  rl.QtyOpen)))  AS OpenBefore,
               CONVERT(decimal(18,3), ISNULL(a.NewQtyOpen,    ISNULL(rl.QtyOpenNow,  rl.QtyOpen)))  AS OpenAfter,
               /* The issue as the run read it, unless the commit wrote it. */
               CONVERT(decimal(18,3), ISNULL(a.PriorQtyIssued, ISNULL(rl.QtyIssued, 0)))             AS IssuedBefore,
               CONVERT(decimal(18,3), ISNULL(a.NewQtyIssued,   ISNULL(rl.QtyIssued, 0)))             AS IssuedAfter,
               CONVERT(decimal(18,3), ISNULL(rl.QtyPOS, 0))                                          AS Sold,
               CONVERT(decimal(18,3), ISNULL(a.PriorQtyClose, ISNULL(rl.QtyCloseNow, rl.QtyClose))) AS CloseBefore,
               CONVERT(decimal(18,3), ISNULL(a.NewQtyClose,   ISNULL(rl.QtyCloseNow, rl.QtyClose))) AS CloseAfter,
               CONVERT(bit, CASE WHEN a.Id IS NULL THEN 0 ELSE 1 END)                                AS Written,
               a.State                                                                              AS WrittenState,
               CONVERT(bit, CASE WHEN rl.Id = @ThisLine THEN 1 ELSE 0 END)                           AS IsThis,
               rl.Outcome
        INTO #Shifts
        FROM agora.StockReconRunLine rl
        LEFT JOIN agora.StockReconAmendment a
               ON a.BranchId = rl.BranchId AND a.RunId = rl.RunId AND a.RunLineId = rl.Id
        WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId AND rl.AreaNo = @AreaNo AND rl.StockItemNo = @Item;

        SELECT s.*,
               s.OpenBefore + s.IssuedBefore - s.Sold                   AS ExpectedBefore,
               s.OpenAfter  + s.IssuedAfter  - s.Sold                   AS ExpectedAfter,
               s.Sold - (s.OpenBefore + s.IssuedBefore - s.CloseBefore) AS VarBefore,
               s.Sold - (s.OpenAfter  + s.IssuedAfter  - s.CloseAfter)  AS VarAfter
        FROM #Shifts s
        ORDER BY s.TransactionDate, s.ShiftNo;
        RETURN;
    END

    IF @Ledger = 'ReconStamp'
    BEGIN
        DECLARE @Run bigint, @Batch int;
        SELECT @Run = s.RunId, @Batch = s.BatchNo FROM agora.ReconStamp s WHERE s.BranchId = @BranchId AND s.Id = @LedgerId;

        SELECT c.LedgerId, c.Action, c.PumpItTable, c.RowKey, c.Change, c.State,
               CONVERT(bit, CASE WHEN c.LedgerId = @LedgerId THEN 1 ELSE 0 END) AS IsThis
        FROM agora.vw_PumpItChange c
        WHERE c.Ledger = 'ReconStamp'
          AND c.LedgerId IN (SELECT x.Id FROM agora.ReconStamp x
                             WHERE x.BranchId = @BranchId AND x.RunId = @Run AND x.BatchNo = @Batch)
        ORDER BY CASE WHEN c.PumpItTable = 'RCN_BankStatementLinesPumpIT' THEN 0 ELSE 1 END, c.LedgerId;
        RETURN;
    END

    SELECT CAST(NULL AS int) AS Nothing WHERE 1 = 0;
END
