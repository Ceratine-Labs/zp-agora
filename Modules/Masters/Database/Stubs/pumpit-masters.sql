/* ============================================================================
   [PumpIT] reference master sources (T022) — LOCAL STUB ONLY.

   The shape, not the estate. Masters' v1__02a creates views naming these
   tables across databases, and three-part naming is same-instance only, so
   without them the views will not CREATE on the local container.

   Column names and types are the live ones (INFORMATION_SCHEMA on the
   customer's instance, 12 Nov 2026). Tables another stub already created are
   only WIDENED here, with the columns the masters read and that stub left out.

   Run, and filled with real rows from the customer's instance (a read of
   about 1,600 rows), by:
       php artisan agora:sync-local-masters

   Each statement below is its own batch (separated by GO) and is run inside
   the local PumpIT database. NEVER run against the customer's instance: there
   the tables already exist, and the command refuses a non-local host.
   ============================================================================ */

IF OBJECT_ID('dbo.SS_StandardCategory') IS NULL
CREATE TABLE dbo.SS_StandardCategory (
    StandardCategoryId INT NOT NULL PRIMARY KEY,
    StandardCategory VARCHAR(40) NOT NULL,
    SupplierGLCode VARCHAR(50) NOT NULL,
    SalesGLCode VARCHAR(50) NOT NULL,
    IsActive BIT NOT NULL
);
GO
IF OBJECT_ID('dbo.BRN_Tank') IS NULL
CREATE TABLE dbo.BRN_Tank (
    SSBranchId INT NOT NULL,
    TankNo NVARCHAR(4) NOT NULL,
    TankDescription NVARCHAR(50) NULL,
    CreateDateTime DATETIME NULL,
    MIST_EOD VARCHAR(10) NULL,
    PRIMARY KEY (SSBranchId, TankNo)
);
GO
IF OBJECT_ID('dbo.SS_Branch_ABSA_MerchantNo') IS NULL
CREATE TABLE dbo.SS_Branch_ABSA_MerchantNo (
    ABSAMerchantNoId INT NOT NULL PRIMARY KEY,
    SSBranchId INT NOT NULL,
    ABSA_MerchantNo NVARCHAR(12) NULL,
    [Description] NVARCHAR(50) NULL,
    MIST_EOD VARCHAR(10) NULL,
    IsActive BIT NOT NULL
);
GO
IF OBJECT_ID('dbo.SS_Branch_SMARTATM_TerminalNo') IS NULL
CREATE TABLE dbo.SS_Branch_SMARTATM_TerminalNo (
    SMARTATMTerminalNoId INT NOT NULL PRIMARY KEY,
    SSBranchId INT NOT NULL,
    SMARTATM_TerminalNo NVARCHAR(12) NULL,
    [Description] NVARCHAR(50) NULL,
    MIST_EOD VARCHAR(10) NULL,
    IsActive BIT NOT NULL
);
GO
IF OBJECT_ID('dbo.SS_Branch_CASHDEVICE_TerminalNo') IS NULL
CREATE TABLE dbo.SS_Branch_CASHDEVICE_TerminalNo (
    CASHDEVICETerminalNoId INT NOT NULL PRIMARY KEY,
    SSBranchId INT NOT NULL,
    CASHDEVICE_TerminalNo NVARCHAR(12) NULL,
    [Description] NVARCHAR(50) NULL,
    MIST_EOD VARCHAR(10) NULL
);
GO
IF OBJECT_ID('dbo.SS_ReconCriteriaGroup') IS NULL
CREATE TABLE dbo.SS_ReconCriteriaGroup (
    CriteriaGroupId INT NOT NULL PRIMARY KEY,
    Code NVARCHAR(25) NOT NULL,
    [Description] NVARCHAR(50) NULL,
    SQLTableName NVARCHAR(100) NULL,
    SQLFieldName NVARCHAR(50) NULL,
    ReconFieldName NVARCHAR(50) NULL,
    DataType NVARCHAR(50) NULL,
    IsActive BIT NULL,
    MIST_EOD VARCHAR(10) NULL
);
GO
IF OBJECT_ID('dbo.SS_ReconCriteria') IS NULL
CREATE TABLE dbo.SS_ReconCriteria (
    SSBranchId INT NOT NULL,
    CriteriaId INT NOT NULL,
    CriteriaGroupId INT NOT NULL,
    Code NVARCHAR(25) NOT NULL,
    [Description] NVARCHAR(50) NULL,
    Reference NVARCHAR(50) NOT NULL,
    ReferenceStartPosition INT NOT NULL,
    ReferenceEndPosition INT NOT NULL,
    ReconStartPosition INT NOT NULL,
    ReconEndPosition INT NOT NULL,
    IsAutoReconcile BIT NOT NULL,
    MIST_EOD VARCHAR(10) NULL,
    PRIMARY KEY (SSBranchId, CriteriaId)
);
GO
IF OBJECT_ID('dbo.BRN_TransactionType') IS NOT NULL AND COL_LENGTH('dbo.BRN_TransactionType', 'AlpNum') IS NULL
    ALTER TABLE dbo.BRN_TransactionType ADD AlpNum NVARCHAR(2) NULL, HighNum INT NULL;
GO
IF OBJECT_ID('dbo.BRN_TransactionType') IS NOT NULL AND COL_LENGTH('dbo.BRN_TransactionType', 'CreateDateTime') IS NULL
    ALTER TABLE dbo.BRN_TransactionType ADD CreateDateTime DATETIME NULL;
GO
IF OBJECT_ID('dbo.BRN_FuelType') IS NOT NULL AND COL_LENGTH('dbo.BRN_FuelType', 'POSIdentifier') IS NULL
    ALTER TABLE dbo.BRN_FuelType ADD POSType NVARCHAR(50) NULL, POSIdentifier NVARCHAR(50) NULL, HandlingFee MONEY NULL, Code CHAR(16) NULL;
GO
IF OBJECT_ID('dbo.BRN_FuelType') IS NOT NULL AND COL_LENGTH('dbo.BRN_FuelType', 'CreateDateTime') IS NULL
    ALTER TABLE dbo.BRN_FuelType ADD CreateDateTime DATETIME NULL;
GO
IF OBJECT_ID('dbo.BRN_Pump') IS NOT NULL AND COL_LENGTH('dbo.BRN_Pump', 'Order') IS NULL
    ALTER TABLE dbo.BRN_Pump ADD [Order] INT NULL, POSType NVARCHAR(50) NULL, POSIdentifier NVARCHAR(50) NULL;
GO
IF OBJECT_ID('dbo.BRN_Pump') IS NOT NULL AND COL_LENGTH('dbo.BRN_Pump', 'CreateDateTime') IS NULL
    ALTER TABLE dbo.BRN_Pump ADD CreateDateTime DATETIME NULL;
