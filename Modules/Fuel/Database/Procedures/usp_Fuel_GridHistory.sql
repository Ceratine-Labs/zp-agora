/* Fuel history report: literal POS observations, not a daily winning price.
   The report's standard eight-argument contract delegates all scope and business
   rules to the same procedure as the Fuel screen. READ ONLY.

   THE SORT. The report's sortable columns are named here, one CASE arm each,
   and handed on to usp_Fuel_GridPrices under the same name; anything else is
   passed as NULL, so the delegate's natural order applies rather than a key
   it was never asked to understand. The arms are what ReportsContractTest
   reads to prove every sortable header has somewhere to go.
   Deployed by v1__11p_fuel_procs.php, and again by v1__11pb. */
CREATE OR ALTER PROCEDURE [agora].[usp_Fuel_GridHistory]
    @BranchIds nvarchar(max) = NULL,
    @DateFrom date = NULL,
    @DateTo date = NULL,
    @Search nvarchar(200) = NULL,
    @SortColumn nvarchar(80) = NULL,
    @SortAsc bit = 1,
    @Page int = 1,
    @PageSize int = 50
AS
BEGIN
    SET NOCOUNT ON;

    SET @SortColumn = CASE @SortColumn
                          WHEN 'BranchName'          THEN 'BranchName'
                          WHEN 'FuelTypeNo'          THEN 'FuelTypeNo'
                          WHEN 'FuelTypeDescription' THEN 'FuelTypeDescription'
                          WHEN 'TradingDate'         THEN 'TradingDate'
                          WHEN 'SourceDate'          THEN 'SourceDate'
                          WHEN 'SellingPrice'        THEN 'SellingPrice'
                          WHEN 'CostPrice'           THEN 'CostPrice'
                          WHEN 'Margin'              THEN 'Margin'
                          WHEN 'IsFleetCard'         THEN 'IsFleetCard'
                      END;

    EXEC agora.usp_Fuel_GridPrices @BranchIds=@BranchIds,@DateFrom=@DateFrom,@DateTo=@DateTo,
        @Search=@Search,@SortColumn=@SortColumn,@SortAsc=@SortAsc,@Page=@Page,@PageSize=@PageSize,
        @Dataset='observations';
END;
