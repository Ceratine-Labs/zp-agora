/* Fuel history report: literal POS observations, not a daily winning price.
   The report's standard eight-argument contract delegates all scope and business
   rules to the same procedure as the Fuel screen. READ ONLY.
   Deployed by v1__11p_fuel_procs.php. */
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
    EXEC agora.usp_Fuel_GridPrices @BranchIds=@BranchIds,@DateFrom=@DateFrom,@DateTo=@DateTo,
        @Search=@Search,@SortColumn=@SortColumn,@SortAsc=@SortAsc,@Page=@Page,@PageSize=@PageSize,
        @Dataset='observations';
END;
