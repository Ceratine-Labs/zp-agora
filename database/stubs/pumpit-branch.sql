/* ============================================================================
   [PumpIT] branch master sources — LOCAL STUB ONLY.

   The shape, not the estate. Core's v1__01j creates four views naming these
   tables across databases (vw_LegacyBranch, vw_LegacyBranchGrouping,
   vw_ProfitCentre, vw_LegacyBranchProfitCentre), and three-part naming is
   same-instance only: without them the views will not CREATE on the local
   container.

   Column names and types are the live ones (sys.columns on the customer's
   instance, 12 Nov 2026), limited to what those views read plus the rest of
   SDK_CategoryGLCode, which T022 will want. SS_Branch itself comes from
   scripts/local-sql.sh and is widened with POSType / IsPOSImport by
   pumpit-reports.sql; this file widens it again only if that file has not run.

   Fill with real rows from the customer's instance (a read of ~870 rows):
       php artisan agora:sync-local-branches

   This file is NEVER run against the customer's instance: there the tables
   already exist.
   ============================================================================ */

IF COL_LENGTH('dbo.SS_Branch', 'POSType') IS NULL ALTER TABLE dbo.SS_Branch ADD POSType NVARCHAR(20) NULL;
IF COL_LENGTH('dbo.SS_Branch', 'IsPOSImport') IS NULL ALTER TABLE dbo.SS_Branch ADD IsPOSImport BIT NULL;

IF OBJECT_ID('dbo.SS_Region') IS NULL
CREATE TABLE dbo.SS_Region (
    Regionid INT NOT NULL PRIMARY KEY,
    Region NVARCHAR(50) NULL,
    IsActive BIT NULL,
    CreateDateTime DATETIME NULL
);

IF OBJECT_ID('dbo.SS_Brand') IS NULL
CREATE TABLE dbo.SS_Brand (
    Brandid INT NOT NULL PRIMARY KEY,
    BrandName NVARCHAR(50) NULL,
    IsActive BIT NULL,
    CreateDateTime DATETIME NULL
);

IF OBJECT_ID('dbo.SS_Class') IS NULL
CREATE TABLE dbo.SS_Class (
    Classid INT NOT NULL PRIMARY KEY,
    ClassName NVARCHAR(50) NULL,
    IsActive BIT NULL,
    CreateDateTime DATETIME NULL
);

IF OBJECT_ID('dbo.SS_ProfitCentre') IS NULL
CREATE TABLE dbo.SS_ProfitCentre (
    ProfitCentreId INT NOT NULL PRIMARY KEY,
    ProfitCentre NVARCHAR(40) NOT NULL,
    IsActive BIT NOT NULL
);

IF OBJECT_ID('dbo.SDK_CategoryGLCode') IS NULL
CREATE TABLE dbo.SDK_CategoryGLCode (
    SSBranchId INT NULL,
    POSSystemId INT NULL,
    Category VARCHAR(100) NULL,
    StandardCategory VARCHAR(100) NULL,
    SupplierGLCode VARCHAR(50) NULL,
    SalesGLCode VARCHAR(50) NULL,
    ProfitCentre VARCHAR(100) NULL,
    IsActive BIT NULL,
    MINGP FLOAT NULL,
    MAXGP FLOAT NULL,
    MINDaysCover FLOAT NULL,
    MAXDaysCover FLOAT NULL
);
