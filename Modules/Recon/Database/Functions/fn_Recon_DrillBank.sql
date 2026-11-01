/* Canonical, read-only bank extraction for the proposal drill and run validity.
   Same predicates and output types as usp_Recon_DrillBank. The inline shape
   lets a scoped set of proposals share one SQL plan instead of nested EXECs.
   Only current matching rows are described; historical source IDs were not
   persisted for aggregate proposals. Criteria, dates and references remain
   the same ones the existing drill uses. */
CREATE OR ALTER FUNCTION [agora].[fn_Recon_DrillBank] (
    @ReconArea nvarchar(20), @BranchId int, @FromDate datetime, @ToDate datetime,
    @KeyRef nvarchar(50), @KeyRef2 nvarchar(50), @BankLineId bigint,
    @WindowFrom datetime, @WindowTo datetime, @RuleOrder varchar(12),
    @BatchKey varchar(10), @MatchMode varchar(10), @MopsConvention varchar(10),
    @BankStartOverride int, @BankLenOverride int, @IncludeReconciled bit
)
RETURNS TABLE
AS
RETURN (
    WITH Crit AS (
        SELECT ProcessOrder, AutoReconId,
               FILTER_StartPosition AS FilterStart,
               CASE WHEN FILTER_EndPosition >= FILTER_StartPosition AND FILTER_StartPosition > 0
                    THEN FILTER_EndPosition - FILTER_StartPosition + 1 ELSE FILTER_EndPosition END AS FilterLen,
               CONVERT(nvarchar(50), FILTER_Value) AS FilterValue,
               ISNULL(@BankStartOverride, BANK_StartPosition) AS BankStart,
               ISNULL(@BankLenOverride, CASE WHEN BANK_EndPosition >= BANK_StartPosition
                    THEN BANK_EndPosition - BANK_StartPosition + 1 ELSE BANK_EndPosition END) AS BankLen,
               BANK_StartPosition2 AS BankStart2,
               CASE WHEN BANK_StartPosition2 > 0 AND BANK_EndPosition2 >= BANK_StartPosition2
                    THEN BANK_EndPosition2 - BANK_StartPosition2 + 1 ELSE BANK_EndPosition2 END AS BankLen2,
               LEN(ISNULL(FILTER_Value, '')) AS Specificity
        FROM agora.vw_AutoReconCriteria
        WHERE BranchId = @BranchId AND BankReconArea = @ReconArea
    ), BankRows AS (
        SELECT CONVERT(bigint, l.BankStatementLineID) AS BankStatementLineID,
               CONVERT(datetime, l.LineDate) AS LineDate,
               CONVERT(nvarchar(400), l.Description) AS Description,
               CONVERT(money, l.Amount) AS Amount,
               CONVERT(nvarchar(50), CASE WHEN @ReconArea = 'FNB' AND @BatchKey = 'numeric'
                    THEN CONVERT(nvarchar(50), TRY_CONVERT(bigint,
                         LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart, x.BankLen)))))
                    ELSE LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart, x.BankLen))) END) AS ExtractedRef,
               CONVERT(nvarchar(50), LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart2, x.BankLen2)))) AS ExtractedRef2,
               CONVERT(nvarchar(2), CASE WHEN @ReconArea = 'ABSA' THEN RIGHT(RTRIM(l.Description), 2) END) AS Leg,
               CONVERT(int, x.ProcessOrder) AS UsedProcessOrder,
               CONVERT(int, x.BankStart) AS UsedBankStart,
               CONVERT(int, x.BankLen) AS UsedBankLen,
               CONVERT(int, l.ReconState) AS ReconState,
               CONVERT(int, l.ReconBatchNo) AS ReconBatchNo
        FROM agora.vw_BankStatementLine l
        OUTER APPLY (
            SELECT TOP 1 c.ProcessOrder, c.BankStart, c.BankLen, c.BankStart2, c.BankLen2
            FROM Crit c
            WHERE c.FilterValue IS NULL
               OR SUBSTRING(l.Description, c.FilterStart, c.FilterLen) = LTRIM(RTRIM(c.FilterValue))
            ORDER BY CASE WHEN @RuleOrder = 'specific' THEN c.Specificity END DESC,
                     c.ProcessOrder ASC, c.AutoReconId ASC
        ) x
        WHERE l.BranchId = @BranchId
          AND l.Type = CASE WHEN @ReconArea = 'CashBags' THEN 'CashDeposit' ELSE @ReconArea END
          AND l.IDState = 2
          AND (@IncludeReconciled = 1 OR l.ReconState = 1)
          AND l.LineDate >= @FromDate AND l.LineDate <= @ToDate
          AND (@BankLineId IS NULL OR l.BankStatementLineID = @BankLineId)
    )
    SELECT BankStatementLineID, LineDate, Description, Amount, ExtractedRef, ExtractedRef2,
           Leg, UsedProcessOrder, UsedBankStart, UsedBankLen, ReconState, ReconBatchNo
    FROM BankRows b
    WHERE (@BankLineId IS NOT NULL)
       OR (@BankLineId IS NULL AND @ReconArea = 'SmartATM'
           AND @KeyRef IS NOT NULL AND @KeyRef2 IS NOT NULL
           AND b.ExtractedRef = @KeyRef AND b.ExtractedRef2 = @KeyRef2)
       OR (@BankLineId IS NULL AND @ReconArea <> 'SmartATM' AND @WindowFrom IS NOT NULL
           AND b.LineDate >= @WindowFrom AND b.LineDate <= @WindowTo
           AND (@KeyRef IS NULL OR b.ExtractedRef = @KeyRef))
       OR (@BankLineId IS NULL AND @WindowFrom IS NULL AND @KeyRef IS NOT NULL
           AND b.ExtractedRef = @KeyRef
           AND (@KeyRef2 IS NULL
                OR (@ReconArea = 'ABSA' AND TRY_CONVERT(int, b.ExtractedRef2) = TRY_CONVERT(int, @KeyRef2))
                OR (@ReconArea <> 'ABSA' AND b.ExtractedRef2 = @KeyRef2)))
);
