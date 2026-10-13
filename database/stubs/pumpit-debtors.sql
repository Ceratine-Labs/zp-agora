/* ============================================================================
   [PumpIT] debtor accounts and their ledger — LOCAL STUB ONLY.

   The two MIST tables the Debtors module (slot 18) reads through
   agora.vw_DebtorAccount and agora.vw_DebtorLedger. Without them those views
   will not CREATE locally, because three-part names are resolved when a view
   is created.

   Types copied from the live instance on 13 Oct 2026, column for column, with
   the customer's clustered keys:

     DBF_CLDB_CUSTOMER   1,406 rows   the POS account master, one row per
                                      account per site (PK SSBranchId, CNUM,
                                      INIT — 8 accounts carry two INITs). Its
                                      BALANCE, BFWD, BAL1..5, EMAIL and
                                      STAT_SEND are NULL on every live row:
                                      the import does not carry them.
     DBF_POSTR_CUSTOMER  13.4M rows   every POS posting. CUST = 'A' marks an
                                      ACCOUNT posting (43,719 rows); the rest
                                      are cash sales. TRCOD I/D/E debit,
                                      C/F/G/R credit.

   No rows: tests write their own TEST- accounts and remove them.

   Agora never writes these tables, here or on live. This file is NEVER run
   against the customer's instance.

   Run by scripts/local-sql.sh up. It depends on no other stub file.
   ============================================================================ */

IF OBJECT_ID('dbo.DBF_CLDB_CUSTOMER') IS NULL
CREATE TABLE dbo.DBF_CLDB_CUSTOMER (
    [SSBranchId] INT NOT NULL,
    [DRC] CHAR(1) NULL,
    [CNUM] CHAR(10) NOT NULL,
    [SNAM] CHAR(30) NULL,
    [HOCODE] CHAR(6) NULL,
    [TYPE] CHAR(1) NULL,
    [INIT] CHAR(10) NOT NULL,
    [CONTACT] CHAR(30) NULL,
    [BADD1] CHAR(30) NULL,
    [BADD2] CHAR(30) NULL,
    [BADD3] CHAR(30) NULL,
    [BADD4] CHAR(30) NULL,
    [PADD1] CHAR(30) NULL,
    [PADD2] CHAR(30) NULL,
    [PADD3] CHAR(30) NULL,
    [PADD4] CHAR(30) NULL,
    [TELB] CHAR(20) NULL,
    [TELH] CHAR(20) NULL,
    [CAT] CHAR(6) NULL,
    [DCAT] CHAR(3) NULL,
    [PRICECAT] CHAR(1) NULL,
    [TDISCP] DECIMAL(18,6) NULL,
    [CRLIMIT] DECIMAL(18,6) NULL,
    [INTRATE] DECIMAL(18,6) NULL,
    [INTYN] CHAR(1) NULL,
    [TERMS] CHAR(1) NULL,
    [SDISCP] DECIMAL(18,6) NULL,
    [SDISCT] CHAR(1) NULL,
    [MINP] DECIMAL(18,6) NULL,
    [NMONTHS] INT NULL,
    [STAT] CHAR(2) NULL,
    [BALANCE] DECIMAL(18,6) NULL,
    [BFWD] DECIMAL(18,6) NULL,
    [BAL1] DECIMAL(18,6) NULL,
    [BAL2] DECIMAL(18,6) NULL,
    [BAL3] DECIMAL(18,6) NULL,
    [BAL4] DECIMAL(18,6) NULL,
    [BAL5] DECIMAL(18,6) NULL,
    [M_PAID] DECIMAL(18,6) NULL,
    [DUE_LAST] DECIMAL(18,6) NULL,
    [INSTALL] DECIMAL(18,6) NULL,
    [M_INT] DECIMAL(18,6) NULL,
    [M_TURN] DECIMAL(18,6) NULL,
    [Y_TURN] DECIMAL(18,6) NULL,
    [M_VAT] DECIMAL(18,6) NULL,
    [Y_VAT] DECIMAL(18,6) NULL,
    [LOCKED] BIT NULL,
    [REPCODE] CHAR(6) NULL,
    [GST] CHAR(15) NULL,
    [PAYDATE] DATETIME NULL,
    [PAYAMT] DECIMAL(18,6) NULL,
    [BLOCKED] CHAR(1) NULL,
    [PRNFLAG] CHAR(1) NULL,
    [ARREARS] DECIMAL(18,6) NULL,
    [COY_REG] CHAR(20) NULL,
    [CONTACT2] CHAR(30) NULL,
    [TELB2] CHAR(20) NULL,
    [TELH2] CHAR(20) NULL,
    [TELC] CHAR(20) NULL,
    [TELC2] CHAR(20) NULL,
    [EMAIL] CHAR(50) NULL,
    [EMAIL2] CHAR(50) NULL,
    [LEADTIME] INT NULL,
    [STOCKHOLD] DECIMAL(18,6) NULL,
    [DOC_SORT] CHAR(1) NULL,
    [STAT_SEND] CHAR(1) NULL,
    [POD_CARDID] CHAR(8) NULL,
    [OPT_CARDNO] CHAR(20) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    CONSTRAINT [PK_DBF_CLDB_CUSTOMER] PRIMARY KEY CLUSTERED ([SSBranchId], [CNUM], [INIT])
);
GO

IF OBJECT_ID('dbo.DBF_POSTR_CUSTOMER') IS NULL
CREATE TABLE dbo.DBF_POSTR_CUSTOMER (
    [SSBranchId] INT NOT NULL,
    [DRC] CHAR(1) NULL,
    [TERMNUM] CHAR(3) NULL,
    [DATE] DATETIME NOT NULL,
    [USERID] CHAR(6) NULL,
    [SHIFT] CHAR(2) NULL,
    [TRCOD] CHAR(1) NOT NULL,
    [CNUM] CHAR(10) NOT NULL,
    [CNAM] CHAR(30) NULL,
    [REPCODE] CHAR(6) NULL,
    [DESC] CHAR(20) NULL,
    [ORDNUM] CHAR(10) NULL,
    [DRCR] CHAR(1) NULL,
    [REF] CHAR(20) NOT NULL,
    [LOGFILE] CHAR(10) NOT NULL,
    [CUST] CHAR(1) NULL,
    [GOODS] DECIMAL(18,6) NULL,
    [VAT] DECIMAL(18,6) NULL,
    [TOTAL] DECIMAL(18,6) NULL,
    [CHANGE] DECIMAL(18,6) NULL,
    [ACTYPE] CHAR(1) NULL,
    [PRINTED] CHAR(1) NULL,
    [STATCNTR] INT NULL,
    [CURRENT] CHAR(1) NULL,
    [LOCATION] CHAR(10) NOT NULL,
    [EOD_CNTR] CHAR(8) NOT NULL,
    [EOW_CNTR] CHAR(8) NULL,
    [EXPORTED] CHAR(1) NULL,
    [SYSDATE] DATETIME NULL,
    [SYSTIME] CHAR(8) NULL,
    [WINVER] BIT NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    CONSTRAINT [PK_DBF_POSTR_CUSTOMER_1] PRIMARY KEY CLUSTERED
        ([SSBranchId], [DATE], [TRCOD], [CNUM], [REF], [LOGFILE], [LOCATION], [EOD_CNTR])
);
GO
