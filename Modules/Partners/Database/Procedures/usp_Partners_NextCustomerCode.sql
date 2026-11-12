/* The next free customer code at a site (T024). Read-only — it PROPOSES a
   code and reserves nothing.

   Customer codes are per site: the site's three-letter prefix and five
   digits (TED00071, WLS00047 — what PumpIT's own screen issued for every
   customer it added this year, 12 Nov 2026). The prefix is the one most of
   the site's codes carry; the number is the highest under it plus one. Codes
   in any other shape (PUMPIT) are ignored, never parsed.

   Result: one row (BranchId, Prefix, LastCode, NextCode). NextCode is NULL
   when the site has no code to follow or the series is full.

   agora.usp_Partners_AddCustomer calls it again inside its own transaction,
   under the site's lock, and that is the number it writes.
   Read by Modules/Partners/Services/PartnerWriteService. Deployed by v1__04pa. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_NextCustomerCode
    @BranchId INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Prefix NVARCHAR(3), @Last INT;

    SELECT TOP (1) @Prefix = LEFT(c.CustomerCode, 3)
    FROM agora.vw_PartnerCustomer c
    WHERE c.BranchId = @BranchId AND c.CustomerCode LIKE N'[A-Z][A-Z][A-Z][0-9][0-9][0-9][0-9][0-9]'
    GROUP BY LEFT(c.CustomerCode, 3)
    ORDER BY COUNT(*) DESC, LEFT(c.CustomerCode, 3);

    SELECT @Last = MAX(TRY_CONVERT(INT, SUBSTRING(c.CustomerCode, 4, 5)))
    FROM agora.vw_PartnerCustomer c
    WHERE c.BranchId = @BranchId AND @Prefix IS NOT NULL AND c.CustomerCode LIKE @Prefix + N'[0-9][0-9][0-9][0-9][0-9]';

    SELECT @BranchId AS BranchId, @Prefix AS Prefix,
           CASE WHEN @Last IS NULL THEN NULL ELSE @Prefix + RIGHT(N'00000' + CONVERT(NVARCHAR(5), @Last), 5) END AS LastCode,
           CASE WHEN @Prefix IS NULL OR ISNULL(@Last, 0) >= 99999 THEN NULL
                ELSE @Prefix + RIGHT(N'00000' + CONVERT(NVARCHAR(5), ISNULL(@Last, 0) + 1), 5) END AS NextCode;
END
