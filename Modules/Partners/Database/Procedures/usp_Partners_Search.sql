/* Suppliers and account customers matching what somebody typed (T024) — the
   Partners source for the command palette (plan §3.10, T012). Read-only.

   One row per hit, best first, at most @Limit (1–50):
     Kind        'supplier' or 'customer'
     Code        the supplier or customer code
     Name        the name
     BranchId    a customer's site; for a supplier, the site when the code is
                 at exactly one of the caller's sites, else NULL
     BranchName  likewise
     Sites       how many of the caller's sites carry the code
     Detail      one line saying what the hit is

   A SUPPLIER IS ONE HIT, NOT ONE PER SITE. The list is group-wide and the
   same code is the same supplier at every site (2,994 codes at 31 sites on
   12 Nov 2026), so 31 identical rows would bury everything else. A customer
   is per site and is one hit per row.

   Rank: the exact code, then a code starting with the text, then a name
   starting with it, then a name containing it, then the POS account number.

   @AllowedBranchIds is the caller's grant (NULL = every site), resolved in
   PHP; @IncludeSuppliers / @IncludeCustomers are the caller's view
   permissions. Payment-control customers (IsMOP) are never offered.
   Read by Modules/Partners/Services/PartnerService. Deployed by v1__04p. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_Search
    @Search NVARCHAR(100),
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @IncludeSuppliers BIT = 1,
    @IncludeCustomers BIT = 1,
    @Limit INT = 12
AS
BEGIN
    SET NOCOUNT ON;
    SET @Search = NULLIF(LTRIM(RTRIM(@Search)), N'');
    SET @Limit = CASE WHEN ISNULL(@Limit, 0) BETWEEN 1 AND 50 THEN @Limit ELSE 12 END;

    IF @Search IS NULL OR LEN(@Search) < 2
    BEGIN
        SELECT CONVERT(NVARCHAR(10), NULL) AS Kind, CONVERT(NVARCHAR(10), NULL) AS Code,
               CONVERT(NVARCHAR(100), NULL) AS Name, CONVERT(INT, NULL) AS BranchId,
               CONVERT(NVARCHAR(100), NULL) AS BranchName, CONVERT(INT, NULL) AS Sites,
               CONVERT(NVARCHAR(200), NULL) AS Detail
        WHERE 1 = 0;
        RETURN;
    END

    DECLARE @Allowed TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @Allowed
    SELECT DISTINCT TRY_CONVERT(INT, value) FROM STRING_SPLIT(@AllowedBranchIds, ',')
    WHERE TRY_CONVERT(INT, value) IS NOT NULL;
    DECLARE @AllBranches BIT = CASE WHEN NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL THEN 1 ELSE 0 END;

    DECLARE @Hits TABLE (Kind NVARCHAR(10), Code NVARCHAR(10), Name NVARCHAR(100), BranchId INT,
                         BranchName NVARCHAR(100), Sites INT, Detail NVARCHAR(200), Rnk INT);

    IF @IncludeSuppliers = 1
        INSERT INTO @Hits
        SELECT TOP (@Limit) N'supplier', g.SupplierCode, g.SupplierName,
               CASE WHEN g.Sites = 1 THEN g.FirstBranchId END,
               CASE WHEN g.Sites = 1 THEN b.Name END,
               g.Sites,
               CONCAT(N'Supplier · ', g.SupplierCode, N' · ',
                      CASE WHEN g.Sites = 1 THEN b.Name ELSE CONCAT(g.Sites, N' sites') END),
               g.Rnk
        FROM (
            SELECT s.SupplierCode,
                   MIN(s.SupplierName) AS SupplierName,
                   COUNT(*) AS Sites,
                   MIN(s.BranchId) AS FirstBranchId,
                   MIN(CASE WHEN s.SupplierCode = @Search THEN 1
                            WHEN s.SupplierCode LIKE @Search + N'%' THEN 2
                            WHEN s.SupplierName LIKE @Search + N'%' THEN 3
                            WHEN s.SupplierName LIKE N'%' + @Search + N'%' THEN 4
                            ELSE 5 END) AS Rnk
            FROM agora.vw_PartnerSupplier s
            JOIN agora.Branch sb ON sb.BranchId = s.BranchId
            WHERE (@AllBranches = 1 OR s.BranchId IN (SELECT BranchId FROM @Allowed))
              AND (s.SupplierCode LIKE @Search + N'%'
                   OR s.SupplierName LIKE N'%' + @Search + N'%'
                   OR s.AccountNo = @Search)
            GROUP BY s.SupplierCode
        ) g
        LEFT JOIN agora.Branch b ON b.BranchId = g.FirstBranchId
        ORDER BY g.Rnk, g.SupplierName, g.SupplierCode;

    IF @IncludeCustomers = 1
        INSERT INTO @Hits
        SELECT TOP (@Limit) N'customer', c.CustomerCode, c.CustomerName, c.BranchId, b.Name, 1,
               CONCAT(N'Account customer · ', c.CustomerCode, N' · ', b.Name),
               CASE WHEN c.CustomerCode = @Search THEN 1
                    WHEN c.CustomerCode LIKE @Search + N'%' THEN 2
                    WHEN c.CustomerName LIKE @Search + N'%' THEN 3
                    WHEN c.CustomerName LIKE N'%' + @Search + N'%' THEN 4
                    ELSE 5 END
        FROM agora.vw_PartnerCustomer c
        JOIN agora.Branch b ON b.BranchId = c.BranchId
        WHERE c.IsPaymentControl = 0
          AND (@AllBranches = 1 OR c.BranchId IN (SELECT BranchId FROM @Allowed))
          AND (c.CustomerCode LIKE @Search + N'%'
               OR c.CustomerName LIKE N'%' + @Search + N'%'
               OR c.AccountNo = @Search)
        ORDER BY 8, c.CustomerName, b.Name;

    SELECT TOP (@Limit) Kind, Code, Name, BranchId, BranchName, Sites, Detail
    FROM @Hits
    ORDER BY Rnk, CASE Kind WHEN N'supplier' THEN 0 ELSE 1 END, Name, BranchName;
END
