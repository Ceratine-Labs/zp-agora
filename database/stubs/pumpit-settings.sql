/* ============================================================================
   [PumpIT] settings sources — LOCAL STUB ONLY.

   The shape, not the estate. Core's v1__01k creates vw_LegacySysDefault and
   vw_LegacyBranchConfig naming these two tables across databases, and
   three-part naming is same-instance only: without them the views will not
   CREATE on the local container.

   Column names and types are the live ones (INFORMATION_SCHEMA.COLUMNS on the
   customer's instance, 12 Nov 2026). SS_SysDefaults is limited to its key and
   the eight columns vw_LegacySysDefault reads: the live table also holds SMTP,
   FTP and upgrade credentials, which Agora never reads and which have no
   business in a stub. BRN_Config is whole (98 rows live, branches 1-14, all
   created 5 Apr 2017).

   Rows: none. tests/Feature/Core/SettingsTest writes its own TEST fixture and
   removes it.

   This file is NEVER run against the customer's instance: there the tables
   already exist.
   ============================================================================ */

IF OBJECT_ID('dbo.SS_SysDefaults') IS NULL
CREATE TABLE dbo.SS_SysDefaults (
    Autoidx INT IDENTITY(1, 1) NOT NULL CONSTRAINT PK_SS_SysDefaults PRIMARY KEY,
    SupplierInvoiceNoOfDays INT NULL,
    CashBagsDaysTOADD INT NOT NULL CONSTRAINT DF_SS_SysDefaults_CashBags DEFAULT 0,
    SmartATMDaysTOADD INT NOT NULL CONSTRAINT DF_SS_SysDefaults_SmartATM DEFAULT 0,
    CashDevicesDaysTOADD INT NOT NULL CONSTRAINT DF_SS_SysDefaults_CashDevices DEFAULT 0,
    ABSADaysTOADD INT NOT NULL CONSTRAINT DF_SS_SysDefaults_ABSA DEFAULT 0,
    ZapperDaysTOADD INT NOT NULL CONSTRAINT DF_SS_SysDefaults_Zapper DEFAULT 0,
    YumbiDaysTOADD INT NOT NULL CONSTRAINT DF_SS_SysDefaults_Yumbi DEFAULT 0,
    InfinityDaysTOADD INT NOT NULL CONSTRAINT DF_SS_SysDefaults_Infinity DEFAULT 0
);

IF OBJECT_ID('dbo.BRN_Config') IS NULL
CREATE TABLE dbo.BRN_Config (
    SSBranchId INT NOT NULL,
    ConfigDescription NVARCHAR(50) NOT NULL,
    ValueType NVARCHAR(50) NULL,
    IValue FLOAT NULL,
    SValue NVARCHAR(255) NULL,
    BValue BIT NOT NULL,
    [Description] NTEXT NULL,
    CreateDateTime DATETIME NULL,
    MIST_EOD VARCHAR(10) NULL
);
