<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Partners — suppliers (creditors) and account customers (debtors), as Agora
 * reads them (T024, slot 04).
 *
 * ADOPTED, NOT MIGRATED (plan §4), and READ-ONLY for now. Both masters live in
 * PumpIT and PumpIT keeps trading on them: the cashup's Suppliers and Expenses
 * legs and the POS paid-out pool resolve a supplier through BRN_Suppliers, the
 * Debtors leg and the account-sales pool resolve a customer through
 * BRN_Customers, and PumpIT itself was still creating customers on
 * 9 Nov 2026. So this module owns no tables: it reads the two legacy tables
 * through views, and the screens are procedures over the views. Whether an
 * edit made in Agora is an Agora-owned row or a write back into PumpIT is
 * Ryan's call and is not built (CLAUDE.md rule 1).
 *
 *   agora.vw_PartnerSupplier   BRN_Suppliers, one row per (BranchId,
 *                              SupplierCode) — its primary key on the
 *                              customer's instance. 91,850 rows on
 *                              12 Nov 2026: one group-wide list of 2,994 codes
 *                              (APS-#### and APE-####) repeated at every site,
 *                              each copy carrying that site's POS account
 *                              number (SupplierID) and its own cash / stock /
 *                              active flags.
 *   agora.vw_PartnerCustomer   BRN_Customers, one row per (BranchId,
 *                              CustomerCode). 897 rows at 25 sites; codes are
 *                              per site (a three-letter site prefix and five
 *                              digits). DebtorID is the POS account number —
 *                              the CNUM the Debtors module's vw_DebtorAccount
 *                              and vw_DebtorLedger are keyed on — and IsMOP
 *                              marks the 14-or-so payment-control accounts a
 *                              site carries (Cash Deposits, Zapper, Pumpit
 *                              Control …), which are not trading partners.
 *
 * Plan §3.3: SSBranchId becomes BranchId, every column is enumerated and
 * trimmed once here, NTEXT becomes NVARCHAR(4000), MONEY becomes DECIMAL(18,2),
 * and nothing is filtered — the 32 malformed rows at SSBranchId 0 (codes 1–31
 * with a supplier code in the name column) are still in the view; the
 * procedures leave them out by joining agora.Branch, in the open.
 *
 * WHAT IS LEFT OUT: the SDK load bookkeeping beyond whether and when a row was
 * loaded, MIST_EOD and the website column (IAddress, 'http://www.' on almost
 * every row).
 *
 * agora.vw_Supplier (Reports) predates this module and keeps its narrower
 * shape for the procedures already reading it; nothing here changes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = (string) config('agora.source_databases.erp');

        foreach ($this->views($erp) as $name => $body) {
            DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[{$name}] AS".$body.';');
        }

        MigrationHelper::recordVersion(
            '1.0',
            'Partners (T024): vw_PartnerSupplier and vw_PartnerCustomer over BRN_Suppliers and BRN_Customers '
            .'— read-only, adopted, nothing filtered.'
        );
    }

    /** @return array<string, string> */
    protected function views(string $erp): array
    {
        return [
            'vw_PartnerSupplier' => "
                SELECT s.SSBranchId                                               AS BranchId,
                       CONVERT(nvarchar(8), LTRIM(RTRIM(s.SupplierCode)))         AS SupplierCode,
                       NULLIF(LTRIM(RTRIM(s.SupplierName)), N'')                  AS SupplierName,
                       NULLIF(LTRIM(RTRIM(s.SupplierID)), N'')                    AS AccountNo,
                       CONVERT(bit, s.IsStock)                                    AS IsStock,
                       CONVERT(bit, s.IsCash)                                     AS IsCash,
                       CONVERT(bit, s.isActive)                                   AS IsActive,
                       NULLIF(LTRIM(RTRIM(s.XCashOrCredit)), N'')                 AS CashOrCredit,
                       NULLIF(LTRIM(RTRIM(s.ContactPerson)), N'')                 AS ContactPerson,
                       NULLIF(LTRIM(RTRIM(s.Department)), N'')                    AS Department,
                       NULLIF(LTRIM(RTRIM(s.JobTitle)), N'')                      AS JobTitle,
                       NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(4000), s.PostalAddress))), N'')   AS PostalAddress,
                       NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(4000), s.PhysicalAddress))), N'') AS PhysicalAddress,
                       NULLIF(LTRIM(RTRIM(s.TelNoA)), N'')                        AS Telephone,
                       NULLIF(LTRIM(RTRIM(s.TelNoB)), N'')                        AS Telephone2,
                       NULLIF(LTRIM(RTRIM(s.FaxNo)), N'')                         AS FaxNo,
                       NULLIF(LTRIM(RTRIM(s.CellNo)), N'')                        AS CellNo,
                       NULLIF(LTRIM(RTRIM(s.EmailAddress)), N'')                  AS EmailAddress,
                       NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(4000), s.Comments))), N'')        AS Comments,
                       s.PosSystemId,
                       CONVERT(bit, s.isSDKLoaded)                                AS IsSdkLoaded,
                       s.SDKLoadDateTime                                          AS SdkLoadedAt,
                       s.CreateDateTime
                FROM [{$erp}].dbo.BRN_Suppliers s",

            'vw_PartnerCustomer' => "
                SELECT c.SSBranchId                                               AS BranchId,
                       CONVERT(nvarchar(10), LTRIM(RTRIM(c.CustomerCode)))        AS CustomerCode,
                       NULLIF(LTRIM(RTRIM(c.CustomerName)), N'')                  AS CustomerName,
                       NULLIF(LTRIM(RTRIM(c.DebtorID)), N'')                      AS AccountNo,
                       NULLIF(LTRIM(RTRIM(c.DebtorGroupID)), N'')                 AS GroupAccountNo,
                       CONVERT(bit, c.IsMOP)                                      AS IsPaymentControl,
                       c.MOPTypeId,
                       NULLIF(LTRIM(RTRIM(c.OMNIGLCode)), '')                     AS GlCode,
                       CONVERT(decimal(18, 2), c.CreditLimitAmount)               AS CreditLimit,
                       CONVERT(decimal(18, 2), c.OpeningPumpITBalance)            AS OpeningBalance,
                       NULLIF(LTRIM(RTRIM(c.CustomerContact)), N'')               AS ContactPerson,
                       NULLIF(LTRIM(RTRIM(c.Department)), N'')                    AS Department,
                       NULLIF(LTRIM(RTRIM(c.JobTitle)), N'')                      AS JobTitle,
                       NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(4000), c.PostalAddress))), N'')   AS PostalAddress,
                       NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(4000), c.PhysicalAddress))), N'') AS PhysicalAddress,
                       NULLIF(LTRIM(RTRIM(c.VATNo)), N'')                         AS VatNo,
                       NULLIF(LTRIM(RTRIM(c.TelNoA)), N'')                        AS Telephone,
                       NULLIF(LTRIM(RTRIM(c.TelNoB)), N'')                        AS Telephone2,
                       NULLIF(LTRIM(RTRIM(c.TelNoH)), N'')                        AS TelephoneHome,
                       NULLIF(LTRIM(RTRIM(c.FaxNo)), N'')                         AS FaxNo,
                       NULLIF(LTRIM(RTRIM(c.CellNo)), N'')                        AS CellNo,
                       NULLIF(LTRIM(RTRIM(c.EmailAddress)), N'')                  AS EmailAddress,
                       NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(4000), c.Comments))), N'')        AS Comments,
                       c.PosSystemId,
                       CONVERT(bit, c.isSDKLoaded)                                AS IsSdkLoaded,
                       c.SDKLoadDateTime                                          AS SdkLoadedAt,
                       c.CreateDateTime
                FROM [{$erp}].dbo.BRN_Customers c",
        ];
    }

    /**
     * Live and staging are forward-only; this runs against the local sandbox
     * only. Nothing reads these views but Partners' own procedures.
     */
    public function down(): void
    {
        $schema = config('agora.schema');

        foreach (array_keys($this->views((string) config('agora.source_databases.erp'))) as $view) {
            DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[{$view}];");
        }
    }
};
