<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Debtors — statements of account, and mailing them (slot 18, 13 Oct 2026).
 *
 * THE LEDGER IS THE CUSTOMER'S, AND ONLY READ. Every posting to an account at
 * the till — invoice, receipt, credit note, journal — is in
 * `PumpIT.dbo.DBF_POSTR_CUSTOMER`, which MIST loads overnight. Agora reads it
 * through `agora.vw_DebtorLedger` and writes nothing anywhere in PumpIT. The
 * account master (name, address, terms, credit limit) is
 * `DBF_CLDB_CUSTOMER`, read through `agora.vw_DebtorAccount`.
 *
 * What the customer's databases have nowhere to put, this creates:
 *
 *   agora.DebtorContact        where a debtor's statement goes. No email
 *                              address is held anywhere in the estate — 0 of
 *                              1,406 MIST accounts, 2 of 778 PumpIT customers
 *                              (13 Oct 2026) — so the address list is Agora's,
 *                              captured per account per site, with the switch
 *                              that puts the account on the monthly run.
 *   agora.DebtorStatementSend  one row per statement mailed, or tried: to whom,
 *                              for which period, the balances it showed, and
 *                              whether it went. What a debtor was told is
 *                              something somebody will be asked about.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('DebtorContact', function (Blueprint $table) {
            // The POS account number, trimmed — DBF_POSTR_CUSTOMER.CNUM.
            $table->string('AccountNo', 10);
            $table->string('Email', 200)->nullable();
            // Further addresses, comma separated, copied on every statement.
            $table->string('CcEmail', 400)->nullable();
            // On the monthly run. Off until somebody has checked the address.
            $table->boolean('AutoSend')->default(false);
            $table->string('Note', 300)->nullable();
        });

        MigrationHelper::naturalKey('DebtorContact', ['BranchId', 'AccountNo']);
        MigrationHelper::rowVersion('DebtorContact');

        MigrationHelper::table('DebtorStatementSend', function (Blueprint $table) {
            $table->string('AccountNo', 10);
            $table->string('AccountName', 60)->nullable();
            $table->date('PeriodFrom');
            $table->date('PeriodTo');
            MigrationHelper::money($table, 'OpeningBalance');
            MigrationHelper::money($table, 'ClosingBalance');
            $table->string('SentTo', 600)->nullable();
            // config('debtors.send_mode') at the time: 'test' went to the
            // test address, never to the debtor.
            $table->string('Mode', 10);
            // 'manual' — a press on the screen; 'schedule' — the monthly run.
            $table->string('Trigger', 10);
            // 'sent' or 'failed'.
            $table->string('Status', 10);
            $table->string('Error', 400)->nullable();
        });

        // The monthly run asks "was this account's statement for this period
        // already sent?" for every account it considers.
        $schema = config('agora.schema');
        DB::statement("CREATE INDEX [IX_DebtorStatementSend_Account] ON [{$schema}].[DebtorStatementSend] ([BranchId], [AccountNo], [PeriodTo]) INCLUDE ([Status], [Trigger], [Mode]);");

        $this->views();

        MigrationHelper::recordVersion(
            '1.34',
            'Debtors: statements of account read from the customer\'s POS ledger (DBF_POSTR_CUSTOMER), '
            .'an Agora-held address list per account per site, and a record of every statement mailed.'
        );
    }

    protected function views(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        /*
         * One row per account per site. The customer's key is (SSBranchId,
         * CNUM, INIT), and eight accounts carry two INITs, so the lowest INIT
         * stands for the account — a label lookup must never change a count.
         * Everything is trimmed once, here: the columns are CHAR(30).
         *
         * Not here, because the import leaves them NULL on every live row
         * (13 Oct 2026): BALANCE, BFWD, BAL1..5, EMAIL, EMAIL2, STAT_SEND. The
         * balance is worked out from the ledger; the address is DebtorContact.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_DebtorAccount] AS
            SELECT
                c.SSBranchId                                   AS BranchId,
                RTRIM(LTRIM(c.CNUM))                           AS AccountNo,
                NULLIF(RTRIM(LTRIM(c.SNAM)), '')               AS AccountName,
                NULLIF(RTRIM(LTRIM(c.CONTACT)), '')            AS Contact,
                NULLIF(RTRIM(LTRIM(c.BADD1)), '')              AS Address1,
                NULLIF(RTRIM(LTRIM(c.BADD2)), '')              AS Address2,
                NULLIF(RTRIM(LTRIM(c.BADD3)), '')              AS Address3,
                NULLIF(RTRIM(LTRIM(c.BADD4)), '')              AS Address4,
                NULLIF(RTRIM(LTRIM(c.PADD1)), '')              AS Postal1,
                NULLIF(RTRIM(LTRIM(c.PADD2)), '')              AS Postal2,
                NULLIF(RTRIM(LTRIM(c.PADD3)), '')              AS Postal3,
                NULLIF(RTRIM(LTRIM(c.PADD4)), '')              AS Postal4,
                NULLIF(RTRIM(LTRIM(c.TELB)), '')               AS Telephone,
                NULLIF(RTRIM(LTRIM(c.TELC)), '')               AS Cellphone,
                NULLIF(RTRIM(LTRIM(c.GST)), '')                AS VatNo,
                NULLIF(RTRIM(LTRIM(c.COY_REG)), '')            AS CompanyReg,
                CONVERT(decimal(18,2), c.CRLIMIT)              AS CreditLimit,
                CONVERT(bit, CASE WHEN c.BLOCKED IN ('Y', 'y', '1') OR c.LOCKED = 1 THEN 1 ELSE 0 END) AS IsBlocked
            FROM (
                SELECT x.*, ROW_NUMBER() OVER (PARTITION BY x.SSBranchId, RTRIM(LTRIM(x.CNUM)) ORDER BY x.INIT) AS rn
                FROM [{$erp}].dbo.DBF_CLDB_CUSTOMER x
            ) c
            WHERE c.rn = 1;
        ");

        /*
         * Every posting to an ACCOUNT, once.
         *
         * CUST = 'A' is an account posting; the other 99.7% of the table is
         * cash sales. Sign from DRCR: D raises what the debtor owes (I
         * invoice, D debit note, E debit journal), C lowers it (R receipt,
         * C credit note, F credit journal, G credit).
         *
         * ONCE: 47 receipts are in the table twice (13 Oct 2026) — loaded once
         * with a blank day-end number and again with one, same date, account,
         * reference and amount. Counting both would show a debtor as having
         * paid twice. One row per (site, date, code, account, reference,
         * amount) is kept, preferring the one carrying a day-end number. Two
         * genuinely different documents cannot collapse: they differ in
         * reference or amount.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_DebtorLedger] AS
            SELECT
                p.SSBranchId                                   AS BranchId,
                RTRIM(LTRIM(p.CNUM))                           AS AccountNo,
                NULLIF(RTRIM(LTRIM(p.CNAM)), '')               AS AccountName,
                CONVERT(date, p.[DATE])                        AS TransactionDate,
                p.TRCOD                                        AS TranCode,
                CASE p.TRCOD
                    WHEN 'I' THEN 'Invoice'
                    WHEN 'D' THEN 'Debit note'
                    WHEN 'E' THEN 'Debit journal'
                    WHEN 'R' THEN 'Payment'
                    WHEN 'C' THEN 'Credit note'
                    WHEN 'F' THEN 'Credit journal'
                    WHEN 'G' THEN 'Credit'
                    ELSE CASE WHEN p.DRCR = 'C' THEN 'Credit' ELSE 'Debit' END
                END                                            AS TranType,
                NULLIF(RTRIM(LTRIM(p.REF)), '')                AS Reference,
                NULLIF(RTRIM(LTRIM(p.[DESC])), '')             AS [Description],
                NULLIF(RTRIM(LTRIM(p.ORDNUM)), '')             AS OrderNo,
                NULLIF(RTRIM(LTRIM(p.TERMNUM)), '')            AS TillNo,
                CONVERT(decimal(18,2), CASE WHEN p.DRCR = 'C' THEN 0 ELSE ISNULL(p.TOTAL, 0) END) AS Debit,
                CONVERT(decimal(18,2), CASE WHEN p.DRCR = 'C' THEN ISNULL(p.TOTAL, 0) ELSE 0 END) AS Credit,
                CONVERT(decimal(18,2), CASE WHEN p.DRCR = 'C' THEN -ISNULL(p.TOTAL, 0) ELSE ISNULL(p.TOTAL, 0) END) AS Amount
            FROM (
                SELECT x.*,
                       ROW_NUMBER() OVER (
                           PARTITION BY x.SSBranchId, x.[DATE], x.TRCOD, x.CNUM, RTRIM(LTRIM(x.REF)), x.TOTAL
                           ORDER BY CASE WHEN RTRIM(LTRIM(x.EOD_CNTR)) = '' THEN 1 ELSE 0 END, x.EOD_CNTR DESC, x.LOGFILE, x.LOCATION
                       ) AS rn
                FROM [{$erp}].dbo.DBF_POSTR_CUSTOMER x
                WHERE x.CUST = 'A'
                  AND RTRIM(LTRIM(x.CNUM)) <> ''
                  AND RTRIM(LTRIM(x.CNUM)) <> 'CASH'
            ) p
            WHERE p.rn = 1;
        ");
    }

    /**
     * Local sandbox only. Live is forward-only, and the send record is the
     * only account of what a debtor was told.
     */
    public function down(): void
    {
        $schema = config('agora.schema');

        foreach (['vw_DebtorLedger', 'vw_DebtorAccount'] as $view) {
            DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[{$view}];");
        }

        MigrationHelper::drop('DebtorStatementSend');
        MigrationHelper::drop('DebtorContact');
    }
};
