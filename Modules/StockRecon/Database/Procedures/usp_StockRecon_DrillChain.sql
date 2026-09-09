/*
 * agora.usp_StockRecon_DrillChain — the whole chain behind one clicked shift.
 *
 * Read by:  Stock recon centre -> a run -> click a row (the detail panel).
 * Reads:    agora.StockReconRunLine, agora.vw_StockMaster, agora.vw_StockArea,
 *           agora.StockReconAmendment
 *
 * WHO WAS ON THE SHIFT travels with each row. dbo.STK_StockReconEmployees is
 * keyed on the same grain a recon line is, so the panel can say who was signed
 * on to the area rather than only which shift number it was — which is the
 * difference between an item-level observation and something that can support
 * a conversation with a person. Where two people were on, both are named and
 * the count says so: a short on a shift two people worked cannot be attributed
 * to either of them, and the panel must not imply that it can.
 * Writes:   nothing.
 *
 * A shift row on its own cannot be judged. Its variance was produced by the
 * counts on either side of it, its amendment was decided by the whole item's
 * running total, and whether it is blocked was decided by a DIFFERENT shift
 * somewhere else in the chain. So the panel is the chain: every shift of this
 * stock item in this window, in order, counted beside balanced, with the
 * clicked row marked and the blocking shift findable.
 *
 * Result set 1: the item, and what its chain adds up to.
 * Result set 2: every shift in the chain.
 *
 * Fetched on demand rather than rendered with the grid — a branch-month is
 * fourteen thousand lines and drilling all of them up front is fourteen
 * thousand queries nobody asked for.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_DrillChain]
    @RunId     INT,
    @BranchId  INT,
    @LineId    BIGINT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @AreaNo INT, @StockItemNo NVARCHAR(10);

    SELECT @AreaNo = AreaNo, @StockItemNo = StockItemNo
    FROM agora.StockReconRunLine
    WHERE BranchId = @BranchId AND RunId = @RunId AND Id = @LineId;

    IF @StockItemNo IS NULL
        THROW 51000, 'AGORA:NO_LINE:That line is not on this run.', 1;

    /* The header. The chain totals come off the lines rather than from the run,
       because the run is about every item and this panel is about one. */
    SELECT
        /* The stored label first, the master second. A run made before
           v1__14a has none, and its panel should still be able to name the
           item rather than going blank on a screen that used to work. */
        ISNULL(MAX(rl.ItemDescription), MAX(m.StockItemDescription)) AS StockItemDescription,
        ISNULL(MAX(rl.POSCode),         MAX(m.POSCode))              AS POSCode,
        ISNULL(MAX(rl.StockLocation),   MAX(m.StockLocation))        AS StockLocation,
        MAX(m.UOMCode)                                               AS UOMCode,
        ISNULL(MAX(rl.AreaDescription), MAX(a.AreaDescription))      AS AreaDescription,
        MAX(a.AreaGroup)                                             AS AreaGroup,
        rl.AreaNo,
        rl.StockItemNo,
        /* How many DIFFERENT people appear anywhere on this chain. One is a
           chain a single person is answerable for; six is a chain where a
           persistent short is about the item or the process, not a person. */
        (SELECT COUNT(DISTINCT x.EmployeeCodes)
           FROM agora.StockReconRunLine x
          WHERE x.BranchId = rl.BranchId AND x.RunId = rl.RunId
            AND x.AreaNo = rl.AreaNo AND x.StockItemNo = rl.StockItemNo
            AND x.EmployeeCodes IS NOT NULL)                         AS DistinctEmployeeSets,
        MAX(rl.ActiveLen)                                   AS ActiveShifts,
        COUNT(*)                                            AS ChainShifts,
        SUM(CONVERT(int, rl.IsDormant))                     AS DormantShifts,
        MAX(rl.ChainNetVar)                                 AS ChainNetVar,
        CONVERT(decimal(18,2), MAX(rl.ChainNetVar) * MAX(ISNULL(rl.SellPrice, 0))) AS ChainNetVarValue,
        MAX(CONVERT(int, rl.ChainBlocked))                  AS ChainBlocked,
        /* WHY it is blocked, named rather than left for the reader to work out
           from ten bits. First one wins, most serious first — the same order
           the exception classes are ranked in. */
        MAX(CASE WHEN rl.FlagSoldMoreThanOnHand = 1 THEN 'A shift sold more than it could hold'        END) AS BlockSoldMore,
        MAX(CASE WHEN rl.FlagCloseExceedsOnHand = 1 THEN 'A shift closed holding more than it received' END) AS BlockCloseExceeds,
        MAX(CASE WHEN rl.FlagNetOver            = 1 THEN 'The window as a whole is over'                END) AS BlockNetOver,
        MAX(CASE WHEN rl.FlagChainBroken        = 1 THEN 'An opening is not the previous closing'       END) AS BlockChainBroken,
        MAX(CASE WHEN rl.FlagIssueWentNowhere   = 1 THEN 'An issue went nowhere'                        END) AS BlockIssueNowhere,
        MAX(CASE WHEN rl.FlagBigAmendment       = 1 THEN 'A correction exceeds the unit cap'            END) AS BlockBigAmendment,
        MAX(CASE WHEN rl.FlagPctAmendment       = 1 THEN 'A correction exceeds the share cap'           END) AS BlockPctAmendment,
        MAX(CASE WHEN rl.FlagNegativeClose      = 1 THEN 'Balancing would drive a closing below zero'   END) AS BlockNegativeClose,
        MAX(CASE WHEN rl.FlagShortChain         = 1 THEN 'Too few active shifts to balance'             END) AS BlockShortChain,
        MAX(CASE WHEN rl.FlagDormantMoved       = 1 THEN 'A dormant shift would have been moved'        END) AS BlockDormantMoved
    FROM agora.StockReconRunLine rl
    LEFT JOIN agora.vw_StockMaster m ON m.BranchId = rl.BranchId AND m.StockItemNo = rl.StockItemNo
    LEFT JOIN agora.vw_StockArea   a ON a.BranchId = rl.BranchId AND a.AreaNo      = rl.AreaNo
    WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
      AND rl.AreaNo = @AreaNo AND rl.StockItemNo = @StockItemNo
    GROUP BY rl.BranchId, rl.RunId, rl.AreaNo, rl.StockItemNo;

    /* The chain. `IsClicked` marks the row the reader came from, so a panel
       over twelve shifts does not make them find it again. `CumulativeVar` is
       the curve the method is drawn on — the counted running total, which is
       what makes the envelope legible without a chart. */
    SELECT
        rl.Id,
        rl.[LineNo],
        rl.TransactionDate,
        rl.ShiftNo,
        rl.IsDormant,
        rl.ActiveSeq,
        rl.QtyOpen, rl.QtyIssued, rl.QtyClose, rl.QtyPOS, rl.QtyVar,
        rl.QtyOpenNew, rl.QtyCloseNew, rl.AmendClose, rl.QtyVarNew,
        rl.ExceptionCode,
        rl.Outcome,
        rl.EmployeeNames,
        rl.EmployeeCodes,
        rl.EmployeeCount,
        rl.WouldAmend,
        rl.Selected,
        rl.CommitState,
        rl.BlockReason,
        CONVERT(bit, CASE WHEN rl.Id = @LineId THEN 1 ELSE 0 END)          AS IsClicked,
        SUM(rl.QtyVar) OVER (ORDER BY rl.[LineNo] ROWS UNBOUNDED PRECEDING) AS CumulativeVar,
        am.[State]                                                          AS AmendmentState,
        am.PriorQtyOpen, am.PriorQtyClose
    FROM agora.StockReconRunLine rl
    LEFT JOIN agora.StockReconAmendment am
           ON am.BranchId = rl.BranchId AND am.RunId = rl.RunId AND am.RunLineId = rl.Id
    WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
      AND rl.AreaNo = @AreaNo AND rl.StockItemNo = @StockItemNo
    ORDER BY rl.[LineNo];
END
