/* One account customer at one site, for its view screen and its row's detail
   (T024). Read-only.

   One result set: PumpIT's row (agora.vw_PartnerCustomer) with, beside it,
   the till's own record of the same account (agora.vw_DebtorAccount, keyed on
   the POS account number, DebtorID) and where its statement goes
   (agora.DebtorContact — the Debtors module's list, edited on the debtor's
   statement and only read here). Columns from the till are prefixed Till;
   the statement's are prefixed Statement.

   Empty means no such customer, or one at a site outside @AllowedBranchIds
   (NULL = every site; the caller's grant, resolved in PHP).
   Read by Modules/Partners/Services/PartnerService. Deployed by v1__04p. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_GetCustomer
    @BranchId INT,
    @CustomerCode NVARCHAR(10),
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET @CustomerCode = LTRIM(RTRIM(@CustomerCode));

    DECLARE @Visible BIT = CASE
        WHEN NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL THEN 1
        WHEN EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId) THEN 1
        ELSE 0 END;

    SELECT c.BranchId,
           b.Name AS BranchName,
           c.CustomerCode,
           c.CustomerName,
           c.AccountNo,
           c.GroupAccountNo,
           CASE WHEN c.IsPaymentControl = 1 THEN N'Payment control' ELSE N'Account' END AS Kind,
           c.IsPaymentControl,
           c.MOPTypeId,
           c.GlCode,
           c.CreditLimit,
           c.OpeningBalance,
           c.ContactPerson,
           c.Department,
           c.JobTitle,
           c.PostalAddress,
           c.PhysicalAddress,
           c.VatNo,
           c.Telephone,
           c.Telephone2,
           c.TelephoneHome,
           c.FaxNo,
           c.CellNo,
           c.EmailAddress,
           c.Comments,
           c.PosSystemId,
           c.IsSdkLoaded,
           c.SdkLoadedAt,
           c.CreateDateTime,
           CONVERT(bit, CASE WHEN a.AccountNo IS NULL THEN 0 ELSE 1 END) AS OnTill,
           a.AccountName AS TillName,
           a.Contact AS TillContact,
           a.Telephone AS TillTelephone,
           a.Cellphone AS TillCellNo,
           a.CreditLimit AS TillCreditLimit,
           a.IsBlocked AS TillIsBlocked,
           a.VatNo AS TillVatNo,
           CONCAT_WS(N', ', a.Address1, a.Address2, a.Address3, a.Address4) AS TillAddress,
           d.Email AS StatementEmail,
           d.CcEmail AS StatementCcEmail,
           ISNULL(d.AutoSend, CONVERT(bit, 0)) AS StatementOnMonthlyRun,
           d.UpdatedAt AS StatementChangedAt
    FROM agora.vw_PartnerCustomer c
    JOIN agora.Branch b ON b.BranchId = c.BranchId
    LEFT JOIN agora.vw_DebtorAccount a ON a.BranchId = c.BranchId AND a.AccountNo = c.AccountNo
    LEFT JOIN agora.DebtorContact d ON d.BranchId = c.BranchId AND d.AccountNo = c.AccountNo
    WHERE c.BranchId = @BranchId AND c.CustomerCode = @CustomerCode AND @Visible = 1;
END
