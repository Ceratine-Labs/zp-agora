/* The next free supplier code in a series (T024). Read-only — it PROPOSES a
   code and reserves nothing.

   Port of dbo.sp_GetNextSupplierCode, corrected for the codes the customer
   actually issues. The legacy procedure takes a first letter and returns that
   letter plus three digits, the highest per BRANCH plus one; on the live list
   every code is 'APS-####' or 'APE-####' (12 Nov 2026), where
   SUBSTRING(code, 2, 3) is 'PS-' and the legacy arithmetic fails. Two
   differences, both deliberate:

     · THE SERIES, NOT A LETTER. @Series is 'APS' (mostly stock suppliers) or
       'APE' (mostly expense creditors); the code is the series, a hyphen and
       four digits. Codes that are not in that shape are ignored, never parsed.
     · GROUP-WIDE, NOT PER BRANCH. The list is one list repeated at every site
       — the same code is the same supplier everywhere — so the next code is
       the highest at ANY site plus one. A per-branch maximum would hand site
       A a code that already means a different supplier at site B.

   Result: one row (Series, LastCode, NextCode, CodesInSeries, Sites). NextCode
   is NULL when the series is full (9999) or @Series is not one of the two.
   Sites is how many of Agora's branches carry the supplier list — the rows a
   new code is inserted as.

   On a screen this only proposes, and can be raced. agora.usp_Partners_AddSupplier
   calls it again inside its own transaction, after taking the series lock,
   and that is the number it writes.
   Read by Modules/Partners/Services/PartnerService. Deployed by v1__04p, and again by v1__04pa (Sites). */
CREATE OR ALTER PROCEDURE agora.usp_Partners_NextSupplierCode
    @Series NVARCHAR(3) = N'APS'
AS
BEGIN
    SET NOCOUNT ON;
    SET @Series = UPPER(LTRIM(RTRIM(@Series)));

    DECLARE @Last INT, @Count INT;

    SELECT @Last = MAX(TRY_CONVERT(INT, SUBSTRING(s.SupplierCode, 5, 4))),
           @Count = COUNT(DISTINCT s.SupplierCode)
    FROM agora.vw_PartnerSupplier s
    WHERE @Series IN (N'APS', N'APE')
      AND s.SupplierCode LIKE @Series + N'-[0-9][0-9][0-9][0-9]';

    SELECT @Series AS Series,
           CASE WHEN @Last IS NULL THEN NULL
                ELSE @Series + N'-' + RIGHT(N'0000' + CONVERT(NVARCHAR(4), @Last), 4) END AS LastCode,
           CASE WHEN @Series NOT IN (N'APS', N'APE') OR ISNULL(@Last, 0) >= 9999 THEN NULL
                ELSE @Series + N'-' + RIGHT(N'0000' + CONVERT(NVARCHAR(4), ISNULL(@Last, 0) + 1), 4) END AS NextCode,
           ISNULL(@Count, 0) AS CodesInSeries,
           (SELECT COUNT(DISTINCT s.BranchId) FROM agora.vw_PartnerSupplier s
            JOIN agora.Branch b ON b.BranchId = s.BranchId) AS Sites;
END
