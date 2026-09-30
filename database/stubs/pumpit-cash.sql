-- ============================================================================
-- The local [PumpIT] stub, widened for Cash.
--
-- Shape only, never the estate — the same contract as the other files in this
-- directory. Run against the LOCAL container's PumpIT database, after
-- pumpit-recon.sql and pumpit-reports.sql (scripts/local-sql.sh runs it in
-- that order).
--
-- Idempotent: every statement is guarded, so running it twice changes nothing.
--
--   docker exec -i agora-sql /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa \
--       -P "$AGORA_DB_PASSWORD" -C -b -d PumpIT -i /dev/stdin < database/stubs/pumpit-cash.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Z-read allocation (T036)
--
--   LOCATION   the POS family the Z-read came from — ARCH, WINBRANCH, AURA,
--              ARCHLIQ, NAMOS, PILOT, GAAP, NIGHTSB on the customer's instance
--              (measured 27 Sep 2026). It is part of the source's primary key
--              (SSBranchId, DATE, EOD_CNTR, TERMNUM, LOGFILE, USERID, LOCATION),
--              and a till's operator ID means nothing without it: operator 4 on
--              ARCH and operator 4 on AURA are different people.
--
-- Since 27 Sep 2026 pumpit-reports.sql creates DBF_P3TRANS_ZREAD with LOCATION
-- (VARCHAR(10) NOT NULL, as on the customer's instance), so on a new container
-- this does nothing. It stays for a container whose table was created before
-- that: there it adds the column NULLable, because the table already holds
-- rows with no location to give them. agora.vw_CashZread reads it through
-- ISNULL(…, ''), so such a row still reads.
--
-- Where this came from: the MIST_Import database has no DBF_P3TRANS_ZREAD at
-- all (only NAMOS_DBF_P3TRANS_ZREAD, the NAMOS landing table), so no
-- [MIST_Import] stub is needed for Cash. The Z-reads Cash allocates are
-- PumpIT's.
-- ----------------------------------------------------------------------------

IF OBJECT_ID('dbo.DBF_P3TRANS_ZREAD') IS NOT NULL
   AND COL_LENGTH('dbo.DBF_P3TRANS_ZREAD', 'LOCATION') IS NULL
    ALTER TABLE dbo.DBF_P3TRANS_ZREAD ADD [LOCATION] VARCHAR(10) NULL;
GO

-- ----------------------------------------------------------------------------
-- 2. Daily banking (T037): the cashup header and every BRN_DailyBanking leg.
--
-- The customer's shapes, column for column, as measured on 27 Sep 2026
-- (sys.columns on PumpIT, with the defaults and the primary keys). ntext is
-- NVARCHAR(MAX) here, as elsewhere in the stubs.
--
-- pumpit-recon.sql and pumpit-reports.sql already create six of these with
-- the handful of columns Recon and Reports read — no TillNo or ShiftNo on the
-- recon ones. On such a container each missing column is ADDED rather than
-- the table rebuilt, because those tables hold rows other lanes wrote: a NOT
-- NULL column with a default is added WITH VALUES, anything else NULLable.
-- A row that pre-dates this therefore has no till and no shift, and joins to
-- no cashup — which is the truth about it.
--
-- The trigger the customer's header carries (dbo.trg_Sync_BRN_DailyBanking,
-- which rewrites Alteryx.dbo.Rev_BRN_DailyBanking on every statement, and is
-- DISABLED on the customer's instance) is deliberately NOT stubbed:
-- agora.usp_Cash_SaveCashupLeg refuses while it is enabled, and a test that
-- wants that refusal creates its own.
-- ----------------------------------------------------------------------------

IF OBJECT_ID('dbo.BRN_DailyBanking') IS NULL
CREATE TABLE dbo.BRN_DailyBanking (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [EmployeeCode] NVARCHAR(11) NOT NULL,
    [ClosingBalance] MONEY NULL DEFAULT ((0)),
    [CreditCardAmount] MONEY NULL DEFAULT ((0)),
    [EFuelAmount] MONEY NULL DEFAULT ((0)),
    [DirectDepositAmount] MONEY NULL DEFAULT ((0)),
    [EmployeeAmount] MONEY NULL DEFAULT ((0)),
    [Posted] BIT NOT NULL DEFAULT ((0)),
    [DayEndNo] NVARCHAR(50) NULL,
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [CardAmount1] MONEY NULL DEFAULT ((0)),
    [CardAmount2] MONEY NULL DEFAULT ((0)),
    [Note] NVARCHAR(MAX) NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [EFuel_IsSdkLoaded] BIT NULL DEFAULT ((0)),
    [EFuel_SdkMessage] VARCHAR(200) NULL,
    [EFuel_SdkLoadDateTime] DATETIME NULL,
    [CCard1_IsSdkLoaded] BIT NULL DEFAULT ((0)),
    [CCard1_SdkMessage] VARCHAR(200) NULL,
    [CCard1_SdkLoadDateTime] DATETIME NULL,
    [CCard2_IsSdkLoaded] BIT NULL DEFAULT ((0)),
    [CCard2_SdkMessage] VARCHAR(200) NULL,
    [CCard2_SdkLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [EFuel_EODNO] VARCHAR(10) NULL,
    [CCard1_EODNO] VARCHAR(10) NULL,
    [CCard2_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBanking] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBanking', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_DailyBanking_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'EmployeeCode') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [EmployeeCode] NVARCHAR(11) NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'ClosingBalance') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [ClosingBalance] MONEY NULL CONSTRAINT [DF_BRN_DailyBanking_ClosingBalance] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBanking', 'CreditCardAmount') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [CreditCardAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBanking_CreditCardAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBanking', 'EFuelAmount') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [EFuelAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBanking_EFuelAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBanking', 'DirectDepositAmount') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [DirectDepositAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBanking_DirectDepositAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBanking', 'EmployeeAmount') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [EmployeeAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBanking_EmployeeAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBanking', 'Posted') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [Posted] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBanking_Posted] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'DayEndNo') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [DayEndNo] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBanking_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'CardAmount1') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [CardAmount1] MONEY NULL CONSTRAINT [DF_BRN_DailyBanking_CardAmount1] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBanking', 'CardAmount2') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [CardAmount2] MONEY NULL CONSTRAINT [DF_BRN_DailyBanking_CardAmount2] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBanking', 'Note') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [Note] NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBanking_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBanking', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBanking_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBanking', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'EFuel_IsSdkLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [EFuel_IsSdkLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBanking_EFuel_IsSdkLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBanking', 'EFuel_SdkMessage') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [EFuel_SdkMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'EFuel_SdkLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [EFuel_SdkLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'CCard1_IsSdkLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [CCard1_IsSdkLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBanking_CCard1_IsSdkLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBanking', 'CCard1_SdkMessage') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [CCard1_SdkMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'CCard1_SdkLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [CCard1_SdkLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'CCard2_IsSdkLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [CCard2_IsSdkLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBanking_CCard2_IsSdkLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBanking', 'CCard2_SdkMessage') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [CCard2_SdkMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'CCard2_SdkLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [CCard2_SdkLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'EFuel_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [EFuel_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'CCard1_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [CCard1_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'CCard2_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [CCard2_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBanking', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBanking ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBanking_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingABSA') IS NULL
CREATE TABLE dbo.BRN_DailyBankingABSA (
    [SSBranchId] INT NOT NULL,
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [RetrievalRefNumber] NVARCHAR(12) NOT NULL DEFAULT ((0)),
    [TransactionAuthCode] NVARCHAR(10) NOT NULL,
    [ABSA_TransactionDate] DATETIME NOT NULL,
    [ABSA_TransactionTime] DATETIME NOT NULL,
    [TransactionAmount] MONEY NOT NULL,
    [BatchNumber] INT NULL DEFAULT ((0)),
    [MerchantNumber] NVARCHAR(12) NULL,
    [ReconBatchNo] INT NULL DEFAULT ((0)),
    [ReconBatchNoPumpIT] INT NULL DEFAULT ((0)),
    [Adjusted] BIT NULL DEFAULT ((0)),
    [AdjustedBy] MONEY NULL DEFAULT ((0.00)),
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingABSA] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[RetrievalRefNumber],[ABSA_TransactionDate],[ABSA_TransactionTime],[TransactionAmount])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'RetrievalRefNumber') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [RetrievalRefNumber] NVARCHAR(12) NOT NULL CONSTRAINT [DF_BRN_DailyBankingABSA_RetrievalRefNumber] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'TransactionAuthCode') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [TransactionAuthCode] NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'ABSA_TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [ABSA_TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'ABSA_TransactionTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [ABSA_TransactionTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'TransactionAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [TransactionAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'BatchNumber') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [BatchNumber] INT NULL CONSTRAINT [DF_BRN_DailyBankingABSA_BatchNumber] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'MerchantNumber') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [MerchantNumber] NVARCHAR(12) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'ReconBatchNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [ReconBatchNo] INT NULL CONSTRAINT [DF_BRN_DailyBankingABSA_ReconBatchNo] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'ReconBatchNoPumpIT') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [ReconBatchNoPumpIT] INT NULL CONSTRAINT [DF_BRN_DailyBankingABSA_ReconBatchNoPumpIT] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'Adjusted') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [Adjusted] BIT NULL CONSTRAINT [DF_BRN_DailyBankingABSA_Adjusted] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'AdjustedBy') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [AdjustedBy] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingABSA_AdjustedBy] DEFAULT ((0.00));
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingABSA_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingABSA_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingABSA', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingABSA ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingABSA_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingCashBags') IS NULL
CREATE TABLE dbo.BRN_DailyBankingCashBags (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [CashBagNo] NVARCHAR(20) NOT NULL,
    [CashBagAmount] MONEY NULL DEFAULT ((0)),
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [ReconBatchNo] INT NULL DEFAULT ((0)),
    [ReconBatchNoPumpIT] INT NULL DEFAULT ((0)),
    [Adjusted] BIT NOT NULL DEFAULT ((0)),
    [AdjustedBy] MONEY NULL DEFAULT ((0)),
    [DailyBankingCashBagID] INT IDENTITY(1,1) NOT NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingCashBags] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[CashBagNo])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingCashBags_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'CashBagNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [CashBagNo] NVARCHAR(20) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'CashBagAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [CashBagAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingCashBags_CashBagAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingCashBags_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'ReconBatchNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [ReconBatchNo] INT NULL CONSTRAINT [DF_BRN_DailyBankingCashBags_ReconBatchNo] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'ReconBatchNoPumpIT') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [ReconBatchNoPumpIT] INT NULL CONSTRAINT [DF_BRN_DailyBankingCashBags_ReconBatchNoPumpIT] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'Adjusted') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [Adjusted] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingCashBags_Adjusted] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'AdjustedBy') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [AdjustedBy] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingCashBags_AdjustedBy] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingCashBags_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingCashBags_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCashBags', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingCashBags ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingCashBags_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingCheques') IS NULL
CREATE TABLE dbo.BRN_DailyBankingCheques (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [ChequeNo] NVARCHAR(15) NOT NULL,
    [ChequeAmount] MONEY NULL DEFAULT ((0)),
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [Reference] NVARCHAR(50) NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingCheques] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[ChequeNo])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingCheques_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'ChequeNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [ChequeNo] NVARCHAR(15) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'ChequeAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [ChequeAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingCheques_ChequeAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingCheques_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'Reference') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [Reference] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingCheques_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingCheques_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCheques', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingCheques ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingCheques_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingCustomers') IS NULL
CREATE TABLE dbo.BRN_DailyBankingCustomers (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [CustomerCode] NVARCHAR(10) NOT NULL,
    [ItemNo] INT NOT NULL,
    [FuelAmount] MONEY NULL DEFAULT ((0)),
    [PaymentAmount] MONEY NULL DEFAULT ((0)),
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [Reference1] NVARCHAR(50) NOT NULL,
    [Reference2] NVARCHAR(50) NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingCustomers] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[CustomerCode],[ItemNo],[Reference1])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingCustomers_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'CustomerCode') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [CustomerCode] NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'ItemNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [ItemNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'FuelAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [FuelAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingCustomers_FuelAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'PaymentAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [PaymentAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingCustomers_PaymentAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingCustomers_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'Reference1') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [Reference1] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'Reference2') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [Reference2] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingCustomers_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingCustomers', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingCustomers ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingCustomers_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingDeposita') IS NULL
CREATE TABLE dbo.BRN_DailyBankingDeposita (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [TerminalNo] NVARCHAR(25) NOT NULL,
    [SlipNo] NVARCHAR(50) NOT NULL,
    [DepositaAmount] MONEY NULL DEFAULT ((0)),
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [DailyBankingDepositaID] INT IDENTITY(1,1) NOT NULL,
    [ReconBatchNo] INT NULL DEFAULT ((0)),
    [ReconBatchNoPumpIT] INT NULL DEFAULT ((0)),
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [Adjusted] BIT NOT NULL DEFAULT ((0)),
    [AdjustedBy] MONEY NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingDeposita] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[TerminalNo],[SlipNo])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingDeposita_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'TerminalNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [TerminalNo] NVARCHAR(25) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'SlipNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [SlipNo] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'DepositaAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [DepositaAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingDeposita_DepositaAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingDeposita_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'ReconBatchNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [ReconBatchNo] INT NULL CONSTRAINT [DF_BRN_DailyBankingDeposita_ReconBatchNo] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'ReconBatchNoPumpIT') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [ReconBatchNoPumpIT] INT NULL CONSTRAINT [DF_BRN_DailyBankingDeposita_ReconBatchNoPumpIT] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingDeposita_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingDeposita_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'Adjusted') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [Adjusted] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingDeposita_Adjusted] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'AdjustedBy') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [AdjustedBy] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDeposita', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingDeposita ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingDeposita_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingDirectDeposits') IS NULL
CREATE TABLE dbo.BRN_DailyBankingDirectDeposits (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [ItemNo] INT NOT NULL,
    [ItemDescription] NVARCHAR(50) NULL,
    [DDAmount] MONEY NULL DEFAULT ((0)),
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [ReconBatchNo] INT NULL,
    [ReconBatchNoPumpIT] INT NULL DEFAULT ((0)),
    [Adjusted] BIT NOT NULL DEFAULT ((0)),
    [AdjustedBy] MONEY NULL DEFAULT ((0)),
    [DailyBankingDirectDepositID] INT IDENTITY(1,1) NOT NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    [BankStatementLineid] INT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingDirectDeposits] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[ItemNo])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingDirectDeposits_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'ItemNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [ItemNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'ItemDescription') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [ItemDescription] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'DDAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [DDAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingDirectDeposits_DDAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingDirectDeposits_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'ReconBatchNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [ReconBatchNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'ReconBatchNoPumpIT') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [ReconBatchNoPumpIT] INT NULL CONSTRAINT [DF_BRN_DailyBankingDirectDeposits_ReconBatchNoPumpIT] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'Adjusted') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [Adjusted] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingDirectDeposits_Adjusted] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'AdjustedBy') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [AdjustedBy] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingDirectDeposits_AdjustedBy] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingDirectDeposits_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingDirectDeposits_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingDirectDeposits_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingDirectDeposits', 'BankStatementLineid') IS NULL ALTER TABLE dbo.BRN_DailyBankingDirectDeposits ADD [BankStatementLineid] INT NULL CONSTRAINT [DF_BRN_DailyBankingDirectDeposits_BankStatementLineid] DEFAULT ((0));
GO

IF OBJECT_ID('dbo.BRN_DailyBankingEmployees') IS NULL
CREATE TABLE dbo.BRN_DailyBankingEmployees (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [EmployeeCode] NVARCHAR(11) NOT NULL,
    [AmountShort] MONEY NULL DEFAULT ((0)),
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [Note] NVARCHAR(MAX) NULL,
    [Reason] NVARCHAR(MAX) NULL,
    [StaffShortsNo] INT NULL DEFAULT ((0)),
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingEmployees] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[EmployeeCode])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingEmployees_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'EmployeeCode') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [EmployeeCode] NVARCHAR(11) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'AmountShort') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [AmountShort] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingEmployees_AmountShort] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingEmployees_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'Note') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [Note] NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'Reason') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [Reason] NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'StaffShortsNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [StaffShortsNo] INT NULL CONSTRAINT [DF_BRN_DailyBankingEmployees_StaffShortsNo] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingEmployees_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingEmployees', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingEmployees ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingEmployees_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingExpenses') IS NULL
CREATE TABLE dbo.BRN_DailyBankingExpenses (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [TransactionNo] NVARCHAR(15) NOT NULL,
    [SupplierCode] NVARCHAR(8) NULL,
    [ExpenseDesc] NVARCHAR(50) NULL,
    [ExpenseAmount] MONEY NULL DEFAULT ((0)),
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingExpenses] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[TransactionNo])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingExpenses_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'TransactionNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [TransactionNo] NVARCHAR(15) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'SupplierCode') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [SupplierCode] NVARCHAR(8) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'ExpenseDesc') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [ExpenseDesc] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'ExpenseAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [ExpenseAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingExpenses_ExpenseAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingExpenses_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingExpenses_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingExpenses_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingExpenses', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingExpenses ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingExpenses_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingFleetCard') IS NULL
CREATE TABLE dbo.BRN_DailyBankingFleetCard (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [SeqNo] INT NOT NULL,
    [FleetCardNo] NVARCHAR(25) NOT NULL,
    [VehicleRegNo] NVARCHAR(25) NOT NULL,
    [FuelVolume] FLOAT NOT NULL DEFAULT ((0)),
    [DieselPrice] MONEY NULL DEFAULT ((0)),
    [DieselCost] MONEY NULL DEFAULT ((0)),
    [DieselMargin] MONEY NULL DEFAULT ((0)),
    [OilAmount] MONEY NULL DEFAULT ((0)),
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [FuelTypeNo] INT NULL DEFAULT ((0)),
    [FleetSelling] MONEY NULL DEFAULT ((0)),
    [FleetCost] MONEY NULL DEFAULT ((0)),
    [Rebate] MONEY NULL DEFAULT ((0)),
    [Payout] MONEY NULL DEFAULT ((0)),
    [FleetCardExpense] MONEY NULL DEFAULT ((0)),
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    [ReconBatchNoPumpIT] INT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingFleetCard] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[SeqNo])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'SeqNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [SeqNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'FleetCardNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [FleetCardNo] NVARCHAR(25) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'VehicleRegNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [VehicleRegNo] NVARCHAR(25) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'FuelVolume') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [FuelVolume] FLOAT NOT NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_FuelVolume] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'DieselPrice') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [DieselPrice] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_DieselPrice] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'DieselCost') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [DieselCost] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_DieselCost] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'DieselMargin') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [DieselMargin] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_DieselMargin] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'OilAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [OilAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_OilAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'FuelTypeNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [FuelTypeNo] INT NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_FuelTypeNo] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'FleetSelling') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [FleetSelling] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_FleetSelling] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'FleetCost') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [FleetCost] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_FleetCost] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'Rebate') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [Rebate] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_Rebate] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'Payout') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [Payout] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_Payout] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'FleetCardExpense') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [FleetCardExpense] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_FleetCardExpense] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingFleetCard', 'ReconBatchNoPumpIT') IS NULL ALTER TABLE dbo.BRN_DailyBankingFleetCard ADD [ReconBatchNoPumpIT] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingFleetCard_ReconBatchNoPumpIT] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingFNB') IS NULL
CREATE TABLE dbo.BRN_DailyBankingFNB (
    [SSBranchId] INT NOT NULL,
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [BatchNo] NVARCHAR(11) NOT NULL DEFAULT ((0)),
    [MerchantNo] NVARCHAR(6) NOT NULL,
    [Amount] MONEY NULL,
    [ReconBatchNo] INT NULL DEFAULT ((0)),
    [ReconBatchNoPumpIT] INT NULL DEFAULT ((0)),
    [Adjusted] BIT NULL DEFAULT ((0)),
    [AdjustedBy] MONEY NULL DEFAULT ((0)),
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingFNB] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[BatchNo],[MerchantNo])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'BatchNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [BatchNo] NVARCHAR(11) NOT NULL CONSTRAINT [DF_BRN_DailyBankingFNB_BatchNo] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'MerchantNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [MerchantNo] NVARCHAR(6) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'Amount') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [Amount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'ReconBatchNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [ReconBatchNo] INT NULL CONSTRAINT [DF_BRN_DailyBankingFNB_ReconBatchNo] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'ReconBatchNoPumpIT') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [ReconBatchNoPumpIT] INT NULL CONSTRAINT [DF_BRN_DailyBankingFNB_ReconBatchNoPumpIT] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'Adjusted') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [Adjusted] BIT NULL CONSTRAINT [DF_BRN_DailyBankingFNB_Adjusted] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'AdjustedBy') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [AdjustedBy] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingFNB_AdjustedBy] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingFNB_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingFNB_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingFNB', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingFNB ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingFNB_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingInfinity') IS NULL
CREATE TABLE dbo.BRN_DailyBankingInfinity (
    [SSBranchId] INT NOT NULL,
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [CardHolder] NVARCHAR(50) NOT NULL DEFAULT ((1)),
    [RewardReference] NVARCHAR(50) NOT NULL DEFAULT ((1)),
    [RedeemReference] NVARCHAR(50) NOT NULL,
    [RewardPurchaseValue] FLOAT NOT NULL DEFAULT ((0)),
    [TerminalReference] NVARCHAR(50) NOT NULL,
    [TerminalQuantity] FLOAT NOT NULL,
    [RedeemPointsValue] MONEY NULL,
    [ID] INT IDENTITY(1,1) NOT NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [TransactionDate_Infinity] DATETIME NULL,
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [ReconBatchNo] INT NOT NULL DEFAULT ((0)),
    [ReconBatchNopumpIT] INT NOT NULL DEFAULT ((0)),
    [Adjusted] BIT NOT NULL DEFAULT ((0)),
    [AdjustedBy] MONEY NOT NULL DEFAULT ((0)),
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingInfinity] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[CardHolder],[RewardReference],[RedeemReference],[RewardPurchaseValue],[TerminalReference],[TerminalQuantity])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'CardHolder') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [CardHolder] NVARCHAR(50) NOT NULL CONSTRAINT [DF_BRN_DailyBankingInfinity_CardHolder] DEFAULT ((1)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'RewardReference') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [RewardReference] NVARCHAR(50) NOT NULL CONSTRAINT [DF_BRN_DailyBankingInfinity_RewardReference] DEFAULT ((1)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'RedeemReference') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [RedeemReference] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'RewardPurchaseValue') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [RewardPurchaseValue] FLOAT NOT NULL CONSTRAINT [DF_BRN_DailyBankingInfinity_RewardPurchaseValue] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'TerminalReference') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [TerminalReference] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'TerminalQuantity') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [TerminalQuantity] FLOAT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'RedeemPointsValue') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [RedeemPointsValue] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingInfinity_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingInfinity_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'TransactionDate_Infinity') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [TransactionDate_Infinity] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingInfinity_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'ReconBatchNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [ReconBatchNo] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingInfinity_ReconBatchNo] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'ReconBatchNopumpIT') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [ReconBatchNopumpIT] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingInfinity_ReconBatchNopumpIT] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'Adjusted') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [Adjusted] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingInfinity_Adjusted] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'AdjustedBy') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [AdjustedBy] MONEY NOT NULL CONSTRAINT [DF_BRN_DailyBankingInfinity_AdjustedBy] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingInfinity', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingInfinity ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingInfinity_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingManualCard1') IS NULL
CREATE TABLE dbo.BRN_DailyBankingManualCard1 (
    [SSBranchId] INT NOT NULL,
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [BatchNo] NVARCHAR(11) NOT NULL DEFAULT ((0)),
    [Amount] MONEY NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingManualCard1] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[BatchNo])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'BatchNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [BatchNo] NVARCHAR(11) NOT NULL CONSTRAINT [DF_BRN_DailyBankingManualCard1_BatchNo] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'Amount') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [Amount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingManualCard1_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingManualCard1_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard1', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard1 ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingManualCard1_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingManualCard2') IS NULL
CREATE TABLE dbo.BRN_DailyBankingManualCard2 (
    [SSBranchId] INT NOT NULL,
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [BatchNo] NVARCHAR(11) NOT NULL DEFAULT ((0)),
    [Amount] MONEY NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingManualCard2] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[BatchNo])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'BatchNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [BatchNo] NVARCHAR(11) NOT NULL CONSTRAINT [DF_BRN_DailyBankingManualCard2_BatchNo] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'Amount') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [Amount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingManualCard2_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingManualCard2_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingManualCard2', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingManualCard2 ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingManualCard2_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingSmartATM') IS NULL
CREATE TABLE dbo.BRN_DailyBankingSmartATM (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TillNo] INT NOT NULL DEFAULT ((0)),
    [ShiftNo] INT NOT NULL DEFAULT ((0)),
    [DepositDateTime] DATETIME NOT NULL,
    [TerminalId] NVARCHAR(8) NOT NULL DEFAULT ((0)),
    [TraceNo] NVARCHAR(7) NOT NULL,
    [UniqueNo] FLOAT NOT NULL,
    [Deposited] MONEY NULL,
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [DailyBankingSmartATMID] INT IDENTITY(1,1) NOT NULL,
    [ReconBatchNo] INT NULL DEFAULT ((0)),
    [ReconBatchNoPumpIT] INT NULL DEFAULT ((0)),
    [CreateDateTime] DATETIME NULL,
    [isSDKLoaded] BIT NULL,
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [Adjusted] BIT NOT NULL DEFAULT ((0)),
    [AdjustedBy] MONEY NULL,
    [SettlementDate] NVARCHAR(15) NOT NULL DEFAULT (''),
    [SubTerminalId] NVARCHAR(9) NOT NULL DEFAULT (''),
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingSmartATM] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[DepositDateTime],[TerminalId],[TraceNo],[UniqueNo])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingSmartATM_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [TillNo] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingSmartATM_TillNo] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [ShiftNo] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingSmartATM_ShiftNo] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'DepositDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [DepositDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'TerminalId') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [TerminalId] NVARCHAR(8) NOT NULL CONSTRAINT [DF_BRN_DailyBankingSmartATM_TerminalId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'TraceNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [TraceNo] NVARCHAR(7) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'UniqueNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [UniqueNo] FLOAT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'Deposited') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [Deposited] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingSmartATM_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'ReconBatchNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [ReconBatchNo] INT NULL CONSTRAINT [DF_BRN_DailyBankingSmartATM_ReconBatchNo] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'ReconBatchNoPumpIT') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [ReconBatchNoPumpIT] INT NULL CONSTRAINT [DF_BRN_DailyBankingSmartATM_ReconBatchNoPumpIT] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [CreateDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [isSDKLoaded] BIT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'Adjusted') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [Adjusted] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingSmartATM_Adjusted] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'AdjustedBy') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [AdjustedBy] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'SettlementDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [SettlementDate] NVARCHAR(15) NOT NULL CONSTRAINT [DF_BRN_DailyBankingSmartATM_SettlementDate] DEFAULT ('') WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'SubTerminalId') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [SubTerminalId] NVARCHAR(9) NOT NULL CONSTRAINT [DF_BRN_DailyBankingSmartATM_SubTerminalId] DEFAULT ('') WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingSmartATM', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingSmartATM ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingSmartATM_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingSuppliers') IS NULL
CREATE TABLE dbo.BRN_DailyBankingSuppliers (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [SupplierCode] NVARCHAR(8) NOT NULL,
    [DocumentNo] NVARCHAR(20) NOT NULL,
    [SupplierAmount] MONEY NULL DEFAULT ((0)),
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [Reference] NVARCHAR(50) NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingSuppliers] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[SupplierCode],[DocumentNo])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingSuppliers_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'SupplierCode') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [SupplierCode] NVARCHAR(8) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'DocumentNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [DocumentNo] NVARCHAR(20) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'SupplierAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [SupplierAmount] MONEY NULL CONSTRAINT [DF_BRN_DailyBankingSuppliers_SupplierAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingSuppliers_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'Reference') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [Reference] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingSuppliers_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingSuppliers_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingSuppliers', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingSuppliers ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingSuppliers_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingYumbi') IS NULL
CREATE TABLE dbo.BRN_DailyBankingYumbi (
    [SSBranchId] INT NOT NULL,
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [ID] NVARCHAR(10) NOT NULL,
    [NetAmount] MONEY NULL,
    [DiscountAmount] MONEY NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [ReconBatchNo] INT NOT NULL DEFAULT ((0)),
    [ReconBatchNopumpIT] INT NOT NULL DEFAULT ((0)),
    [Adjusted] BIT NOT NULL DEFAULT ((0)),
    [AdjustedBy] MONEY NOT NULL DEFAULT ((0)),
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingYumbi] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[ID])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'ID') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [ID] NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'NetAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [NetAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'DiscountAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [DiscountAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingYumbi_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingYumbi_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbi_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'ReconBatchNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [ReconBatchNo] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbi_ReconBatchNo] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'ReconBatchNopumpIT') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [ReconBatchNopumpIT] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbi_ReconBatchNopumpIT] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'Adjusted') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [Adjusted] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbi_Adjusted] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'AdjustedBy') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [AdjustedBy] MONEY NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbi_AdjustedBy] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbi', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbi ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbi_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingYumbiVoucher') IS NULL
CREATE TABLE dbo.BRN_DailyBankingYumbiVoucher (
    [SSBranchId] INT NOT NULL,
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [RedemptionDate] DATETIME NOT NULL,
    [RedeemedAmount] MONEY NULL,
    [CustomerName] NVARCHAR(50) NULL,
    [CustomerReference] NVARCHAR(50) NOT NULL,
    [VoucherType] NVARCHAR(50) NULL,
    [CreateDateTime] DATETIME NOT NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NOT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [ReconBatchNo] INT NOT NULL DEFAULT ((0)),
    [ReconBatchNoPumpIT] INT NOT NULL DEFAULT ((0)),
    [Adjusted] BIT NOT NULL DEFAULT ((0)),
    [AdjustedBy] MONEY NOT NULL DEFAULT ((0)),
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingYumbiVoucher] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[RedemptionDate],[CustomerReference])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'RedemptionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [RedemptionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'RedeemedAmount') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [RedeemedAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'CustomerName') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [CustomerName] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'CustomerReference') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [CustomerReference] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'VoucherType') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [VoucherType] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [CreateDateTime] DATETIME NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbiVoucher_CreateDateTime] DEFAULT (getdate()) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [isSDKLoaded] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbiVoucher_isSDKLoaded] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'Imported') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbiVoucher_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'ReconBatchNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [ReconBatchNo] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbiVoucher_ReconBatchNo] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'ReconBatchNoPumpIT') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [ReconBatchNoPumpIT] INT NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbiVoucher_ReconBatchNoPumpIT] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'Adjusted') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [Adjusted] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbiVoucher_Adjusted] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'AdjustedBy') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [AdjustedBy] MONEY NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbiVoucher_AdjustedBy] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DailyBankingYumbiVoucher', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingYumbiVoucher ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingYumbiVoucher_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_DailyBankingZapper') IS NULL
CREATE TABLE dbo.BRN_DailyBankingZapper (
    [SSBranchId] INT NOT NULL,
    [TillNo] INT NOT NULL,
    [ShiftNo] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [UniqueField] NVARCHAR(150) NOT NULL,
    [Reference1] NVARCHAR(100) NOT NULL,
    [Status] NVARCHAR(100) NOT NULL,
    [NettTurnover] MONEY NULL,
    [FeeTotal] MONEY NULL,
    [ProcessingFeeVAT] MONEY NULL,
    [VoucherFee] MONEY NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [isOMNIPrep] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_DailyBankingZapper] PRIMARY KEY CLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[UniqueField])
);
GO

IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'TillNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [TillNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'UniqueField') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [UniqueField] NVARCHAR(150) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'Reference1') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [Reference1] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'Status') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [Status] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'NettTurnover') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [NettTurnover] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'FeeTotal') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [FeeTotal] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'ProcessingFeeVAT') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [ProcessingFeeVAT] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'VoucherFee') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [VoucherFee] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_DailyBankingZapper_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_DailyBankingZapper_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DailyBankingZapper', 'isOMNIPrep') IS NULL ALTER TABLE dbo.BRN_DailyBankingZapper ADD [isOMNIPrep] BIT NOT NULL CONSTRAINT [DF_BRN_DailyBankingZapper_isOMNIPrep] DEFAULT ((0)) WITH VALUES;
GO

-- ----------------------------------------------------------------------------
-- 3. What the cashup reads besides its legs.
--
--   BRN_Customers              the account customers a Debtors leg names
--                              (the legacy total counts a line only when its
--                              customer is on this list)
--   DBF_EFTBAT_NEWCREDITCARD   the card terminal's batch file, which
--                              agora.usp_Cash_LoadCardAmounts sums into FNB
--                              lines exactly as dbo.sp_Load_BranchCreditCardAmount
--                              does. The customer's has 113 columns; these are
--                              the ones the load reads, plus its primary key.
-- ----------------------------------------------------------------------------

IF OBJECT_ID('dbo.BRN_Customers') IS NULL
CREATE TABLE dbo.BRN_Customers (
    [SSBranchId]   INT            NOT NULL,
    [CustomerCode] NVARCHAR(10)   NOT NULL,
    [CustomerName] NVARCHAR(100)  NULL,
    CONSTRAINT [PK_BRN_Customers] PRIMARY KEY CLUSTERED ([SSBranchId], [CustomerCode])
);
GO

IF OBJECT_ID('dbo.DBF_EFTBAT_NEWCREDITCARD') IS NULL
CREATE TABLE dbo.DBF_EFTBAT_NEWCREDITCARD (
    [SSBranchId] INT          NOT NULL,
    [TRCOD]      CHAR(4)      NOT NULL,
    [EOD_CNTR]   CHAR(8)      NOT NULL,
    [CARD]       CHAR(20)     NOT NULL,
    [AMOUNT]     DECIMAL(18,6) NULL,
    [POSREF]     CHAR(8)      NOT NULL,
    [TRANDATE]   DATETIME     NOT NULL,
    [TRANTIME]   CHAR(8)      NOT NULL,
    [MERCHID]    CHAR(30)     NULL,
    [USERID]     CHAR(6)      NULL,
    [TERMNUM]    CHAR(3)      NULL,
    [LOGFILE]    CHAR(10)     NULL,
    [BATCHNO]    CHAR(10)     NULL
);
GO

-- ----------------------------------------------------------------------------
-- 4. The pools a cashup's legs are chosen from (T037, Ryan's Q1 of 27 Sep 2026).
--
-- In the legacy, a leg is not typed: the cashier picks rows from a pool, the
-- row is copied onto the cashup, and the pool row is marked as banked so it
-- cannot be banked twice (dbo.sp_IsSelectedForDailyBanking_DailyBanking_Update
-- is the rule, pool by pool). agora.usp_Cash_SaveCashupLeg does both in one
-- transaction; these are the pools it reads and marks, in the customer's
-- shapes (27 Sep 2026), created where the container has none and widened
-- where another stub made a narrower one.
--
--   BRN_DropSafe            cash bags          IsSelectedForDailyBanking
--   BRN_CashDevices         cash devices       IsSelectedForDailyBanking
--   BRN_SmartATM            Smart ATM          IsSelectedForDailyBanking
--   BRN_ABSAMarkoff         ABSA card          IsSelectedForDailyBanking
--   BRN_Yumbi, BRN_YumbiVoucher, BRN_Infinity, BRN_Zapper   the same
--   DBF_INVHISTH_CUSTOMER   POS account sales  IsSelectedForBanking
--   DBF_POSTR_SUPPLIER      POS paid-outs      IsSelectedForBanking
--   BRN_Transaction         cash purchase requests   Posted, ShiftNo
--   BRN_TransactionLine     their amounts (read only)
--   BRN_Customers.DebtorID, BRN_Suppliers.SupplierID   the POS account number
--                           each master row answers to
-- ----------------------------------------------------------------------------

IF OBJECT_ID('dbo.BRN_ABSAMarkoff') IS NULL
CREATE TABLE dbo.BRN_ABSAMarkoff (
    [SSBranchId] INT NOT NULL,
    [LineNumber] INT NULL,
    [Hdr1_FileType] NVARCHAR(1) NULL,
    [Hdr2_UniqueFileName] NVARCHAR(20) NULL,
    [Hdr3_Date] DATETIME NULL,
    [Hdr4_VersionNr] NVARCHAR(3) NULL,
    [Dtl1_FileType] NVARCHAR(3) NULL,
    [Dtl2_TerminalId] NVARCHAR(8) NULL,
    [Dtl3_MerchantNumber] NVARCHAR(12) NULL,
    [Dtl4_TransactionAccountNumber] NVARCHAR(23) NULL,
    [Dtl5_TransactionDate] DATETIME NOT NULL,
    [Dtl6_ReceivedDate] DATETIME NULL,
    [Dtl7_SettlementDate] DATETIME NULL,
    [Dtl8_TransactionTime] DATETIME NOT NULL,
    [Dtl9_TransactionAmount] MONEY NOT NULL,
    [Dtl10_CashBack] MONEY NULL,
    [Dtl11_TransactionType] NVARCHAR(3) NULL,
    [Dtl12_TransactionAuthCode] NVARCHAR(10) NULL,
    [Dtl13_TransactionAuthIndicator] NVARCHAR(1) NULL,
    [Dtl14_CardType] NVARCHAR(2) NULL,
    [Dtl15_POSEntryMode] NVARCHAR(2) NULL,
    [Dtl16_TransactionOverrideIndicator] NVARCHAR(1) NULL,
    [Dtl17_TransactionSeqNumber] NVARCHAR(3) NULL,
    [Dtl18_SuspenseIndicator] NVARCHAR(1) NULL,
    [Dtl19_BudgetIndicator] NVARCHAR(1) NULL,
    [Dtl20_BudgetMonths] INT NULL,
    [Dtl21CashierNumber] NVARCHAR(6) NULL,
    [Dtl22BatchNumber] INT NULL,
    [Dtl23_RetrievalRefNumber] NVARCHAR(12) NOT NULL,
    [Dtl24_Association] NVARCHAR(3) NULL,
    [Dtl25_Card_Issuer] NVARCHAR(3) NULL,
    [Dtl26_VVSCashIndicator] NVARCHAR(1) NULL,
    [Dtl27_VVSItemCode] NVARCHAR(2) NULL,
    [Dtl28_VVSFaceValue] NVARCHAR(9) NULL,
    [Dtl29_InvoiceNumber] NVARCHAR(10) NULL,
    [Dtl311_FleetBussiness_PersonalIndicator] NVARCHAR(2) NULL,
    [Dtl312_FleetSasol] NVARCHAR(1) NULL,
    [Dtl313_FleetInhouse] NVARCHAR(1) NULL,
    [Dtl314_FleetLitres] MONEY NULL,
    [Dtl315_FleetOdometer] INT NULL,
    [Dtl316_FleetCode1] NVARCHAR(2) NULL,
    [Dtl317_FleetAmount1] MONEY NULL,
    [Dtl318_FleetQty1] MONEY NULL,
    [Dtl319_FleetCode2] NVARCHAR(2) NULL,
    [Dtl3110_FleetAmount2] MONEY NULL,
    [Dtl3111_FleetQty2] MONEY NULL,
    [Dtl3112_FleetCode3] NVARCHAR(2) NULL,
    [Dtl3113_FleetAmount3] MONEY NULL,
    [Dtl3114_FleetQty3] MONEY NULL,
    [Dtl3115_FleetCode4] NVARCHAR(2) NULL,
    [Dtl3116_FleetAmount4] MONEY NULL,
    [Dtl3117_FleetQty4] MONEY NULL,
    [Dtl3118_FleetCode5] NVARCHAR(2) NULL,
    [Dtl3119_FleetAmount5] MONEY NULL,
    [Dtl3120_FleetQty5] MONEY NULL,
    [Dtl3121_FleetCode6] NVARCHAR(2) NULL,
    [Dtl3122_FleetAmount6] MONEY NULL,
    [Dtl3123_FleetQty6] MONEY NULL,
    [Dtl3124_SourceIndicator] INT NULL,
    [Dtl321_AOPDescription] NVARCHAR(25) NULL,
    [Dtl322_Filler] NVARCHAR(115) NULL,
    [Trl1_FileType] NVARCHAR(1) NULL,
    [Trl2_Date] DATETIME NULL,
    [Trl3_TotalRecords] INT NULL,
    [Trl4_HashTotalForFile1] MONEY NULL,
    [Trl5_SuspenseRecordCount] INT NULL,
    [Trl6_HashTotalForSuspenseTransaction] MONEY NULL,
    [IsSelectedForDailyBanking] BIT NOT NULL DEFAULT ((0)),
    [MIST_EOD] VARCHAR(10) NULL,
    CONSTRAINT [PK_BRN_ABSAMarkoff] PRIMARY KEY CLUSTERED ([SSBranchId],[Dtl5_TransactionDate],[Dtl8_TransactionTime],[Dtl9_TransactionAmount],[Dtl23_RetrievalRefNumber])
);
GO

IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'LineNumber') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [LineNumber] INT NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Hdr1_FileType') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Hdr1_FileType] NVARCHAR(1) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Hdr2_UniqueFileName') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Hdr2_UniqueFileName] NVARCHAR(20) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Hdr3_Date') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Hdr3_Date] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Hdr4_VersionNr') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Hdr4_VersionNr] NVARCHAR(3) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl1_FileType') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl1_FileType] NVARCHAR(3) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl2_TerminalId') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl2_TerminalId] NVARCHAR(8) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3_MerchantNumber') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3_MerchantNumber] NVARCHAR(12) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl4_TransactionAccountNumber') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl4_TransactionAccountNumber] NVARCHAR(23) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl5_TransactionDate') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl5_TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl6_ReceivedDate') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl6_ReceivedDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl7_SettlementDate') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl7_SettlementDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl8_TransactionTime') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl8_TransactionTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl9_TransactionAmount') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl9_TransactionAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl10_CashBack') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl10_CashBack] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl11_TransactionType') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl11_TransactionType] NVARCHAR(3) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl12_TransactionAuthCode') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl12_TransactionAuthCode] NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl13_TransactionAuthIndicator') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl13_TransactionAuthIndicator] NVARCHAR(1) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl14_CardType') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl14_CardType] NVARCHAR(2) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl15_POSEntryMode') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl15_POSEntryMode] NVARCHAR(2) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl16_TransactionOverrideIndicator') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl16_TransactionOverrideIndicator] NVARCHAR(1) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl17_TransactionSeqNumber') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl17_TransactionSeqNumber] NVARCHAR(3) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl18_SuspenseIndicator') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl18_SuspenseIndicator] NVARCHAR(1) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl19_BudgetIndicator') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl19_BudgetIndicator] NVARCHAR(1) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl20_BudgetMonths') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl20_BudgetMonths] INT NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl21CashierNumber') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl21CashierNumber] NVARCHAR(6) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl22BatchNumber') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl22BatchNumber] INT NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl23_RetrievalRefNumber') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl23_RetrievalRefNumber] NVARCHAR(12) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl24_Association') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl24_Association] NVARCHAR(3) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl25_Card_Issuer') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl25_Card_Issuer] NVARCHAR(3) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl26_VVSCashIndicator') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl26_VVSCashIndicator] NVARCHAR(1) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl27_VVSItemCode') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl27_VVSItemCode] NVARCHAR(2) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl28_VVSFaceValue') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl28_VVSFaceValue] NVARCHAR(9) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl29_InvoiceNumber') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl29_InvoiceNumber] NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl311_FleetBussiness_PersonalIndicator') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl311_FleetBussiness_PersonalIndicator] NVARCHAR(2) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl312_FleetSasol') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl312_FleetSasol] NVARCHAR(1) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl313_FleetInhouse') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl313_FleetInhouse] NVARCHAR(1) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl314_FleetLitres') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl314_FleetLitres] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl315_FleetOdometer') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl315_FleetOdometer] INT NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl316_FleetCode1') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl316_FleetCode1] NVARCHAR(2) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl317_FleetAmount1') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl317_FleetAmount1] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl318_FleetQty1') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl318_FleetQty1] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl319_FleetCode2') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl319_FleetCode2] NVARCHAR(2) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3110_FleetAmount2') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3110_FleetAmount2] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3111_FleetQty2') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3111_FleetQty2] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3112_FleetCode3') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3112_FleetCode3] NVARCHAR(2) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3113_FleetAmount3') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3113_FleetAmount3] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3114_FleetQty3') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3114_FleetQty3] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3115_FleetCode4') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3115_FleetCode4] NVARCHAR(2) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3116_FleetAmount4') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3116_FleetAmount4] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3117_FleetQty4') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3117_FleetQty4] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3118_FleetCode5') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3118_FleetCode5] NVARCHAR(2) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3119_FleetAmount5') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3119_FleetAmount5] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3120_FleetQty5') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3120_FleetQty5] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3121_FleetCode6') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3121_FleetCode6] NVARCHAR(2) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3122_FleetAmount6') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3122_FleetAmount6] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3123_FleetQty6') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3123_FleetQty6] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl3124_SourceIndicator') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl3124_SourceIndicator] INT NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl321_AOPDescription') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl321_AOPDescription] NVARCHAR(25) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Dtl322_Filler') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Dtl322_Filler] NVARCHAR(115) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Trl1_FileType') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Trl1_FileType] NVARCHAR(1) NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Trl2_Date') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Trl2_Date] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Trl3_TotalRecords') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Trl3_TotalRecords] INT NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Trl4_HashTotalForFile1') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Trl4_HashTotalForFile1] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Trl5_SuspenseRecordCount') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Trl5_SuspenseRecordCount] INT NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'Trl6_HashTotalForSuspenseTransaction') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [Trl6_HashTotalForSuspenseTransaction] MONEY NULL;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'IsSelectedForDailyBanking') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [IsSelectedForDailyBanking] BIT NOT NULL CONSTRAINT [DF_BRN_ABSAMarkoff_IsSelectedForDailyBanking] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_ABSAMarkoff', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_ABSAMarkoff ADD [MIST_EOD] VARCHAR(10) NULL;
GO

IF OBJECT_ID('dbo.BRN_CashDevices') IS NULL
CREATE TABLE dbo.BRN_CashDevices (
    [SSBranchId] INT NOT NULL,
    [TransactionDateTime] DATETIME NOT NULL,
    [SafeId] NVARCHAR(10) NOT NULL,
    [TransactionNo] NVARCHAR(15) NOT NULL,
    [TransactionValue] MONEY NOT NULL,
    [User] NVARCHAR(15) NOT NULL,
    [TransactionType] NVARCHAR(20) NOT NULL,
    [AccountNo] NVARCHAR(10) NOT NULL,
    [IsSelectedForDailyBanking] BIT NOT NULL DEFAULT ((0)),
    [CreateDateTime] DATETIME NOT NULL DEFAULT (getdate()),
    [MIST_EOD] VARCHAR(10) NULL,
    [Unique_Transaction_ID] NVARCHAR(12) NULL,
    [SAP_BPCode] NVARCHAR(10) NULL,
    [Transaction_Type_ID] INT NULL,
    [Short_Bag_Nr] INT NULL,
    [Transaction_DateTime] DATETIME NULL,
    [User_ID] NVARCHAR(10) NULL,
    [Bag_ID] INT NULL,
    [Customer_Ref] NVARCHAR(8) NULL,
    CONSTRAINT [PK_BRN_CashDevices] PRIMARY KEY CLUSTERED ([SSBranchId],[TransactionDateTime],[SafeId],[TransactionNo])
);
GO

IF COL_LENGTH('dbo.BRN_CashDevices', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'TransactionDateTime') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [TransactionDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'SafeId') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [SafeId] NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'TransactionNo') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [TransactionNo] NVARCHAR(15) NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'TransactionValue') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [TransactionValue] MONEY NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'User') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [User] NVARCHAR(15) NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'TransactionType') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [TransactionType] NVARCHAR(20) NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'AccountNo') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [AccountNo] NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'IsSelectedForDailyBanking') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [IsSelectedForDailyBanking] BIT NOT NULL CONSTRAINT [DF_BRN_CashDevices_IsSelectedForDailyBanking] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_CashDevices', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [CreateDateTime] DATETIME NOT NULL CONSTRAINT [DF_BRN_CashDevices_CreateDateTime] DEFAULT (getdate()) WITH VALUES;
IF COL_LENGTH('dbo.BRN_CashDevices', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'Unique_Transaction_ID') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [Unique_Transaction_ID] NVARCHAR(12) NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'SAP_BPCode') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [SAP_BPCode] NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'Transaction_Type_ID') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [Transaction_Type_ID] INT NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'Short_Bag_Nr') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [Short_Bag_Nr] INT NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'Transaction_DateTime') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [Transaction_DateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'User_ID') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [User_ID] NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'Bag_ID') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [Bag_ID] INT NULL;
IF COL_LENGTH('dbo.BRN_CashDevices', 'Customer_Ref') IS NULL ALTER TABLE dbo.BRN_CashDevices ADD [Customer_Ref] NVARCHAR(8) NULL;
GO

IF OBJECT_ID('dbo.BRN_Customers') IS NULL
CREATE TABLE dbo.BRN_Customers (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [CustomerCode] NVARCHAR(10) NOT NULL,
    [CustomerName] NVARCHAR(100) NULL,
    [CustomerContact] NVARCHAR(35) NULL,
    [Department] NVARCHAR(30) NULL,
    [JobTitle] NVARCHAR(50) NULL,
    [PostalAddress] NVARCHAR(MAX) NULL,
    [PhysicalAddress] NVARCHAR(MAX) NULL,
    [VATNo] NVARCHAR(25) NULL,
    [TelNoA] NVARCHAR(25) NULL,
    [TelNoB] NVARCHAR(25) NULL,
    [TelNoH] NVARCHAR(25) NULL,
    [FaxNo] NVARCHAR(25) NULL,
    [CellNo] NVARCHAR(50) NULL,
    [EmailAddress] NVARCHAR(50) NULL,
    [IAddress] NVARCHAR(50) NULL DEFAULT ('http://www.'),
    [Comments] NVARCHAR(MAX) NULL,
    [CreditLimitAmount] MONEY NULL DEFAULT ((0)),
    [DebtorID] NVARCHAR(50) NOT NULL,
    [DebtorGroupID] NVARCHAR(50) NOT NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [isSDKLoaded] BIT NOT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDate] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [PosSystemId] INT NULL,
    [OpeningPumpITBalance] MONEY NOT NULL DEFAULT ((0)),
    [IsMOP] BIT NOT NULL DEFAULT ((0)),
    [MOPTypeId] INT NOT NULL DEFAULT ((0)),
    [OMNIGLCode] VARCHAR(20) NULL,
    CONSTRAINT [PK_BRN_Customers] PRIMARY KEY CLUSTERED ([SSBranchId],[CustomerCode])
);
GO

IF COL_LENGTH('dbo.BRN_Customers', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_Customers ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_Customers_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Customers', 'CustomerCode') IS NULL ALTER TABLE dbo.BRN_Customers ADD [CustomerCode] NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'CustomerName') IS NULL ALTER TABLE dbo.BRN_Customers ADD [CustomerName] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'CustomerContact') IS NULL ALTER TABLE dbo.BRN_Customers ADD [CustomerContact] NVARCHAR(35) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'Department') IS NULL ALTER TABLE dbo.BRN_Customers ADD [Department] NVARCHAR(30) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'JobTitle') IS NULL ALTER TABLE dbo.BRN_Customers ADD [JobTitle] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'PostalAddress') IS NULL ALTER TABLE dbo.BRN_Customers ADD [PostalAddress] NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'PhysicalAddress') IS NULL ALTER TABLE dbo.BRN_Customers ADD [PhysicalAddress] NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'VATNo') IS NULL ALTER TABLE dbo.BRN_Customers ADD [VATNo] NVARCHAR(25) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'TelNoA') IS NULL ALTER TABLE dbo.BRN_Customers ADD [TelNoA] NVARCHAR(25) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'TelNoB') IS NULL ALTER TABLE dbo.BRN_Customers ADD [TelNoB] NVARCHAR(25) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'TelNoH') IS NULL ALTER TABLE dbo.BRN_Customers ADD [TelNoH] NVARCHAR(25) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'FaxNo') IS NULL ALTER TABLE dbo.BRN_Customers ADD [FaxNo] NVARCHAR(25) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'CellNo') IS NULL ALTER TABLE dbo.BRN_Customers ADD [CellNo] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'EmailAddress') IS NULL ALTER TABLE dbo.BRN_Customers ADD [EmailAddress] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'IAddress') IS NULL ALTER TABLE dbo.BRN_Customers ADD [IAddress] NVARCHAR(50) NULL CONSTRAINT [DF_BRN_Customers_IAddress] DEFAULT ('http://www.');
IF COL_LENGTH('dbo.BRN_Customers', 'Comments') IS NULL ALTER TABLE dbo.BRN_Customers ADD [Comments] NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'CreditLimitAmount') IS NULL ALTER TABLE dbo.BRN_Customers ADD [CreditLimitAmount] MONEY NULL CONSTRAINT [DF_BRN_Customers_CreditLimitAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_Customers', 'DebtorID') IS NULL ALTER TABLE dbo.BRN_Customers ADD [DebtorID] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'DebtorGroupID') IS NULL ALTER TABLE dbo.BRN_Customers ADD [DebtorGroupID] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_Customers ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_Customers_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_Customers', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_Customers ADD [isSDKLoaded] BIT NOT NULL CONSTRAINT [DF_BRN_Customers_isSDKLoaded] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Customers', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_Customers ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'SDKLoadDate') IS NULL ALTER TABLE dbo.BRN_Customers ADD [SDKLoadDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_Customers ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_Customers ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_Customers ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'PosSystemId') IS NULL ALTER TABLE dbo.BRN_Customers ADD [PosSystemId] INT NULL;
IF COL_LENGTH('dbo.BRN_Customers', 'OpeningPumpITBalance') IS NULL ALTER TABLE dbo.BRN_Customers ADD [OpeningPumpITBalance] MONEY NOT NULL CONSTRAINT [DF_BRN_Customers_OpeningPumpITBalance] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Customers', 'IsMOP') IS NULL ALTER TABLE dbo.BRN_Customers ADD [IsMOP] BIT NOT NULL CONSTRAINT [DF_BRN_Customers_IsMOP] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Customers', 'MOPTypeId') IS NULL ALTER TABLE dbo.BRN_Customers ADD [MOPTypeId] INT NOT NULL CONSTRAINT [DF_BRN_Customers_MOPTypeId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Customers', 'OMNIGLCode') IS NULL ALTER TABLE dbo.BRN_Customers ADD [OMNIGLCode] VARCHAR(20) NULL;
GO

IF OBJECT_ID('dbo.BRN_DropSafe') IS NULL
CREATE TABLE dbo.BRN_DropSafe (
    [SSBranchId] INT NOT NULL,
    [BagNo] NVARCHAR(20) NOT NULL,
    [DropDate] DATETIME NOT NULL,
    [DropTime] NVARCHAR(8) NOT NULL,
    [Amount] MONEY NOT NULL,
    [ReasonForManualBagId] INT NOT NULL DEFAULT ((0)),
    [ReasonIfAmountMoreThanR2000] NVARCHAR(50) NULL,
    [RefNoFaultReported] NVARCHAR(20) NULL,
    [CashierCode] NVARCHAR(11) NOT NULL,
    [ManagerCode] NVARCHAR(11) NOT NULL,
    [IsSelectedForDailyBanking] BIT NOT NULL DEFAULT ((0)),
    [CollectionId] BIGINT NOT NULL DEFAULT ((0)),
    [CreateDateTime] DATETIME NOT NULL DEFAULT (getdate()),
    [MIST_EOD] VARCHAR(10) NULL,
    CONSTRAINT [PK_BRN_DropSafe] PRIMARY KEY CLUSTERED ([SSBranchId],[BagNo])
);
GO

IF COL_LENGTH('dbo.BRN_DropSafe', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'BagNo') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [BagNo] NVARCHAR(20) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'DropDate') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [DropDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'DropTime') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [DropTime] NVARCHAR(8) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'Amount') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [Amount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'ReasonForManualBagId') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [ReasonForManualBagId] INT NOT NULL CONSTRAINT [DF_BRN_DropSafe_ReasonForManualBagId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DropSafe', 'ReasonIfAmountMoreThanR2000') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [ReasonIfAmountMoreThanR2000] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'RefNoFaultReported') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [RefNoFaultReported] NVARCHAR(20) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'CashierCode') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [CashierCode] NVARCHAR(11) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'ManagerCode') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [ManagerCode] NVARCHAR(11) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'IsSelectedForDailyBanking') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [IsSelectedForDailyBanking] BIT NOT NULL CONSTRAINT [DF_BRN_DropSafe_IsSelectedForDailyBanking] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DropSafe', 'CollectionId') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [CollectionId] BIGINT NOT NULL CONSTRAINT [DF_BRN_DropSafe_CollectionId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DropSafe', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [CreateDateTime] DATETIME NOT NULL CONSTRAINT [DF_BRN_DropSafe_CreateDateTime] DEFAULT (getdate()) WITH VALUES;
IF COL_LENGTH('dbo.BRN_DropSafe', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD [MIST_EOD] VARCHAR(10) NULL;
GO

IF OBJECT_ID('dbo.BRN_Infinity') IS NULL
CREATE TABLE dbo.BRN_Infinity (
    [SSBranchId] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [Cardholder] NVARCHAR(50) NOT NULL,
    [FirstName] NVARCHAR(50) NULL,
    [Surname] NVARCHAR(50) NULL,
    [TransactionType] NVARCHAR(50) NULL,
    [RewardPointsValue] FLOAT NULL,
    [RewardPurchaseValue] FLOAT NOT NULL,
    [RewardReference] NVARCHAR(50) NOT NULL,
    [RedeemPointsValue] FLOAT NULL,
    [RedeemReference] NVARCHAR(50) NOT NULL,
    [PerformedBy1] NVARCHAR(50) NULL,
    [PerformedBy2] NVARCHAR(50) NULL,
    [Terminal] NVARCHAR(50) NULL,
    [IsSelectedForDailyBanking] BIT NULL DEFAULT ((0)),
    [TerminalReference] NVARCHAR(50) NOT NULL,
    [TerminalQuantity] FLOAT NOT NULL DEFAULT ((0)),
    [MIST_EOD] VARCHAR(10) NULL,
    [MyCheck] NVARCHAR(5) NULL,
    CONSTRAINT [PK_BRN_Infinity] PRIMARY KEY CLUSTERED ([SSBranchId],[TransactionDate],[Cardholder],[RewardPurchaseValue],[RewardReference],[RedeemReference],[TerminalReference],[TerminalQuantity])
);
GO

IF COL_LENGTH('dbo.BRN_Infinity', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'Cardholder') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [Cardholder] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'FirstName') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [FirstName] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'Surname') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [Surname] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'TransactionType') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [TransactionType] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'RewardPointsValue') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [RewardPointsValue] FLOAT NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'RewardPurchaseValue') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [RewardPurchaseValue] FLOAT NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'RewardReference') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [RewardReference] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'RedeemPointsValue') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [RedeemPointsValue] FLOAT NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'RedeemReference') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [RedeemReference] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'PerformedBy1') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [PerformedBy1] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'PerformedBy2') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [PerformedBy2] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'Terminal') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [Terminal] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'IsSelectedForDailyBanking') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [IsSelectedForDailyBanking] BIT NULL CONSTRAINT [DF_BRN_Infinity_IsSelectedForDailyBanking] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_Infinity', 'TerminalReference') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [TerminalReference] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'TerminalQuantity') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [TerminalQuantity] FLOAT NOT NULL CONSTRAINT [DF_BRN_Infinity_TerminalQuantity] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Infinity', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_Infinity', 'MyCheck') IS NULL ALTER TABLE dbo.BRN_Infinity ADD [MyCheck] NVARCHAR(5) NULL;
GO

IF OBJECT_ID('dbo.BRN_SmartATM') IS NULL
CREATE TABLE dbo.BRN_SmartATM (
    [SSBranchId] INT NOT NULL,
    [DepositDateTime] DATETIME NOT NULL,
    [TerminalId] NVARCHAR(8) NOT NULL,
    [DepositorName] NVARCHAR(50) NOT NULL,
    [TraceNo] NVARCHAR(7) NOT NULL,
    [Deposited] MONEY NOT NULL,
    [Settled] MONEY NOT NULL,
    [IsSelectedForDailyBanking] BIT NOT NULL DEFAULT ((0)),
    [UniqueNo] FLOAT NULL,
    [CreateDateTime] DATETIME NOT NULL DEFAULT (getdate()),
    [MIST_EOD] VARCHAR(10) NULL,
    [SmartTypeId] INT NULL DEFAULT ((0)),
    [SettlementDate] NVARCHAR(15) NOT NULL DEFAULT (''),
    [SubTerminalId] NVARCHAR(9) NOT NULL DEFAULT (''),
    [SiteName] NVARCHAR(50) NOT NULL DEFAULT (''),
    CONSTRAINT [PK_BRN_SmartATM] PRIMARY KEY CLUSTERED ([SSBranchId],[DepositDateTime],[TraceNo],[TerminalId])
);
GO

IF COL_LENGTH('dbo.BRN_SmartATM', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_SmartATM', 'DepositDateTime') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [DepositDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_SmartATM', 'TerminalId') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [TerminalId] NVARCHAR(8) NULL;
IF COL_LENGTH('dbo.BRN_SmartATM', 'DepositorName') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [DepositorName] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_SmartATM', 'TraceNo') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [TraceNo] NVARCHAR(7) NULL;
IF COL_LENGTH('dbo.BRN_SmartATM', 'Deposited') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [Deposited] MONEY NULL;
IF COL_LENGTH('dbo.BRN_SmartATM', 'Settled') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [Settled] MONEY NULL;
IF COL_LENGTH('dbo.BRN_SmartATM', 'IsSelectedForDailyBanking') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [IsSelectedForDailyBanking] BIT NOT NULL CONSTRAINT [DF_BRN_SmartATM_IsSelectedForDailyBanking] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_SmartATM', 'UniqueNo') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [UniqueNo] FLOAT NULL;
IF COL_LENGTH('dbo.BRN_SmartATM', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [CreateDateTime] DATETIME NOT NULL CONSTRAINT [DF_BRN_SmartATM_CreateDateTime] DEFAULT (getdate()) WITH VALUES;
IF COL_LENGTH('dbo.BRN_SmartATM', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_SmartATM', 'SmartTypeId') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [SmartTypeId] INT NULL CONSTRAINT [DF_BRN_SmartATM_SmartTypeId] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_SmartATM', 'SettlementDate') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [SettlementDate] NVARCHAR(15) NOT NULL CONSTRAINT [DF_BRN_SmartATM_SettlementDate] DEFAULT ('') WITH VALUES;
IF COL_LENGTH('dbo.BRN_SmartATM', 'SubTerminalId') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [SubTerminalId] NVARCHAR(9) NOT NULL CONSTRAINT [DF_BRN_SmartATM_SubTerminalId] DEFAULT ('') WITH VALUES;
IF COL_LENGTH('dbo.BRN_SmartATM', 'SiteName') IS NULL ALTER TABLE dbo.BRN_SmartATM ADD [SiteName] NVARCHAR(50) NOT NULL CONSTRAINT [DF_BRN_SmartATM_SiteName] DEFAULT ('') WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_Suppliers') IS NULL
CREATE TABLE dbo.BRN_Suppliers (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [SupplierCode] NVARCHAR(8) NOT NULL,
    [SupplierName] NVARCHAR(55) NULL,
    [ContactPerson] NVARCHAR(50) NULL,
    [Department] NVARCHAR(30) NULL,
    [JobTitle] NVARCHAR(50) NULL,
    [PostalAddress] NVARCHAR(MAX) NULL,
    [PhysicalAddress] NVARCHAR(MAX) NULL,
    [TelNoA] NVARCHAR(25) NULL,
    [TelNoB] NVARCHAR(25) NULL,
    [FaxNo] NVARCHAR(25) NULL,
    [CellNo] NVARCHAR(50) NULL,
    [EmailAddress] NVARCHAR(50) NULL,
    [IAddress] NVARCHAR(50) NULL DEFAULT ('http://www.'),
    [Comments] NVARCHAR(MAX) NULL,
    [SupplierID] NVARCHAR(50) NOT NULL,
    [IsStock] BIT NOT NULL DEFAULT ((0)),
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [IsCash] BIT NOT NULL DEFAULT ((0)),
    [XCashOrCredit] NCHAR(10) NULL,
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDate] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [isActive] BIT NULL DEFAULT ((1)),
    [MIST_EOD] VARCHAR(10) NULL,
    [PosSystemId] INT NULL,
    CONSTRAINT [PK_BRN_Suppliers] PRIMARY KEY CLUSTERED ([SSBranchId],[SupplierCode])
);
GO

IF COL_LENGTH('dbo.BRN_Suppliers', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_Suppliers_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Suppliers', 'SupplierCode') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [SupplierCode] NVARCHAR(8) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'SupplierName') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [SupplierName] NVARCHAR(55) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'ContactPerson') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [ContactPerson] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'Department') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [Department] NVARCHAR(30) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'JobTitle') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [JobTitle] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'PostalAddress') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [PostalAddress] NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'PhysicalAddress') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [PhysicalAddress] NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'TelNoA') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [TelNoA] NVARCHAR(25) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'TelNoB') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [TelNoB] NVARCHAR(25) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'FaxNo') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [FaxNo] NVARCHAR(25) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'CellNo') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [CellNo] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'EmailAddress') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [EmailAddress] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'IAddress') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [IAddress] NVARCHAR(50) NULL CONSTRAINT [DF_BRN_Suppliers_IAddress] DEFAULT ('http://www.');
IF COL_LENGTH('dbo.BRN_Suppliers', 'Comments') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [Comments] NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'SupplierID') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [SupplierID] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'IsStock') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [IsStock] BIT NOT NULL CONSTRAINT [DF_BRN_Suppliers_IsStock] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Suppliers', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_Suppliers_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_Suppliers', 'IsCash') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [IsCash] BIT NOT NULL CONSTRAINT [DF_BRN_Suppliers_IsCash] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Suppliers', 'XCashOrCredit') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [XCashOrCredit] NCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_Suppliers_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_Suppliers', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'SDKLoadDate') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [SDKLoadDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'isActive') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [isActive] BIT NULL CONSTRAINT [DF_BRN_Suppliers_isActive] DEFAULT ((1));
IF COL_LENGTH('dbo.BRN_Suppliers', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_Suppliers', 'PosSystemId') IS NULL ALTER TABLE dbo.BRN_Suppliers ADD [PosSystemId] INT NULL;
GO

IF OBJECT_ID('dbo.BRN_Transaction') IS NULL
CREATE TABLE dbo.BRN_Transaction (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TransactionNo] NVARCHAR(15) NOT NULL,
    [TypeCode] NVARCHAR(2) NOT NULL,
    [PurchaseRequestDate] DATETIME NULL,
    [SupplierCode] NVARCHAR(8) NOT NULL,
    [BankDetail] NVARCHAR(MAX) NULL,
    [QuoteNo] NVARCHAR(30) NOT NULL,
    [Posted] BIT NOT NULL DEFAULT ((0)),
    [ShiftNo] INT NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [EmailAddress] NVARCHAR(100) NULL,
    [IsDeclined] BIT NOT NULL DEFAULT ((0)),
    [DeclinedReason] NVARCHAR(50) NULL,
    [DeclinedByUserId] INT NULL,
    [DeclinedDate] DATETIME NULL DEFAULT ((0)),
    [MIST_EOD] VARCHAR(10) NULL,
    [Approved] BIT NOT NULL DEFAULT ((0)),
    [ApprovedDate] DATETIME NULL,
    [ApprovedByUserId] INT NULL,
    [Posted_WINCASHUP] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_Transaction] PRIMARY KEY CLUSTERED ([SSBranchId],[TransactionNo],[TypeCode])
);
GO

IF COL_LENGTH('dbo.BRN_Transaction', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_Transaction_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Transaction', 'TransactionNo') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [TransactionNo] NVARCHAR(15) NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'TypeCode') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [TypeCode] NVARCHAR(2) NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'PurchaseRequestDate') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [PurchaseRequestDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'SupplierCode') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [SupplierCode] NVARCHAR(8) NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'BankDetail') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [BankDetail] NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'QuoteNo') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [QuoteNo] NVARCHAR(30) NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'Posted') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [Posted] BIT NOT NULL CONSTRAINT [DF_BRN_Transaction_Posted] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Transaction', 'ShiftNo') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [ShiftNo] INT NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_Transaction_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_Transaction', 'EmailAddress') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [EmailAddress] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'IsDeclined') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [IsDeclined] BIT NOT NULL CONSTRAINT [DF_BRN_Transaction_IsDeclined] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Transaction', 'DeclinedReason') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [DeclinedReason] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'DeclinedByUserId') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [DeclinedByUserId] INT NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'DeclinedDate') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [DeclinedDate] DATETIME NULL CONSTRAINT [DF_BRN_Transaction_DeclinedDate] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_Transaction', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'Approved') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [Approved] BIT NOT NULL CONSTRAINT [DF_BRN_Transaction_Approved] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Transaction', 'ApprovedDate') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [ApprovedDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'ApprovedByUserId') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [ApprovedByUserId] INT NULL;
IF COL_LENGTH('dbo.BRN_Transaction', 'Posted_WINCASHUP') IS NULL ALTER TABLE dbo.BRN_Transaction ADD [Posted_WINCASHUP] BIT NOT NULL CONSTRAINT [DF_BRN_Transaction_Posted_WINCASHUP] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_TransactionLine') IS NULL
CREATE TABLE dbo.BRN_TransactionLine (
    [SSBranchId] INT NOT NULL DEFAULT ((0)),
    [TransactionNo] NVARCHAR(15) NOT NULL,
    [TypeCode] NVARCHAR(2) NOT NULL,
    [ItemNo] SMALLINT NOT NULL,
    [ExpenseNo] INT NOT NULL,
    [PurchaseAmount] MONEY NULL DEFAULT ((0)),
    [PurchaseRequestDescription] NVARCHAR(MAX) NULL,
    [PurchaseRequestJustification] NVARCHAR(MAX) NULL,
    [Posted] BIT NOT NULL DEFAULT ((0)),
    [Imported] BIT NOT NULL DEFAULT ((0)),
    [InvoiceNo] NVARCHAR(75) NULL,
    [InvoiceDate] DATETIME NULL,
    [PastelPosted] BIT NOT NULL DEFAULT ((0)),
    [VATInd] INT NULL,
    [CreateDateTime] DATETIME NULL DEFAULT (getdate()),
    [IsEmailSent] BIT NOT NULL DEFAULT ((0)),
    [IsApprovedEmailSent] BIT NOT NULL DEFAULT ((0)),
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [SDK_EODNO] VARCHAR(10) NULL,
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    CONSTRAINT [PK_BRN_TransactionLine] PRIMARY KEY CLUSTERED ([SSBranchId],[TransactionNo],[TypeCode],[ItemNo])
);
GO

IF COL_LENGTH('dbo.BRN_TransactionLine', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [SSBranchId] INT NOT NULL CONSTRAINT [DF_BRN_TransactionLine_SSBranchId] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'TransactionNo') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [TransactionNo] NVARCHAR(15) NULL;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'TypeCode') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [TypeCode] NVARCHAR(2) NULL;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'ItemNo') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [ItemNo] SMALLINT NULL;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'ExpenseNo') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [ExpenseNo] INT NULL;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'PurchaseAmount') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [PurchaseAmount] MONEY NULL CONSTRAINT [DF_BRN_TransactionLine_PurchaseAmount] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_TransactionLine', 'PurchaseRequestDescription') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [PurchaseRequestDescription] NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'PurchaseRequestJustification') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [PurchaseRequestJustification] NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'Posted') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [Posted] BIT NOT NULL CONSTRAINT [DF_BRN_TransactionLine_Posted] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'Imported') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [Imported] BIT NOT NULL CONSTRAINT [DF_BRN_TransactionLine_Imported] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'InvoiceNo') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [InvoiceNo] NVARCHAR(75) NULL;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'InvoiceDate') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [InvoiceDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'PastelPosted') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [PastelPosted] BIT NOT NULL CONSTRAINT [DF_BRN_TransactionLine_PastelPosted] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'VATInd') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [VATInd] INT NULL;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [CreateDateTime] DATETIME NULL CONSTRAINT [DF_BRN_TransactionLine_CreateDateTime] DEFAULT (getdate());
IF COL_LENGTH('dbo.BRN_TransactionLine', 'IsEmailSent') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [IsEmailSent] BIT NOT NULL CONSTRAINT [DF_BRN_TransactionLine_IsEmailSent] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'IsApprovedEmailSent') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [IsApprovedEmailSent] BIT NOT NULL CONSTRAINT [DF_BRN_TransactionLine_IsApprovedEmailSent] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'isSDKLoaded') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_BRN_TransactionLine_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_TransactionLine', 'SDK_EODNO') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'SDKMessage') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_TransactionLine', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_TransactionLine ADD [MIST_EOD] VARCHAR(10) NULL;
GO

IF OBJECT_ID('dbo.BRN_Yumbi') IS NULL
CREATE TABLE dbo.BRN_Yumbi (
    [SSBranchId] INT NOT NULL,
    [ID] NVARCHAR(10) NOT NULL,
    [TransactionDate] DATETIME NULL,
    [TransactionTime] DATETIME NULL,
    [Restaurant] NVARCHAR(100) NULL,
    [Channel] NVARCHAR(100) NULL,
    [ReceiptType] NVARCHAR(100) NULL,
    [OrderType] NVARCHAR(100) NULL,
    [FirstName] NVARCHAR(100) NULL,
    [LastName] NVARCHAR(100) NULL,
    [Phone] NVARCHAR(100) NULL,
    [Email] NVARCHAR(100) NULL,
    [Payment] NVARCHAR(100) NULL,
    [GrossAmount] MONEY NULL,
    [ProductAmount] MONEY NULL,
    [DiscountAmount] MONEY NULL,
    [DeliveryFee] MONEY NULL,
    [DriverTip] MONEY NULL,
    [NetAmount] MONEY NULL,
    [Street] NVARCHAR(100) NULL,
    [AddressLine1] NVARCHAR(100) NULL,
    [AddressLine2] NVARCHAR(100) NULL,
    [Latitude] NVARCHAR(100) NULL,
    [Longitude] NVARCHAR(100) NULL,
    [IsSelectedForDailyBanking] BIT NULL DEFAULT ((0)),
    [MIST_EOD] VARCHAR(10) NULL,
    CONSTRAINT [PK_BRN_Yumbi] PRIMARY KEY CLUSTERED ([SSBranchId],[ID])
);
GO

IF COL_LENGTH('dbo.BRN_Yumbi', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'ID') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [ID] NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'TransactionTime') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [TransactionTime] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'Restaurant') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [Restaurant] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'Channel') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [Channel] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'ReceiptType') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [ReceiptType] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'OrderType') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [OrderType] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'FirstName') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [FirstName] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'LastName') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [LastName] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'Phone') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [Phone] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'Email') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [Email] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'Payment') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [Payment] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'GrossAmount') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [GrossAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'ProductAmount') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [ProductAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'DiscountAmount') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [DiscountAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'DeliveryFee') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [DeliveryFee] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'DriverTip') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [DriverTip] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'NetAmount') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [NetAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'Street') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [Street] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'AddressLine1') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [AddressLine1] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'AddressLine2') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [AddressLine2] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'Latitude') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [Latitude] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'Longitude') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [Longitude] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Yumbi', 'IsSelectedForDailyBanking') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [IsSelectedForDailyBanking] BIT NULL CONSTRAINT [DF_BRN_Yumbi_IsSelectedForDailyBanking] DEFAULT ((0));
IF COL_LENGTH('dbo.BRN_Yumbi', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_Yumbi ADD [MIST_EOD] VARCHAR(10) NULL;
GO

IF OBJECT_ID('dbo.BRN_YumbiVoucher') IS NULL
CREATE TABLE dbo.BRN_YumbiVoucher (
    [SSBranchId] INT NOT NULL,
    [RedemptionDate] DATETIME NOT NULL,
    [CampaignName] NVARCHAR(100) NULL,
    [SponsorName] NVARCHAR(100) NULL,
    [Details] NVARCHAR(100) NULL,
    [OrderChannel] NVARCHAR(50) NULL,
    [RedeemedAmount] MONEY NULL,
    [SettledAmount] MONEY NULL,
    [VoucherType] NVARCHAR(50) NULL,
    [RedemptionChannel] NVARCHAR(50) NULL,
    [YumbiStoreId] INT NULL,
    [RestaurantName] NVARCHAR(100) NULL,
    [RestaurantExternalReferenceID] NVARCHAR(100) NULL,
    [CustomerName] NVARCHAR(50) NULL,
    [CustomerReference] NVARCHAR(50) NOT NULL,
    [CustomerRegistrationDate] DATETIME NULL,
    [DateActivated] DATETIME NULL,
    [ExpiryDate] DATETIME NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [IsSelectedForDailyBanking] BIT NOT NULL DEFAULT ((0)),
    CONSTRAINT [PK_BRN_YumbiVoucher] PRIMARY KEY CLUSTERED ([SSBranchId],[RedemptionDate],[CustomerReference])
);
GO

IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'RedemptionDate') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [RedemptionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'CampaignName') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [CampaignName] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'SponsorName') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [SponsorName] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'Details') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [Details] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'OrderChannel') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [OrderChannel] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'RedeemedAmount') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [RedeemedAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'SettledAmount') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [SettledAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'VoucherType') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [VoucherType] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'RedemptionChannel') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [RedemptionChannel] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'YumbiStoreId') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [YumbiStoreId] INT NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'RestaurantName') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [RestaurantName] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'RestaurantExternalReferenceID') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [RestaurantExternalReferenceID] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'CustomerName') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [CustomerName] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'CustomerReference') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [CustomerReference] NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'CustomerRegistrationDate') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [CustomerRegistrationDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'DateActivated') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [DateActivated] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'ExpiryDate') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [ExpiryDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_YumbiVoucher', 'IsSelectedForDailyBanking') IS NULL ALTER TABLE dbo.BRN_YumbiVoucher ADD [IsSelectedForDailyBanking] BIT NOT NULL CONSTRAINT [DF_BRN_YumbiVoucher_IsSelectedForDailyBanking] DEFAULT ((0)) WITH VALUES;
GO

IF OBJECT_ID('dbo.BRN_Zapper') IS NULL
CREATE TABLE dbo.BRN_Zapper (
    [SSBranchId] INT NOT NULL,
    [TransactionDate] DATETIME NOT NULL,
    [UniqueField] NVARCHAR(150) NOT NULL,
    [Reference1] NVARCHAR(100) NOT NULL,
    [Status] NVARCHAR(100) NOT NULL,
    [CustomerName] NVARCHAR(100) NULL,
    [BillAmount] MONEY NULL,
    [TipAmount] MONEY NULL,
    [VoucherAmount] MONEY NULL,
    [NettTurnover] MONEY NULL,
    [FeeTotal] MONEY NULL,
    [ProcessingFeeVAT] MONEY NULL,
    [VoucherFee] MONEY NULL,
    [SettlementTotal] MONEY NULL,
    [CustomerTotal] MONEY NULL,
    [Reference2] NVARCHAR(100) NULL,
    [IsSelectedForDailyBanking] BIT NOT NULL DEFAULT ((0)),
    [MIST_EOD] VARCHAR(10) NULL,
    CONSTRAINT [PK_BRN_Zapper] PRIMARY KEY CLUSTERED ([SSBranchId],[TransactionDate],[UniqueField])
);
GO

IF COL_LENGTH('dbo.BRN_Zapper', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'TransactionDate') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [TransactionDate] DATETIME NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'UniqueField') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [UniqueField] NVARCHAR(150) NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'Reference1') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [Reference1] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'Status') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [Status] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'CustomerName') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [CustomerName] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'BillAmount') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [BillAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'TipAmount') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [TipAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'VoucherAmount') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [VoucherAmount] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'NettTurnover') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [NettTurnover] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'FeeTotal') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [FeeTotal] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'ProcessingFeeVAT') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [ProcessingFeeVAT] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'VoucherFee') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [VoucherFee] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'SettlementTotal') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [SettlementTotal] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'CustomerTotal') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [CustomerTotal] MONEY NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'Reference2') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [Reference2] NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.BRN_Zapper', 'IsSelectedForDailyBanking') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [IsSelectedForDailyBanking] BIT NOT NULL CONSTRAINT [DF_BRN_Zapper_IsSelectedForDailyBanking] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_Zapper', 'MIST_EOD') IS NULL ALTER TABLE dbo.BRN_Zapper ADD [MIST_EOD] VARCHAR(10) NULL;
GO

IF OBJECT_ID('dbo.DBF_INVHISTH_CUSTOMER') IS NULL
CREATE TABLE dbo.DBF_INVHISTH_CUSTOMER (
    [SSBranchId] INT NOT NULL,
    [DRC] CHAR(1) NOT NULL,
    [TERMNUM] CHAR(3) NULL,
    [SHIFT] CHAR(2) NULL,
    [TILLNUM] CHAR(3) NULL,
    [LOGFILE] CHAR(10) NOT NULL,
    [CASHIER] CHAR(6) NULL,
    [USERID] CHAR(6) NULL,
    [TYPE] CHAR(1) NOT NULL,
    [DRCR] CHAR(1) NULL,
    [CUSTOMER] CHAR(1) NULL,
    [REF] CHAR(20) NOT NULL,
    [DATE] DATETIME NOT NULL,
    [CNUM] CHAR(10) NOT NULL,
    [CNAM] CHAR(30) NULL,
    [ORDNUM] CHAR(10) NULL,
    [REPCODE] CHAR(6) NULL,
    [DESC] CHAR(20) NULL,
    [PADD1] CHAR(30) NULL,
    [PADD2] CHAR(30) NULL,
    [PADD3] CHAR(30) NULL,
    [PADD4] CHAR(30) NULL,
    [BADD1] CHAR(30) NULL,
    [BADD2] CHAR(30) NULL,
    [BADD3] CHAR(30) NULL,
    [BADD4] CHAR(30) NULL,
    [DISCOUNT] DECIMAL(18,6) NULL,
    [GOODS] DECIMAL(18,6) NULL,
    [TOTDISC] DECIMAL(18,6) NULL,
    [TOTVAT] DECIMAL(18,6) NULL,
    [TOTAL] DECIMAL(18,6) NULL,
    [COST] DECIMAL(18,6) NULL,
    [EXCLU_DISC] DECIMAL(18,6) NULL,
    [TELEPHONE] CHAR(20) NULL,
    [INCLUSIVE] BIT NULL,
    [TAXFREE] BIT NULL,
    [POSTED] CHAR(1) NULL,
    [CURRENT] CHAR(1) NULL,
    [LOCKED] BIT NULL,
    [FLAG] CHAR(1) NULL,
    [TRANSFLAG] CHAR(1) NULL,
    [AMTPD] DECIMAL(18,6) NULL,
    [LOCATION] CHAR(10) NOT NULL,
    [EOD_CNTR] CHAR(8) NOT NULL,
    [EOW_CNTR] CHAR(8) NULL,
    [SYSDATE] DATETIME NULL,
    [SYSTIME] CHAR(8) NULL,
    [WINVER] BIT NULL,
    [DUEDATE] DATETIME NULL,
    [DCSEQ] INT NULL,
    [TLADATE] DATETIME NULL,
    [isSDKLoaded] BIT NULL DEFAULT ((0)),
    [IsSelectedForBanking] BIT NOT NULL DEFAULT ((0)),
    [SDKMessage] VARCHAR(200) NULL,
    [SDKLoadDateTime] DATETIME NULL,
    [SDK_EODNO] VARCHAR(10) NULL,
    [MIST_EOD] VARCHAR(10) NULL,
    [EOD_DATE] DATETIME NULL,
    CONSTRAINT [PK_DBF_INVHISTH_CUSTOMER] PRIMARY KEY CLUSTERED ([SSBranchId],[DRC],[LOGFILE],[TYPE],[REF],[DATE],[CNUM],[LOCATION],[EOD_CNTR])
);
GO

IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'SSBranchId') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'DRC') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [DRC] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'TERMNUM') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [TERMNUM] CHAR(3) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'SHIFT') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [SHIFT] CHAR(2) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'TILLNUM') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [TILLNUM] CHAR(3) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'LOGFILE') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [LOGFILE] CHAR(10) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'CASHIER') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [CASHIER] CHAR(6) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'USERID') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [USERID] CHAR(6) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'TYPE') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [TYPE] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'DRCR') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [DRCR] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'CUSTOMER') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [CUSTOMER] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'REF') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [REF] CHAR(20) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'DATE') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [DATE] DATETIME NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'CNUM') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [CNUM] CHAR(10) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'CNAM') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [CNAM] CHAR(30) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'ORDNUM') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [ORDNUM] CHAR(10) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'REPCODE') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [REPCODE] CHAR(6) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'DESC') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [DESC] CHAR(20) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'PADD1') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [PADD1] CHAR(30) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'PADD2') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [PADD2] CHAR(30) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'PADD3') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [PADD3] CHAR(30) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'PADD4') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [PADD4] CHAR(30) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'BADD1') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [BADD1] CHAR(30) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'BADD2') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [BADD2] CHAR(30) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'BADD3') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [BADD3] CHAR(30) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'BADD4') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [BADD4] CHAR(30) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'DISCOUNT') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [DISCOUNT] DECIMAL(18,6) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'GOODS') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [GOODS] DECIMAL(18,6) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'TOTDISC') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [TOTDISC] DECIMAL(18,6) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'TOTVAT') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [TOTVAT] DECIMAL(18,6) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'TOTAL') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [TOTAL] DECIMAL(18,6) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'COST') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [COST] DECIMAL(18,6) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'EXCLU_DISC') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [EXCLU_DISC] DECIMAL(18,6) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'TELEPHONE') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [TELEPHONE] CHAR(20) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'INCLUSIVE') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [INCLUSIVE] BIT NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'TAXFREE') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [TAXFREE] BIT NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'POSTED') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [POSTED] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'CURRENT') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [CURRENT] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'LOCKED') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [LOCKED] BIT NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'FLAG') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [FLAG] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'TRANSFLAG') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [TRANSFLAG] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'AMTPD') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [AMTPD] DECIMAL(18,6) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'LOCATION') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [LOCATION] CHAR(10) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'EOD_CNTR') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [EOD_CNTR] CHAR(8) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'EOW_CNTR') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [EOW_CNTR] CHAR(8) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'SYSDATE') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [SYSDATE] DATETIME NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'SYSTIME') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [SYSTIME] CHAR(8) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'WINVER') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [WINVER] BIT NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'DUEDATE') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [DUEDATE] DATETIME NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'DCSEQ') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [DCSEQ] INT NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'TLADATE') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [TLADATE] DATETIME NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'isSDKLoaded') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [isSDKLoaded] BIT NULL CONSTRAINT [DF_DBF_INVHISTH_CUSTOMER_isSDKLoaded] DEFAULT ((0));
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'IsSelectedForBanking') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [IsSelectedForBanking] BIT NOT NULL CONSTRAINT [DF_DBF_INVHISTH_CUSTOMER_IsSelectedForBanking] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'SDKMessage') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [SDKMessage] VARCHAR(200) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'SDKLoadDateTime') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [SDKLoadDateTime] DATETIME NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'SDK_EODNO') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [SDK_EODNO] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'MIST_EOD') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [MIST_EOD] VARCHAR(10) NULL;
IF COL_LENGTH('dbo.DBF_INVHISTH_CUSTOMER', 'EOD_DATE') IS NULL ALTER TABLE dbo.DBF_INVHISTH_CUSTOMER ADD [EOD_DATE] DATETIME NULL;
GO

IF OBJECT_ID('dbo.DBF_POSTR_SUPPLIER') IS NULL
CREATE TABLE dbo.DBF_POSTR_SUPPLIER (
    [SSBranchId] INT NOT NULL,
    [DRC] CHAR(1) NULL,
    [TERMNUM] CHAR(3) NULL,
    [DATE] DATETIME NOT NULL,
    [USERID] CHAR(6) NULL,
    [SHIFT] CHAR(2) NULL,
    [TRCOD] CHAR(1) NULL,
    [CNUM] CHAR(10) NULL,
    [CNAM] CHAR(30) NULL,
    [REPCODE] CHAR(6) NULL,
    [DESC] CHAR(20) NOT NULL,
    [ORDNUM] CHAR(10) NULL,
    [DRCR] CHAR(1) NULL,
    [REF] CHAR(20) NOT NULL,
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
    [IsSelectedForBanking] BIT NOT NULL DEFAULT ((0)),
    [MIST_EOD] VARCHAR(10) NULL,
    CONSTRAINT [PK_DBF_POSTR_SUPPLIER] PRIMARY KEY CLUSTERED ([SSBranchId],[DATE],[DESC],[REF],[LOCATION],[EOD_CNTR])
);
GO

IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'SSBranchId') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [SSBranchId] INT NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'DRC') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [DRC] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'TERMNUM') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [TERMNUM] CHAR(3) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'DATE') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [DATE] DATETIME NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'USERID') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [USERID] CHAR(6) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'SHIFT') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [SHIFT] CHAR(2) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'TRCOD') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [TRCOD] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'CNUM') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [CNUM] CHAR(10) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'CNAM') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [CNAM] CHAR(30) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'REPCODE') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [REPCODE] CHAR(6) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'DESC') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [DESC] CHAR(20) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'ORDNUM') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [ORDNUM] CHAR(10) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'DRCR') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [DRCR] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'REF') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [REF] CHAR(20) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'CUST') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [CUST] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'GOODS') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [GOODS] DECIMAL(18,6) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'VAT') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [VAT] DECIMAL(18,6) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'TOTAL') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [TOTAL] DECIMAL(18,6) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'CHANGE') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [CHANGE] DECIMAL(18,6) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'ACTYPE') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [ACTYPE] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'PRINTED') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [PRINTED] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'STATCNTR') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [STATCNTR] INT NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'CURRENT') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [CURRENT] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'LOCATION') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [LOCATION] CHAR(10) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'EOD_CNTR') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [EOD_CNTR] CHAR(8) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'EOW_CNTR') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [EOW_CNTR] CHAR(8) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'EXPORTED') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [EXPORTED] CHAR(1) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'SYSDATE') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [SYSDATE] DATETIME NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'SYSTIME') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [SYSTIME] CHAR(8) NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'WINVER') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [WINVER] BIT NULL;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'IsSelectedForBanking') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [IsSelectedForBanking] BIT NOT NULL CONSTRAINT [DF_DBF_POSTR_SUPPLIER_IsSelectedForBanking] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.DBF_POSTR_SUPPLIER', 'MIST_EOD') IS NULL ALTER TABLE dbo.DBF_POSTR_SUPPLIER ADD [MIST_EOD] VARCHAR(10) NULL;
GO

-- ----------------------------------------------------------------------------
-- 5. Till balance (T038).
--
-- A till balance moves part of one cashup's variance onto another cashup —
-- usually the same person's, on a different till, shift or day — as PumpIT's
-- own till-balance screen does: two BRN_TillBalance rows under one number,
-- one taking the amount off, the other putting it on, so the pair sums to nil.
-- Measured on the customer's instance on 27 Sep 2026: 20,804 rows, 10,413
-- numbers, every number since mid-2024 a pair that nets to R0.00.
--
--   BRN_TillBalance   the customer's shape, column for column, with its
--                     primary key (NONCLUSTERED there — the table is a heap)
--                     and its defaults. No trigger, no foreign key.
--   SS_UniqueNumber   SectionId 3 is the till balance counter
--                     (dbo.sp_GenerateTillBalanceNo). pumpit-recon.sql makes
--                     the table with SectionId 1 only; this adds section 3.
--
-- agora.usp_Cash_SaveTillBalance INSERTs the pair and takes the number; the
-- approval that posts it to both cashups stays in PumpIT for now (see the
-- procedure's header).
-- ----------------------------------------------------------------------------

IF OBJECT_ID('dbo.BRN_TillBalance') IS NULL
CREATE TABLE dbo.BRN_TillBalance (
    [SSBranchId]             INT            NOT NULL,
    [TillNo]                 INT            NOT NULL,
    [ShiftNo]                INT            NOT NULL,
    [TransactionDate]        DATETIME       NOT NULL,
    [LogFile]                CHAR(10)       NOT NULL,
    [TillBalanceNo]          INT            NOT NULL,
    [EmployeeCode]           NVARCHAR(11)   NOT NULL,
    [TransactionAmount]      MONEY          NULL,
    [OriginalEmployeeAmount] MONEY          NULL,
    [EmployeeAmount]         MONEY          NULL,
    [CreatedByUserId]        INT            NULL,
    [Approved]               BIT            NOT NULL CONSTRAINT [DF_BRN_TillBalance_Approved] DEFAULT ((0)),
    [ApprovedDate]           DATETIME       NULL,
    [ApprovedByUserId]       INT            NOT NULL CONSTRAINT [DF_BRN_TillBalance_ApprovedByUserId] DEFAULT ((0)),
    [IsDeclined]             BIT            NOT NULL CONSTRAINT [DF_BRN_TillBalance_IsDeclined] DEFAULT ((0)),
    [DeclinedReason]         NVARCHAR(50)   NULL,
    [DeclinedByUserId]       INT            NOT NULL CONSTRAINT [DF_BRN_TillBalance_DeclinedByUserId] DEFAULT ((0)),
    [DeclinedDate]           DATETIME       NULL,
    [MIST_EOD]               VARCHAR(10)    NULL,
    [CreateDateTime]         DATETIME       NOT NULL CONSTRAINT [DF_BRN_TillBalance_CreateDateTime] DEFAULT (getdate()),
    CONSTRAINT [PK_BRN_TillBalance] PRIMARY KEY NONCLUSTERED ([SSBranchId],[TillNo],[ShiftNo],[TransactionDate],[LogFile],[TillBalanceNo])
);
GO

IF OBJECT_ID('dbo.SS_UniqueNumber') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM dbo.SS_UniqueNumber WHERE SectionId = 3)
    INSERT INTO dbo.SS_UniqueNumber (SectionId, NextUniqueNumber) VALUES (3, 5000);
GO

-- ----------------------------------------------------------------------------
-- 6. Drop safe (T039).
--
-- The customer's shapes, measured on 27 Sep 2026 (sys.columns on PumpIT):
--
--   BRN_DropSafe             33,979 bags; PK NONCLUSTERED (SSBranchId, BagNo).
--                            Section 4 above makes it in that shape; on a
--                            container where pumpit-recon.sql made it first it
--                            is the narrow, all-NULLable one, and stays so —
--                            the Recon tests insert bags without a time or a
--                            manager, and a rebuild would break them.
--   BRN_DropSafe_Collection  4,450 collections; CollectionId is BIGINT
--                            IDENTITY(1,1), PK NONCLUSTERED (CollectionId).
--                            pumpit-recon.sql makes it with a plain INT and the
--                            Recon tests insert their own ids, so the IDENTITY
--                            cannot be added here without breaking them. It is
--                            EMULATED instead: a DEFAULT from a sequence, so an
--                            INSERT that leaves CollectionId out — which is how
--                            agora.usp_Cash_CollectDropSafe writes, exactly as
--                            against the IDENTITY on live — gets the next
--                            number back through OUTPUT inserted.CollectionId,
--                            and an INSERT that names one still works. The
--                            sequence starts at 900001, well clear of any id a
--                            test chooses.
--   BRN_DropSafe_ReasonAmountMoreThanR2000
--                            the four reasons the PumpIT drop screen offers for
--                            a bag over R2,000 (free text is accepted as well:
--                            7,186 bags carry none, the rest 132 different
--                            texts). Nothing in the stubs made it before.
--
-- No rows: a test inserts its own TEST- reasons and employees and removes them.
-- ----------------------------------------------------------------------------

IF OBJECT_ID('dbo.BRN_DropSafe_ReasonAmountMoreThanR2000') IS NULL
CREATE TABLE dbo.BRN_DropSafe_ReasonAmountMoreThanR2000 (
    [ReasonAmountMoreThanR2000Id] INT IDENTITY(1,1) NOT NULL,
    [ReasonAmountMoreThanR2000]   NVARCHAR(50)      NOT NULL,
    [IsActive]                    BIT               NOT NULL CONSTRAINT [DF_BRN_DropSafe_ReasonAmountMoreThanR2000_IsActive] DEFAULT ((1)),
    CONSTRAINT [PK_BRN_DropSafe_ReasonAmountMoreThanR2000] PRIMARY KEY NONCLUSTERED ([ReasonAmountMoreThanR2000Id])
);
GO

IF OBJECT_ID('dbo.BRN_DropSafe_Collection') IS NULL
CREATE TABLE dbo.BRN_DropSafe_Collection (
    [CollectionId]   BIGINT IDENTITY(1,1) NOT NULL,
    [SSBranchId]     INT           NOT NULL,
    [DBagNo]         NVARCHAR(20)  NOT NULL,
    [ManagerCode]    NVARCHAR(10)  NOT NULL,
    [SecurityName]   NVARCHAR(50)  NOT NULL,
    [CollectionDate] DATETIME      NOT NULL,
    [CollectionTime] NVARCHAR(8)   NOT NULL,
    [TotalNoOfBags]  FLOAT         NULL,
    [TotalAmount]    MONEY         NOT NULL CONSTRAINT [DF_BRN_DropSafe_Collection_TotalAmount] DEFAULT ((0.00)),
    [CreateDateTime] DATETIME      NOT NULL CONSTRAINT [DF_BRN_DropSafe_Collection_CreateDateTime] DEFAULT (getdate()),
    [MIST_EOD]       VARCHAR(10)   NULL,
    CONSTRAINT [PK_BRN_DropSafe_Collection] PRIMARY KEY NONCLUSTERED ([CollectionId])
);
GO

IF OBJECT_ID('dbo.SQ_BRN_DropSafe_Collection', 'SO') IS NULL
    EXEC ('CREATE SEQUENCE dbo.SQ_BRN_DropSafe_Collection AS BIGINT START WITH 900001 INCREMENT BY 1;');
GO

IF COLUMNPROPERTY(OBJECT_ID('dbo.BRN_DropSafe_Collection'), 'CollectionId', 'IsIdentity') = 0
   AND NOT EXISTS (SELECT 1 FROM sys.default_constraints
                   WHERE parent_object_id = OBJECT_ID('dbo.BRN_DropSafe_Collection')
                     AND parent_column_id = COLUMNPROPERTY(OBJECT_ID('dbo.BRN_DropSafe_Collection'), 'CollectionId', 'ColumnId'))
    ALTER TABLE dbo.BRN_DropSafe_Collection
        ADD CONSTRAINT [DF_BRN_DropSafe_Collection_CollectionId]
        DEFAULT (NEXT VALUE FOR dbo.SQ_BRN_DropSafe_Collection) FOR [CollectionId];
GO

-- ----------------------------------------------------------------------------
-- 7. Staff shorts (T040).
--
-- The customer's shapes, measured on 27 Sep 2026 (sys.columns on PumpIT):
--
--   BRN_StaffShorts   4,851 shorts; PK NONCLUSTERED (SSBranchId, StaffShortsNo),
--                     no trigger. StaffShortsNo comes from SS_UniqueNumber
--                     section 2 (dbo.sp_GenerateStaffShortsNo) and is unique
--                     across every site (4,851 numbers for 4,851 rows).
--                     pumpit-reports.sql makes it narrower — no
--                     IsSelectedForDailyBanking, no MIST_EOD, no defaults, no
--                     key — so each is ADDED here where missing. The key is
--                     added only while no two rows share one.
--   BRN_DailyBankingEmployees   the cashup's pump-shortages leg a posted short
--                     is copied onto. Its PK NONCLUSTERED (SSBranchId, TillNo,
--                     ShiftNo, TransactionDate, EmployeeCode) — ONE line per
--                     person per cashup — is added where the container has no
--                     key and no duplicate, so the stub refuses what the
--                     customer's instance refuses.
--   SS_UniqueNumber   section 2 is the staff-short counter; this adds it at
--                     5000 (the customer's stood at 4852 on 27 Sep 2026).
--
-- No rows: a test inserts its own TEST- employees and shorts and removes them.
-- ----------------------------------------------------------------------------

IF OBJECT_ID('dbo.BRN_StaffShorts') IS NULL
CREATE TABLE dbo.BRN_StaffShorts (
    [SSBranchId]                INT            NOT NULL,
    [StaffShortsNo]             INT            NOT NULL,
    [EmployeeCode]              NVARCHAR(11)   NOT NULL,
    [AmountShort]               MONEY          NULL,
    [Note]                      NVARCHAR(MAX)  NULL,
    [Reason]                    NVARCHAR(MAX)  NULL,
    [TransactionDate]           DATETIME       NULL,
    [CreatedByUserId]           INT            NULL,
    [Approved]                  BIT            NOT NULL CONSTRAINT [DF_BRN_StaffShorts_Approved] DEFAULT ((0)),
    [ApprovedDate]              DATETIME       NULL,
    [ApprovedByUserId]          INT            NULL,
    [IsDeclined]                BIT            NOT NULL CONSTRAINT [DF_BRN_StaffShorts_IsDeclined] DEFAULT ((0)),
    [DeclinedReason]            NVARCHAR(50)   NULL,
    [DeclinedByUserId]          INT            NOT NULL CONSTRAINT [DF_BRN_StaffShorts_DeclinedByUserId] DEFAULT ((0)),
    [DeclinedDate]              DATETIME       NULL,
    [IsSelectedForDailyBanking] BIT            NULL CONSTRAINT [DF_BRN_StaffShorts_IsSelectedForDailyBanking] DEFAULT ((0)),
    [CreateDateTime]            DATETIME       NULL CONSTRAINT [DF_BRN_StaffShorts_CreateDateTime] DEFAULT (getdate()),
    [MIST_EOD]                  VARCHAR(10)    NULL,
    CONSTRAINT [PK_BRN_StaffShorts] PRIMARY KEY NONCLUSTERED ([SSBranchId], [StaffShortsNo])
);
GO

IF COL_LENGTH('dbo.BRN_StaffShorts', 'IsSelectedForDailyBanking') IS NULL
    ALTER TABLE dbo.BRN_StaffShorts ADD [IsSelectedForDailyBanking] BIT NULL
        CONSTRAINT [DF_BRN_StaffShorts_IsSelectedForDailyBanking] DEFAULT ((0)) WITH VALUES;
IF COL_LENGTH('dbo.BRN_StaffShorts', 'MIST_EOD') IS NULL
    ALTER TABLE dbo.BRN_StaffShorts ADD [MIST_EOD] VARCHAR(10) NULL;
GO

-- The four defaults the customer's table carries, on a container whose table
-- was made without them.
IF NOT EXISTS (SELECT 1 FROM sys.default_constraints WHERE parent_object_id = OBJECT_ID('dbo.BRN_StaffShorts')
               AND parent_column_id = COLUMNPROPERTY(OBJECT_ID('dbo.BRN_StaffShorts'), 'Approved', 'ColumnId'))
    ALTER TABLE dbo.BRN_StaffShorts ADD CONSTRAINT [DF_BRN_StaffShorts_Approved] DEFAULT ((0)) FOR [Approved];
IF NOT EXISTS (SELECT 1 FROM sys.default_constraints WHERE parent_object_id = OBJECT_ID('dbo.BRN_StaffShorts')
               AND parent_column_id = COLUMNPROPERTY(OBJECT_ID('dbo.BRN_StaffShorts'), 'IsDeclined', 'ColumnId'))
    ALTER TABLE dbo.BRN_StaffShorts ADD CONSTRAINT [DF_BRN_StaffShorts_IsDeclined] DEFAULT ((0)) FOR [IsDeclined];
IF NOT EXISTS (SELECT 1 FROM sys.default_constraints WHERE parent_object_id = OBJECT_ID('dbo.BRN_StaffShorts')
               AND parent_column_id = COLUMNPROPERTY(OBJECT_ID('dbo.BRN_StaffShorts'), 'DeclinedByUserId', 'ColumnId'))
    ALTER TABLE dbo.BRN_StaffShorts ADD CONSTRAINT [DF_BRN_StaffShorts_DeclinedByUserId] DEFAULT ((0)) FOR [DeclinedByUserId];
IF NOT EXISTS (SELECT 1 FROM sys.default_constraints WHERE parent_object_id = OBJECT_ID('dbo.BRN_StaffShorts')
               AND parent_column_id = COLUMNPROPERTY(OBJECT_ID('dbo.BRN_StaffShorts'), 'CreateDateTime', 'ColumnId'))
    ALTER TABLE dbo.BRN_StaffShorts ADD CONSTRAINT [DF_BRN_StaffShorts_CreateDateTime] DEFAULT (getdate()) FOR [CreateDateTime];
GO

IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE parent_object_id = OBJECT_ID('dbo.BRN_StaffShorts') AND type = 'PK')
   AND NOT EXISTS (SELECT 1 FROM dbo.BRN_StaffShorts GROUP BY SSBranchId, StaffShortsNo HAVING COUNT(*) > 1)
    ALTER TABLE dbo.BRN_StaffShorts ADD CONSTRAINT [PK_BRN_StaffShorts] PRIMARY KEY NONCLUSTERED ([SSBranchId], [StaffShortsNo]);
GO

IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE parent_object_id = OBJECT_ID('dbo.BRN_DailyBankingEmployees') AND type = 'PK')
   AND NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.BRN_DailyBankingEmployees')
                   AND name IN ('SSBranchId', 'TillNo', 'ShiftNo', 'TransactionDate', 'EmployeeCode') AND is_nullable = 1)
   AND NOT EXISTS (SELECT 1 FROM dbo.BRN_DailyBankingEmployees
                   GROUP BY SSBranchId, TillNo, ShiftNo, TransactionDate, EmployeeCode HAVING COUNT(*) > 1)
    ALTER TABLE dbo.BRN_DailyBankingEmployees ADD CONSTRAINT [PK_BRN_DailyBankingEmployees]
        PRIMARY KEY NONCLUSTERED ([SSBranchId], [TillNo], [ShiftNo], [TransactionDate], [EmployeeCode]);
GO

IF OBJECT_ID('dbo.SS_UniqueNumber') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM dbo.SS_UniqueNumber WHERE SectionId = 2)
    INSERT INTO dbo.SS_UniqueNumber (SectionId, NextUniqueNumber) VALUES (2, 5000);
GO

-- ----------------------------------------------------------------------------
-- 8. Deductions (T041).
--
-- BRN_EmployeePost: the post a person holds. The legacy deduction reports
-- print it and INNER JOIN it — a person whose PostNo has no post drops out of
-- the stock-loss and non-integrated tabs — so the port keeps the join, and the
-- stub needs the table. Keyed (SSBranchId, PostNo) on the customer's instance,
-- 311 rows, no duplicates (read 30 Sep 2026).
-- ----------------------------------------------------------------------------

IF OBJECT_ID('dbo.BRN_EmployeePost') IS NULL
CREATE TABLE dbo.BRN_EmployeePost (
    SSBranchId       INT            NOT NULL,
    PostNo           SMALLINT       NOT NULL,
    PostDescription  NVARCHAR(50)   NULL,
    StockControl     BIT            NOT NULL CONSTRAINT [DF_BRN_EmployeePost_StockControl] DEFAULT ((0)),
    CreateDateTime   DATETIME       NULL,
    MIST_EOD         VARCHAR(10)    NULL,
    CONSTRAINT [PK_BRN_EmployeePost] PRIMARY KEY NONCLUSTERED ([SSBranchId], [PostNo])
);
GO
