/* One supplier at one site, for its view screen and its row's detail (T024).
   Read-only.

   Result sets, in order:
     1. the supplier at @BranchId, everything vw_PartnerSupplier carries, with
        the site's name and the code's series
     2. the same code at the caller's OTHER sites — name, POS account number,
        flags — because the list is group-wide and a supplier is usually the
        same supplier at every site; a name that differs is shown, not hidden
        (16 codes carry two names across sites on 12 Nov 2026)

   An empty first set means no such supplier, or one at a site outside
   @AllowedBranchIds (NULL = every site; the caller's grant, resolved in PHP).
   Read by Modules/Partners/Services/PartnerService. Deployed by v1__04p. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_GetSupplier
    @BranchId INT,
    @SupplierCode NVARCHAR(8),
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET @SupplierCode = LTRIM(RTRIM(@SupplierCode));

    DECLARE @Allowed TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @Allowed
    SELECT DISTINCT TRY_CONVERT(INT, value) FROM STRING_SPLIT(@AllowedBranchIds, ',')
    WHERE TRY_CONVERT(INT, value) IS NOT NULL;
    DECLARE @AllBranches BIT = CASE WHEN NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL THEN 1 ELSE 0 END;

    DECLARE @Visible BIT = CASE WHEN @AllBranches = 1 OR EXISTS (SELECT 1 FROM @Allowed WHERE BranchId = @BranchId)
                                THEN 1 ELSE 0 END;

    SELECT s.BranchId,
           b.Name AS BranchName,
           s.SupplierCode,
           s.SupplierName,
           CASE WHEN s.SupplierCode LIKE 'APS-%' THEN N'APS'
                WHEN s.SupplierCode LIKE 'APE-%' THEN N'APE'
                ELSE N'Other' END AS CodeSeries,
           s.AccountNo,
           s.IsStock,
           s.IsCash,
           s.IsActive,
           CASE WHEN s.IsActive = 0 THEN N'Inactive' ELSE N'Active' END AS Status,
           s.CashOrCredit,
           s.ContactPerson,
           s.Department,
           s.JobTitle,
           s.PostalAddress,
           s.PhysicalAddress,
           s.Telephone,
           s.Telephone2,
           s.FaxNo,
           s.CellNo,
           s.EmailAddress,
           s.Comments,
           s.PosSystemId,
           s.IsSdkLoaded,
           s.SdkLoadedAt,
           s.CreateDateTime
    FROM agora.vw_PartnerSupplier s
    JOIN agora.Branch b ON b.BranchId = s.BranchId
    WHERE s.BranchId = @BranchId AND s.SupplierCode = @SupplierCode AND @Visible = 1;

    SELECT s.BranchId,
           b.Name AS BranchName,
           s.SupplierName,
           s.AccountNo,
           s.IsStock,
           s.IsCash,
           CASE WHEN s.IsActive = 0 THEN N'Inactive' ELSE N'Active' END AS Status
    FROM agora.vw_PartnerSupplier s
    JOIN agora.Branch b ON b.BranchId = s.BranchId
    WHERE s.SupplierCode = @SupplierCode
      AND s.BranchId <> @BranchId
      AND @Visible = 1
      AND (@AllBranches = 1 OR s.BranchId IN (SELECT BranchId FROM @Allowed))
    ORDER BY b.Name, s.BranchId;
END
