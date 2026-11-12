<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Debtors — the statement ledger ZP asked for (slot 18a, 12 Nov 2026).
 *
 * ZP, 12 Nov 2026: "Transaction detail: MIST_Trans_Customer. Bank
 * Transactions: RCN_BankStatementLinesPumpIT." Their debtor statement is
 * built from those two tables and the take-on balance PumpIT holds per
 * customer, and so is Agora's from here on. agora.vw_DebtorLedger (the till's
 * DBF_POSTR_CUSTOMER) stays for what else reads it; the Debtors procedures
 * read this view instead.
 *
 * One row per posting to a till account (BranchId, AccountNo — the POS
 * account number, MIST's BusinessPartnerCode, BRN_Customers.DebtorID):
 *
 *   I / C  an invoice or credit note: ONE ROW PER DOCUMENT from
 *          MIST_Trans_Customer (TransactionType I or C, never the CASH till
 *          sales). Litres and fuel type are the document's fuel lines
 *          (Category FUEL…); the amount is every line, VAT inclusive.
 *   R      a payment: a bank line of RCN_BankStatementLinesPumpIT with
 *          Type 'Debtors', tied to the account through its CustomerCode —
 *          BRN_Customers (same site, same CustomerCode) gives the DebtorID.
 *          BRN_Customers is read directly, not through Partners'
 *          vw_PartnerCustomer, so Debtors does not wait on that module's
 *          deploy.
 *          A negative bank line on a debtor (money paid back) is a 'Refund'.
 *   O      the balance PumpIT took the account on with
 *          (BRN_Customers.OpeningPumpITBalance), dated the day the customer
 *          row was created. 25 of 897 customers carry one (12 Nov 2026).
 *
 * WHY THE TAKE-ON IS IN. ZP's own statement for NGS00013 (Kloppers, Ngwenya)
 * shows -15 275.10 due to the customer at 11 Oct 2026. That is, to the cent,
 * 11 134.15 taken on + every MIST invoice less credit to that day - every
 * Debtors bank line to that day. Without the take-on it is -26 409.25.
 *
 * MIST LOADS SOME DOCUMENTS TWICE. 4 713 of 63 994 account documents
 * (12 Nov 2026) are in the table twice or more — the same transaction,
 * slip, account and lines, loaded again on the next day's EOD, or an hour or
 * two later under the same one. Counting both would bill the debtor twice.
 * A load is identified by when it was loaded (EODDate + TransactionTime): the
 * lines of one load are seconds apart, a reload is hours or a day later. So
 * a document keeps the lines loaded within five minutes of its first line.
 * Checked against the till ledger (DBF_POSTR_CUSTOMER) for Jul–Sep 2026: the
 * totals agree to the cent at every site whose accounts both ledgers carry.
 *
 * KM and REG are NOT here. MIST_Trans_Customer gains ODO and REGNO when ZP
 * add them, and a view that names a column fails to create without it — so
 * agora.usp_Debtors_GetStatement looks them up itself, once the columns
 * exist.
 *
 * Reads only. Nothing here writes PumpIT.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_DebtorStatementLedger] AS
            WITH line AS (
                SELECT
                    t.MIST_BranchId                                            AS BranchId,
                    CONVERT(nvarchar(16), LTRIM(RTRIM(t.BusinessPartnerCode))) AS AccountNo,
                    NULLIF(LTRIM(RTRIM(t.BusinessPartnerName)), '')            AS AccountName,
                    CONVERT(nvarchar(40), LTRIM(RTRIM(t.TransactionNumber)))   AS DocumentNo,
                    NULLIF(LTRIM(RTRIM(t.DocumentNumber)), '')                 AS Reference,
                    t.TransactionType,
                    CONVERT(date, t.TransactionDate)                           AS TransactionDate,
                    NULLIF(LTRIM(RTRIM(t.OrderNumber)), '')                    AS OrderNo,
                    CASE WHEN t.Category LIKE 'FUEL%'
                         THEN CASE WHEN t.DebitCredit = 'C' THEN -t.Qty ELSE t.Qty END END AS Litres,
                    CASE WHEN t.Category LIKE 'FUEL%'
                         THEN COALESCE(NULLIF(LTRIM(RTRIM(t.StandardCategory)), ''),
                                       NULLIF(LTRIM(RTRIM(t.ItemDescription)), '')) END   AS FuelType,
                    CASE WHEN t.DebitCredit = 'C' THEN -ISNULL(t.LineTotalAmountIncl, 0)
                         ELSE ISNULL(t.LineTotalAmountIncl, 0) END             AS LineAmount,
                    DATEADD(second,
                            ISNULL(DATEDIFF(second, CONVERT(time, '00:00'), TRY_CONVERT(time, t.TransactionTime)), 0),
                            CONVERT(datetime2(0), CONVERT(date, t.EODDate)))  AS LoadedAt
                FROM [{$erp}].dbo.MIST_Trans_Customer t
                WHERE t.TransactionType IN ('I', 'C')
                  AND LTRIM(RTRIM(t.BusinessPartnerCode)) NOT IN ('', 'CASH')
            ), loaded AS (
                SELECT l.*,
                       MIN(l.LoadedAt) OVER (PARTITION BY l.BranchId, l.AccountNo, l.DocumentNo, l.Reference, l.TransactionType) AS FirstLoadedAt
                FROM line l
            ), doc AS (
                SELECT BranchId, AccountNo, DocumentNo, Reference, TransactionType,
                       MAX(AccountName)                       AS AccountName,
                       MIN(TransactionDate)                   AS TransactionDate,
                       MAX(OrderNo)                           AS OrderNo,
                       SUM(Litres)                            AS Litres,
                       CASE WHEN MIN(FuelType) = MAX(FuelType) THEN MIN(FuelType)
                            ELSE MIN(FuelType) + ' / ' + MAX(FuelType) END AS FuelType,
                       SUM(LineAmount)                        AS Amount
                FROM loaded
                WHERE DATEDIFF(second, FirstLoadedAt, LoadedAt) <= 300
                GROUP BY BranchId, AccountNo, DocumentNo, Reference, TransactionType
            )
            SELECT d.BranchId,
                   d.AccountNo,
                   d.AccountName,
                   d.TransactionDate,
                   CONVERT(char(1), d.TransactionType)                         AS TranCode,
                   CONVERT(nvarchar(20), CASE d.TransactionType WHEN 'C' THEN 'Credit note' ELSE 'Invoice' END) AS TranType,
                   d.DocumentNo,
                   CONVERT(nvarchar(510), d.Reference)                         AS [Description],
                   d.OrderNo,
                   CONVERT(decimal(18,3), d.Litres)                            AS Litres,
                   CONVERT(nvarchar(100), d.FuelType)                          AS FuelType,
                   CONVERT(decimal(18,2), CASE WHEN d.Amount > 0 THEN d.Amount ELSE 0 END)  AS Debit,
                   CONVERT(decimal(18,2), CASE WHEN d.Amount < 0 THEN -d.Amount ELSE 0 END) AS Credit,
                   CONVERT(decimal(18,2), d.Amount)                            AS Amount
            FROM doc d

            UNION ALL

            SELECT b.SSBranchId,
                   CONVERT(nvarchar(16), LTRIM(RTRIM(c.DebtorID))),
                   NULLIF(LTRIM(RTRIM(c.CustomerName)), ''),
                   CONVERT(date, b.LineDate),
                   'R',
                   CONVERT(nvarchar(20), CASE WHEN b.Amount < 0 THEN 'Refund' ELSE 'Payment' END),
                   CONVERT(nvarchar(40), b.BankStatementLineID),
                   CONVERT(nvarchar(510), NULLIF(LTRIM(RTRIM(b.[Description])), '')),
                   NULL,
                   NULL,
                   NULL,
                   CONVERT(decimal(18,2), CASE WHEN b.Amount < 0 THEN -b.Amount ELSE 0 END),
                   CONVERT(decimal(18,2), CASE WHEN b.Amount > 0 THEN b.Amount ELSE 0 END),
                   CONVERT(decimal(18,2), -ISNULL(b.Amount, 0))
            FROM [{$erp}].dbo.RCN_BankStatementLinesPumpIT b
            JOIN [{$erp}].dbo.BRN_Customers c
              ON c.SSBranchId = b.SSBranchId AND LTRIM(RTRIM(c.CustomerCode)) = LTRIM(RTRIM(b.CustomerCode))
            WHERE b.[Type] = 'Debtors'
              AND b.LineDate IS NOT NULL
              AND LTRIM(RTRIM(ISNULL(c.DebtorID, ''))) <> ''

            UNION ALL

            SELECT c.SSBranchId,
                   CONVERT(nvarchar(16), LTRIM(RTRIM(c.DebtorID))),
                   NULLIF(LTRIM(RTRIM(c.CustomerName)), ''),
                   ISNULL(CONVERT(date, c.CreateDateTime), CONVERT(date, '19000101')),
                   'O',
                   CONVERT(nvarchar(20), 'Balance taken on'),
                   CONVERT(nvarchar(40), LTRIM(RTRIM(c.CustomerCode))),
                   CONVERT(nvarchar(510), 'Opening balance taken on from PumpIT'),
                   NULL,
                   NULL,
                   NULL,
                   CONVERT(decimal(18,2), CASE WHEN c.OpeningPumpITBalance > 0 THEN c.OpeningPumpITBalance ELSE 0 END),
                   CONVERT(decimal(18,2), CASE WHEN c.OpeningPumpITBalance < 0 THEN -c.OpeningPumpITBalance ELSE 0 END),
                   CONVERT(decimal(18,2), c.OpeningPumpITBalance)
            FROM [{$erp}].dbo.BRN_Customers c
            WHERE LTRIM(RTRIM(ISNULL(c.DebtorID, ''))) <> ''
              AND ISNULL(c.OpeningPumpITBalance, 0) <> 0;
        ");

        MigrationHelper::recordVersion(
            '1.35',
            'Debtors: statements read from MIST_Trans_Customer (invoices, one row per document, reloads counted once), '
            .'RCN_BankStatementLinesPumpIT (Debtors bank lines by customer code) and the PumpIT take-on balance '
            .'(agora.vw_DebtorStatementLedger), as ZP asked on 12 Nov 2026.'
        );
    }

    /** Local sandbox only. Live is forward-only. */
    public function down(): void
    {
        $schema = config('agora.schema');

        DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[vw_DebtorStatementLedger];");
    }
};
