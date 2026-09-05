/* ============================================================================
   [PumpIT] recon tables — LOCAL STUB ONLY.

   The shape, not the estate. The agora.vw_* views name PumpIT across
   databases and three-part naming is same-instance only, so the local
   container needs a PumpIT with these tables in it or the views will not
   create and the ported preview procedures will not compile.

   Columns are exactly the ones the five agora.usp_Recon_Preview* procedures
   read, and no others. Types are the ones the procedure bodies imply — money
   for amounts, because the originals declare their table variables that way.
   This file is NEVER run against the customer's instance: there the real
   tables already exist, with 286,899 bank lines and 1.38 million deposit rows
   between them.

   Run by scripts/local-sql.sh up.
   ============================================================================ */

IF OBJECT_ID('dbo.RCN_BankStatementLinesPumpIT') IS NULL
CREATE TABLE dbo.RCN_BankStatementLinesPumpIT (
    BankStatementLineID BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    SSBranchId          INT           NOT NULL,
    LineDate            DATETIME      NOT NULL,
    Description         NVARCHAR(200) NULL,
    Amount              MONEY         NOT NULL,
    -- The channel: ABSA, FNB, CashMachine, CashBags, SmartATM, UnIdentified.
    Type                NVARCHAR(20)  NOT NULL,
    -- 1 = never classified, 2 = classified. Every recon screen filters on 2.
    IDState             INT           NOT NULL,
    -- 1 = unreconciled, 2 = reconciled. Agrees with ReconBatchNo at row level.
    ReconState          INT           NOT NULL,
    ReconBatchNo        INT           NOT NULL DEFAULT 0
);

IF OBJECT_ID('dbo.BRN_AutoReconCriteria') IS NULL
CREATE TABLE dbo.BRN_AutoReconCriteria (
    AutoReconId          INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    SSBranchId           INT          NOT NULL,
    BankReconArea        NVARCHAR(20) NOT NULL,
    ProcessOrder         INT          NOT NULL,
    -- BANK_EndPosition is a LENGTH in ABSA/FNB/SmartATM and an END POSITION in
    -- CashMachine/CashBags (finding 9). The procedures resolve it either way
    -- and report which reading they used; the column keeps the raw value.
    BANK_StartPosition   INT          NULL,
    BANK_EndPosition     INT          NULL,
    BANK_StartPosition2  INT          NULL,
    BANK_EndPosition2    INT          NULL,
    MOPS_StartPosition   INT          NULL,
    MOPS_EndPosition     INT          NULL,
    FILTER_Value         NVARCHAR(50) NULL,
    FILTER_StartPosition INT          NULL,
    FILTER_EndPosition   INT          NULL
);

IF OBJECT_ID('dbo.BRN_DailyBankingABSA') IS NULL
CREATE TABLE dbo.BRN_DailyBankingABSA (
    SSBranchId         INT          NOT NULL,
    TransactionDate    DATETIME     NOT NULL,
    BatchNumber        INT          NULL,
    MerchantNumber     NVARCHAR(20) NULL,
    TransactionAmount  MONEY        NOT NULL,
    -- The live recon stamp. The plain ReconBatchNo column on this family is 0
    -- on all 1.38 million rows and is not stubbed: it is dead, and a query
    -- that filters on it returns nothing, silently.
    ReconBatchNoPumpIT INT          NOT NULL DEFAULT 0
);

IF OBJECT_ID('dbo.BRN_DailyBankingFNB') IS NULL
CREATE TABLE dbo.BRN_DailyBankingFNB (
    SSBranchId         INT          NOT NULL,
    TransactionDate    DATETIME     NOT NULL,
    -- Free text in five formats, and two rows in production hold '#REF!'.
    BatchNo            NVARCHAR(50) NULL,
    MerchantNo         NVARCHAR(20) NULL,
    Amount             MONEY        NOT NULL,
    ReconBatchNoPumpIT INT          NOT NULL DEFAULT 0
);

IF OBJECT_ID('dbo.BRN_DailyBankingSmartATM') IS NULL
CREATE TABLE dbo.BRN_DailyBankingSmartATM (
    -- This table has a key of its own, unlike most of the family. Stamping
    -- addresses SmartATM deposits by it rather than by a composite built out
    -- of TerminalId, TraceNo and a FLOAT UniqueNo.
    DailyBankingSmartATMID INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    SSBranchId         INT          NOT NULL,
    TerminalId         NVARCHAR(30) NULL,
    TraceNo            NVARCHAR(30) NULL,
    UniqueNo           NVARCHAR(30) NULL,
    DepositDateTime    DATETIME     NULL,
    Deposited          MONEY        NOT NULL,
    ReconBatchNoPumpIT INT          NOT NULL DEFAULT 0
);

IF OBJECT_ID('dbo.BRN_SmartATM') IS NULL
CREATE TABLE dbo.BRN_SmartATM (
    SSBranchId      INT          NOT NULL,
    TerminalId      NVARCHAR(30) NULL,
    TraceNo         NVARCHAR(30) NULL,
    UniqueNo        NVARCHAR(30) NULL,
    DepositDateTime DATETIME     NULL
);

IF OBJECT_ID('dbo.BRN_DailyBankingCashBags') IS NULL
CREATE TABLE dbo.BRN_DailyBankingCashBags (
    DailyBankingCashBagID BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    SSBranchId            INT          NOT NULL,
    TransactionDate       DATETIME     NOT NULL,
    CashBagNo             NVARCHAR(50) NULL,
    CashBagAmount         MONEY        NOT NULL,
    ReconBatchNoPumpIT    INT          NOT NULL DEFAULT 0
);

IF OBJECT_ID('dbo.BRN_DailyBankingDeposita') IS NULL
CREATE TABLE dbo.BRN_DailyBankingDeposita (
    SSBranchId         INT          NOT NULL,
    TransactionDate    DATETIME     NOT NULL,
    SlipNo             NVARCHAR(50) NULL,
    DepositaAmount     MONEY        NOT NULL,
    ReconBatchNoPumpIT INT          NOT NULL DEFAULT 0
);

IF OBJECT_ID('dbo.BRN_DropSafe') IS NULL
CREATE TABLE dbo.BRN_DropSafe (
    SSBranchId   INT          NOT NULL,
    BagNo        NVARCHAR(50) NULL,
    CollectionId INT          NULL
);

IF OBJECT_ID('dbo.BRN_DropSafe_Collection') IS NULL
CREATE TABLE dbo.BRN_DropSafe_Collection (
    SSBranchId   INT          NOT NULL,
    CollectionId INT          NOT NULL,
    DBagNo       NVARCHAR(50) NULL
);

/* The batch-number counter. dbo.sp_GenerateReconBatchNo reads SectionId 1 and
   increments it — with SELECT then UPDATE and no lock, which is the race their
   own source comments on ("2023/10/27 duplicates for some reason ????").
   agora.usp_Recon_Commit uses the same counter so the numbers cannot collide,
   but allocates with a single UPDATE ... OUTPUT. */
IF OBJECT_ID('dbo.SS_UniqueNumber') IS NULL
BEGIN
    CREATE TABLE dbo.SS_UniqueNumber (
        SectionId        INT NOT NULL PRIMARY KEY,
        NextUniqueNumber INT NOT NULL
    );
    INSERT INTO dbo.SS_UniqueNumber (SectionId, NextUniqueNumber) VALUES (1, 3000);
END
