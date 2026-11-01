/* Customer reports1305 (Omni/Arch),1262(Aura09),1319(Pilot purchases).
   Original source units and signs, no pack-size multiplication or conversion.
   The pack calls the Omni family WINBRANCH; actual POS identity is retained.
   Prior-week receipts and three subsequent days support late-GRV evidence.
   Cost comparisons are within the authorized branch, never another site's data.
*/
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_WeeklyReceipts
    @BranchId int,
    @FromDate date,
    @ToDate date
AS
BEGIN
    SET NOCOUNT ON;
    IF @BranchId IS NULL OR @BranchId <= 0 OR @FromDate IS NULL OR @ToDate IS NULL
       OR @ToDate < @FromDate OR DATEDIFF(day,@FromDate,@ToDate) > 92
        THROW 51000, 'AGORA:BAD_WEEK:Choose one site and a period of at most 93 days.', 1;

    IF OBJECT_ID('agora.vw_StockWeeklySupplier','V') IS NULL
       OR OBJECT_ID('agora.vw_StockWeeklyPilotPurchase','V') IS NULL
        THROW 51000, 'AGORA:WEEKLY_SOURCE_UNAVAILABLE:Weekly receipt source views are not installed.', 1;

    DECLARE @Start date=DATEADD(day,-7,@FromDate), @End date=DATEADD(day,4,@ToDate);
    ;WITH dated AS (
        SELECT s.*,
               CASE WHEN RTRIM(s.POSSystemName)='Aura' THEN 'AURA' ELSE 'WINBRANCH' END AS SourceSystem,
               CASE WHEN RTRIM(s.POSSystemName)='Aura' THEN CONVERT(date,s.TransactionDate)
                    ELSE COALESCE(CONVERT(date,s.EODDate),CONVERT(date,s.TransactionDate)) END AS EvidenceDate
        FROM agora.vw_StockWeeklySupplier s
        WHERE s.BranchId=@BranchId
    ), history AS (
        SELECT s.*,
               ROW_NUMBER() OVER (
                   PARTITION BY s.BranchId,s.POSSystemId,s.ItemCode
                   ORDER BY s.EvidenceDate,s.TransactionDate,s.TransactionNumber,s.DocumentNumber
               ) AS PriceSequence
        FROM dated s
        WHERE s.EvidenceDate>=DATEADD(day,-90,@FromDate) AND s.EvidenceDate<@End
    ), priced AS (
        SELECT h.*, MAX(CASE WHEN h.UnitCostPriceExcl>0 THEN h.PriceSequence END) OVER (
            PARTITION BY h.BranchId,h.POSSystemId,h.ItemCode ORDER BY h.PriceSequence
            ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING) AS PreviousPriceSequence
        FROM history h
    ), supplier AS (
        SELECT h.*,p.UnitCostPriceExcl AS PreviousUnitCost,
               CASE WHEN h.TransactionType='I' THEN 1 WHEN h.TransactionType='C' THEN -1 END AS SignFactor
        FROM priced h
        LEFT JOIN history p ON p.BranchId=h.BranchId AND p.POSSystemId=h.POSSystemId
            AND p.ItemCode=h.ItemCode AND p.PriceSequence=h.PreviousPriceSequence
        WHERE h.EvidenceDate>=@Start AND h.EvidenceDate<@End
    ), documents AS (
        SELECT POSSystemId,BusinessPartnerCode,DocumentNumber,COUNT(DISTINCT TransactionNumber) AS DocumentUses
        FROM supplier
        WHERE NULLIF(LTRIM(RTRIM(DocumentNumber)),'') IS NOT NULL
        GROUP BY POSSystemId,BusinessPartnerCode,DocumentNumber
    ), evidence AS (
        SELECT CAST(s.SourceSystem AS nvarchar(20)) AS SourceSystem,
               CAST(LTRIM(RTRIM(s.ItemCode)) AS nvarchar(80)) AS POSCode,
               CAST(CASE WHEN s.SourceSystem='WINBRANCH' AND s.EODDate IS NULL THEN 'SourceException' WHEN s.TransactionType='I' THEN 'Receipt' WHEN s.TransactionType='C' THEN 'Credit' ELSE 'SourceException' END AS nvarchar(30)) AS EvidenceType,
               s.EvidenceDate,
               CASE WHEN s.SourceSystem<>'WINBRANCH' OR s.EODDate IS NOT NULL THEN CONVERT(decimal(19,4),s.Qty*s.SignFactor) END AS Quantity,
               CASE WHEN s.SourceSystem<>'WINBRANCH' OR s.EODDate IS NOT NULL THEN CONVERT(decimal(19,4),s.LineTotalAmountIncl*s.SignFactor) END AS Value,
               CAST(LTRIM(RTRIM(s.DocumentNumber)) AS nvarchar(160)) AS Reference,
               (SELECT CASE WHEN s.SourceSystem='AURA' THEN 'PumpIT report1262' ELSE 'PumpIT report1305' END AS Source,
                       s.POSSystemId,s.POSSystemName,s.TransactionNumber,
                       s.TransactionDate,s.EODDate,s.TransactionType,
                       s.BusinessPartnerCode AS SupplierCode,s.BusinessPartnerName AS SupplierName,
                       s.ItemDescription,LTRIM(RTRIM(s.ItemUOM)) AS Unit,s.PackSize,
                       'Source quantity; no unit or pack conversion' AS QuantityBasis,
                       'Source line total including VAT, signed by transaction type' AS ValueBasis,
                       s.Qty AS SourceQuantity,s.UnitCostPriceExcl,s.PreviousUnitCost,
                       s.LineTotalAmountExcl AS SourceExclVat,s.LineTotalAmountVat AS SourceVat,
                       CASE WHEN s.SourceSystem='WINBRANCH' AND s.EODDate IS NULL THEN 'Missing end-of-day date; transaction date shown for review only'
                            WHEN s.TransactionType NOT IN ('I','C') OR s.TransactionType IS NULL THEN 'Unsupported transaction type'
                            WHEN NULLIF(LTRIM(RTRIM(s.DocumentNumber)),'') IS NULL THEN 'No invoice reference captured'
                            WHEN d.DocumentUses>1 THEN 'Invoice reference used more than once'
                            WHEN s.TransactionType='C' THEN 'Credit note'
                            WHEN s.UnitCostPriceExcl<=0 THEN 'Zero or negative unit cost'
                            WHEN s.Qty=0 THEN 'Zero quantity received'
                            WHEN s.LineTotalAmountExcl<>0 AND s.LineTotalAmountVat/s.LineTotalAmountExcl*100 NOT BETWEEN -0.5 AND 15.5 THEN 'VAT rate outside expected range'
                            WHEN s.PreviousUnitCost>0 AND ABS((s.UnitCostPriceExcl-s.PreviousUnitCost)/s.PreviousUnitCost)>0.25 THEN 'Unit cost moved more than twenty five percent'
                            ELSE NULL END AS ExceptionFlag,
                       CASE WHEN s.EvidenceDate<@FromDate THEN 'Previous week' WHEN s.EvidenceDate>@ToDate THEN 'After week' ELSE 'Selected week' END AS PeriodPosition
                FOR JSON PATH,WITHOUT_ARRAY_WRAPPER,INCLUDE_NULL_VALUES) AS DetailJson
        FROM supplier s
        LEFT JOIN documents d ON d.POSSystemId=s.POSSystemId AND d.BusinessPartnerCode=s.BusinessPartnerCode AND d.DocumentNumber=s.DocumentNumber
        WHERE s.EvidenceDate>=@Start AND s.EvidenceDate<@End
        UNION ALL
        SELECT CAST('PILOT' AS nvarchar(20)),CAST(LTRIM(RTRIM(p.POSCode)) AS nvarchar(80)),
               CAST(CASE WHEN LTRIM(RTRIM(p.Category))='zc' THEN 'SourceException' WHEN p.Quantity<0 THEN 'Credit' ELSE 'Receipt' END AS nvarchar(30)),
               CONVERT(date,p.PurchaseDate),
               CASE WHEN LTRIM(RTRIM(p.Category))<>'zc' OR p.Category IS NULL THEN CONVERT(decimal(19,4),p.Quantity) END,
               CASE WHEN LTRIM(RTRIM(p.Category))<>'zc' OR p.Category IS NULL THEN CONVERT(decimal(19,4),p.CostExcl+p.Vat) END,
               CAST(LTRIM(RTRIM(p.InvoiceReference)) AS nvarchar(160)),
               (SELECT 'PumpIT report1319' AS Source,p.SourceId,p.SupplierCode,p.SupplierName,
                       p.PostingReference,p.ItemDescription,LTRIM(RTRIM(p.Unit)) AS Unit,
                       'Source purchase quantity; no unit or pack conversion' AS QuantityBasis,
                       'Source COST plus VAT; source sign retained' AS ValueBasis,
                       p.Quantity AS SourceQuantity,p.CostExcl,p.Vat,p.Category,
                       CASE WHEN LTRIM(RTRIM(p.Category))='zc' THEN 'Control account; not a physical purchase'
                            WHEN NULLIF(LTRIM(RTRIM(p.InvoiceReference)),'') IS NULL THEN 'No invoice reference captured'
                            WHEN p.Quantity=0 THEN 'Zero quantity received' ELSE NULL END AS ExceptionFlag,
                       CASE WHEN p.PurchaseDate<@FromDate THEN 'Previous week' WHEN p.PurchaseDate>=DATEADD(day,1,@ToDate) THEN 'After week' ELSE 'Selected week' END AS PeriodPosition
                FOR JSON PATH,WITHOUT_ARRAY_WRAPPER,INCLUDE_NULL_VALUES)
        FROM agora.vw_StockWeeklyPilotPurchase p
        WHERE p.BranchId=@BranchId AND p.PurchaseDate>=@Start AND p.PurchaseDate<@End
    )
    SELECT SourceSystem,POSCode,EvidenceType,EvidenceDate,Quantity,Value,Reference,DetailJson
    FROM evidence
    ORDER BY EvidenceDate,SourceSystem,POSCode,Reference;
END;
