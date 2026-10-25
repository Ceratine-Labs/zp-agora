CREATE OR ALTER PROCEDURE agora.usp_StockRecon_PreviewGlobal
    @BranchId int,
    @RunId bigint,
    @Basis varchar(12) = 'balanced',
    @IncludeProduction bit = 1
AS
BEGIN
    SET NOCOUNT ON;
    /* Read-only second pass for the global run view. Saved run quantities;
       current product metadata and production records are labelled separately.
       Raw quantities are NOT converted by Ratio (Ryan, 25 Oct 2026).
       A candidate is evidence to investigate, never permission to amend.
       Sets: group totals, chains, linked pairs, coincidence counts, production. */
    IF @Basis IS NULL OR @Basis NOT IN ('balanced', 'original')
        THROW 51000, 'AGORA:BAD_BASIS:Choose original or balanced quantities.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.StockReconRun WHERE BranchId=@BranchId AND Id=@RunId)
        THROW 51000, 'AGORA:NO_RUN:That run does not belong to this site.', 1;

    DECLARE @From date, @To date;
    SELECT @From=FromDate, @To=ISNULL(CompleteTo,ToDate)
    FROM agora.StockReconRun WHERE BranchId=@BranchId AND Id=@RunId;

    SELECT ROW_NUMBER() OVER (ORDER BY c.AreaNo,c.StockItemNo) ChainId,
           c.*, COALESCE(NULLIF(c.SavedDescription,''),m.StockItemDescription,c.StockItemNo) ItemDescription,
           COALESCE(NULLIF(c.SavedPOS,''),m.POSCode) POSCode,
           m.UOMCode,
           m.PosSystem, ISNULL(m.PreProductionTypeNo,0) PreProductionTypeNo,
           m.Ratio,
           CONVERT(decimal(18,4),CASE WHEN @Basis='original' THEN c.OriginalVariance ELSE c.BalancedVariance END) Residual,
           UPPER(LEFT(LTRIM(COALESCE(NULLIF(c.SavedDescription,''),m.StockItemDescription,c.StockItemNo)),
               CHARINDEX(' ',LTRIM(COALESCE(NULLIF(c.SavedDescription,''),m.StockItemDescription,c.StockItemNo))+' ')-1)) NameRoot
    INTO #chains
    FROM (
        SELECT AreaNo,StockItemNo,MIN(Id) LineId,COUNT(*) ShiftCount,
               MAX(ItemDescription) SavedDescription,MAX(POSCode) SavedPOS,
               MAX(StockLocation) SavedLocation,
               SUM(QtyVar) OriginalVariance,SUM(QtyVarNew) BalancedVariance,
               MAX(CONVERT(int,ChainBlocked)) Held,
               SUM(CASE WHEN QtyIssuedNew>QtyIssued THEN QtyIssuedNew-QtyIssued ELSE 0 END) ProposedMissingIssue,
               SUM(CASE WHEN DupTier='D1' THEN 1 ELSE 0 END) ClearDuplicates,
               SUM(CASE WHEN DupTier='D2' THEN 1 ELSE 0 END) PossibleDuplicates
        FROM agora.StockReconRunLine
        WHERE BranchId=@BranchId AND RunId=@RunId
        GROUP BY AreaNo,StockItemNo
    ) c
    OUTER APPLY (
        SELECT TOP 1 x.StockItemDescription,x.POSCode,x.UOMCode,x.PosSystem,x.PreProductionTypeNo,x.Ratio
        FROM agora.vw_StockItem x
        WHERE x.BranchId=@BranchId AND x.StockItemNo=c.StockItemNo
        ORDER BY CASE WHEN x.AreaNo=c.AreaNo THEN 0 ELSE 1 END,
                 CASE WHEN x.PosSystem=c.SavedLocation THEN 0 ELSE 1 END,x.PosSystem
    ) m;

    IF (SELECT COUNT(*) FROM #chains)>5000
        THROW 51000, 'AGORA:GLOBAL_TOO_LARGE:Use a smaller run for global comparison.', 1;
    CREATE INDEX ix_residual ON #chains(Residual);

    /* Each over/short pair appears once. Blank units, POS codes and name roots
       must never manufacture a link. Different units cannot be compared. */
    SELECT TOP (100001) p.ChainId OverChainId,n.ChainId ShortChainId,
           p.Residual OverQuantity,n.Residual ShortQuantity,
           CONVERT(decimal(18,4),p.Residual+n.Residual) Difference,
           CASE WHEN NULLIF(LTRIM(RTRIM(p.UOMCode)),'') IS NULL
                     OR NULLIF(LTRIM(RTRIM(n.UOMCode)),'') IS NULL OR p.UOMCode<>n.UOMCode THEN 0
                WHEN p.PreProductionTypeNo>0 AND p.PreProductionTypeNo=n.PreProductionTypeNo THEN 3
                WHEN NULLIF(LTRIM(RTRIM(p.POSCode)),'') IS NOT NULL AND p.POSCode=n.POSCode AND p.PosSystem=n.PosSystem THEN 2
                WHEN p.AreaNo=n.AreaNo AND LEN(p.NameRoot)>=3 AND p.NameRoot=n.NameRoot
                     AND p.NameRoot LIKE '%[A-Z]%' THEN 1
                ELSE 0 END LinkStrength
    INTO #pairs
    FROM #chains p JOIN #chains n ON p.Residual>0.005 AND n.Residual < -0.005
        AND ABS(p.Residual+n.Residual)<=0.01;

    IF (SELECT COUNT(*) FROM #pairs)>100000
        THROW 51000, 'AGORA:GLOBAL_TOO_LARGE:Too many possible pairs; use a smaller run.', 1;

    /* Production is contextual evidence, never subtracted from variance: the
       counts already include movements. Keep base code, item, site, POS and
       date beside each record, without guessing the conversion ratio. */
    SELECT TOP (100001) p.TransactionDate,p.ShiftNo,p.StockItemNo,p.PosSystem,p.BaseCode,
           p.TrackingNumber,p.TotalQuantity,p.LandedCostPrice
    INTO #production
    FROM agora.vw_StockReconProduction p
    WHERE @IncludeProduction=1 AND p.BranchId=@BranchId AND p.TransactionDate>=@From
      AND p.TransactionDate<DATEADD(day,1,@To)
      AND EXISTS (SELECT 1 FROM #chains c WHERE c.StockItemNo=p.StockItemNo
                  AND c.PosSystem=p.PosSystem)
    ORDER BY p.TransactionDate,p.ShiftNo,p.StockItemNo,p.TrackingNumber;

    IF (SELECT COUNT(*) FROM #production)>100000
        THROW 51000, 'AGORA:GLOBAL_TOO_LARGE:Too many production records; use a smaller run.', 1;

    SELECT PreProductionTypeNo,COUNT(*) ChainCount,
           SUM(ABS(Residual)) GrossRawQuantity,SUM(Residual) NetRawQuantity,
           SUM(ABS(Residual))-ABS(SUM(Residual)) CancelledRawQuantity,
           COUNT(DISTINCT UOMCode) UnitKinds,
           SUM(CASE WHEN NULLIF(LTRIM(RTRIM(UOMCode)),'') IS NULL THEN 1 ELSE 0 END) UnknownUnits,
           SUM(CASE WHEN Ratio IS NULL OR Ratio<=0 THEN 1 ELSE 0 END) UnsetRatios
    FROM #chains GROUP BY PreProductionTypeNo ORDER BY PreProductionTypeNo;

    SELECT c.*,
           CASE WHEN c.PreProductionTypeNo>0 AND ABS(c.Residual)>0.005
                     AND g.UnitKinds=1 AND g.UnknownUnits=0 THEN g.Cancelled ELSE 0 END GroupRawOffset,
           (SELECT COUNT(*) FROM #pairs p WHERE p.LinkStrength>0
             AND (p.OverChainId=c.ChainId OR p.ShortChainId=c.ChainId)) CandidateCount
    FROM #chains c
    CROSS APPLY (SELECT SUM(ABS(x.Residual))-ABS(SUM(x.Residual)) Cancelled,
                        COUNT(DISTINCT x.UOMCode) UnitKinds,
                        SUM(CASE WHEN NULLIF(LTRIM(RTRIM(x.UOMCode)),'') IS NULL THEN 1 ELSE 0 END) UnknownUnits
                 FROM #chains x WHERE x.PreProductionTypeNo=c.PreProductionTypeNo) g
    ORDER BY PreProductionTypeNo,AreaNo,StockItemNo;

    SELECT p.*,o.LineId OverLineId,n.LineId ShortLineId,
           o.ItemDescription OverItem,n.ItemDescription ShortItem,
           o.StockItemNo OverItemNo,n.StockItemNo ShortItemNo,
           o.AreaNo OverArea,n.AreaNo ShortArea,o.UOMCode,
           CASE LinkStrength WHEN 3 THEN 'Same pre-production group'
                             WHEN 2 THEN 'Same POS code'
                             ELSE 'Same area and description root' END LinkReason
    FROM #pairs p JOIN #chains o ON o.ChainId=p.OverChainId JOIN #chains n ON n.ChainId=p.ShortChainId
    WHERE LinkStrength>0 ORDER BY LinkStrength DESC,ABS(Difference),OverChainId,ShortChainId;

    SELECT COUNT(*) ArithmeticPairs,
           COALESCE(SUM(CASE WHEN LinkStrength=0 THEN 1 ELSE 0 END),0) RejectedPairs,
           COALESCE(SUM(CASE WHEN LinkStrength>0 THEN 1 ELSE 0 END),0) LinkedPairs
    FROM #pairs;

    SELECT * FROM #production
    ORDER BY TransactionDate,ShiftNo,StockItemNo,TrackingNumber;
END;
