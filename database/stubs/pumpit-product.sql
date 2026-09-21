/* ============================================================================
   [PumpIT] product-master tables — LOCAL STUB ONLY.

   The shape, not the estate. `agora.vw_StockItem` and its neighbours name
   PumpIT across databases, and three-part naming is same-instance only:
   without these tables the views will not CREATE and the Product procedures
   cannot be deployed locally at all.

   Generated from the live instance on 20 Sep 2026 — every type here is the
   type the customer's column actually has. Only the columns the agora.vw_*
   views enumerate are present; a column that is not in a view is not in the
   stub, which is the cheapest way to keep the two honest about each other.

   Two things to know about what is stubbed here:

     * `dbo.STK_StockMaster` ALREADY EXISTS from pumpit-recon.sql, with ten of
       its twenty-four columns. This file WIDENS it rather than redefining it,
       so this file must run after that one.
     * `dbo.DBF_STDB` is the POS cost and sell file — 252,511 rows live, and
       its primary key is (SSBranchId, CODE, LOCATION), ALL THREE. Joining it
       on branch and code alone turns the 7,447-row stock master into 8,161
       rows, and at 713 items it attaches a different product's cost and
       category: branch 18's code 999 is a Mitchum roll-on under WINBRANCH, a
       prawn salad under AURA and a Clover crate under ARCH. With LOCATION the
       join returns exactly 7,447 rows and needs no dedupe at all.

   Nothing is keyed or indexed. This is a shape for the compiler, not a
   performance rehearsal; a plan measured here would be a lie about a 249 GB
   production database.

   This file is NEVER run against the customer's instance.

   Run by scripts/local-sql.sh up, after pumpit-recon.sql and
   pumpit-reports.sql.
   ============================================================================ */

/* ---------------------------------------------------------------------------
   dbo.STK_StockMaster — the other fourteen columns.

   NVARCHAR lengths are the real ones: StockItemNo is NVARCHAR(5), which is why
   an item number is per branch and small, and why renumbering is not available
   with 6.2 million count lines referencing it.
   --------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.STK_StockMaster', 'PriceType') IS NULL ALTER TABLE dbo.STK_StockMaster ADD PriceType NVARCHAR(20) NOT NULL DEFAULT '';
IF COL_LENGTH('dbo.STK_StockMaster', 'Factor') IS NULL ALTER TABLE dbo.STK_StockMaster ADD Factor FLOAT NOT NULL DEFAULT 0;
IF COL_LENGTH('dbo.STK_StockMaster', 'IssueMultiple') IS NULL ALTER TABLE dbo.STK_StockMaster ADD IssueMultiple FLOAT NOT NULL DEFAULT 0;
IF COL_LENGTH('dbo.STK_StockMaster', 'IssueMultiplePercentage') IS NULL ALTER TABLE dbo.STK_StockMaster ADD IssueMultiplePercentage FLOAT NOT NULL DEFAULT 0;
IF COL_LENGTH('dbo.STK_StockMaster', 'IsDoCloseQtyCalc') IS NULL ALTER TABLE dbo.STK_StockMaster ADD IsDoCloseQtyCalc BIT NOT NULL DEFAULT 0;
IF COL_LENGTH('dbo.STK_StockMaster', 'IsAllowNegativeQtyIssued') IS NULL ALTER TABLE dbo.STK_StockMaster ADD IsAllowNegativeQtyIssued BIT NOT NULL DEFAULT 0;
IF COL_LENGTH('dbo.STK_StockMaster', 'IsAllowNegativeQtyClose') IS NULL ALTER TABLE dbo.STK_StockMaster ADD IsAllowNegativeQtyClose BIT NOT NULL DEFAULT 0;
IF COL_LENGTH('dbo.STK_StockMaster', 'IsStockItemPreProduction') IS NULL ALTER TABLE dbo.STK_StockMaster ADD IsStockItemPreProduction BIT NOT NULL DEFAULT 0;
IF COL_LENGTH('dbo.STK_StockMaster', 'isPreProductionItem') IS NULL ALTER TABLE dbo.STK_StockMaster ADD isPreProductionItem BIT NOT NULL DEFAULT 0;
IF COL_LENGTH('dbo.STK_StockMaster', 'PreProductionTypeNo') IS NULL ALTER TABLE dbo.STK_StockMaster ADD PreProductionTypeNo INT NOT NULL DEFAULT 0;
IF COL_LENGTH('dbo.STK_StockMaster', 'Ratio') IS NULL ALTER TABLE dbo.STK_StockMaster ADD Ratio FLOAT NOT NULL DEFAULT 0;
IF COL_LENGTH('dbo.STK_StockMaster', 'ProduceLimitPercentage') IS NULL ALTER TABLE dbo.STK_StockMaster ADD ProduceLimitPercentage FLOAT NOT NULL DEFAULT 0;
IF COL_LENGTH('dbo.STK_StockMaster', 'CreateDateTime') IS NULL ALTER TABLE dbo.STK_StockMaster ADD CreateDateTime DATETIME NULL;

/* ---------------------------------------------------------------------------
   dbo.STK_StockReconLine — one more column.

   CreateDateTime is when the count line was WRITTEN, as against TransactionDate
   which is the day it counts for. The two are days apart in practice: on
   20 September the newest count date was the 19th while its rows were still
   arriving at 19:44. That is what `agora:refresh-count-stats --if-stale` reads
   to decide whether anything has landed since the last rebuild, so without it
   the scheduled form cannot run at all.
   --------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.STK_StockReconLine', 'CreateDateTime') IS NULL
    ALTER TABLE dbo.STK_StockReconLine ADD CreateDateTime DATETIME NULL;

/* ---------------------------------------------------------------------------
   dbo.STK_AreaGroup — six rows live, carrying the monthly and daily loss
   grace per group of counting areas. STK_Area.AreaGroup joins to it by
   DESCRIPTION, not by id, which is the legacy estate being itself.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.STK_AreaGroup') IS NULL
CREATE TABLE dbo.STK_AreaGroup (
    AreaGroupId                      INT              NOT NULL,
    AreaGroupDescription             NVARCHAR(50)     NULL,
    LossGraceMonthly                 MONEY            NOT NULL,
    LossGraceDaily                   MONEY            NOT NULL
);

/* ---------------------------------------------------------------------------
   dbo.STK_StockMasterCritical — the critical-line list, 1,895 rows live.

   Keyed on (SSBranchId, Code, Location) where Code is the POS CODE, not the
   stock item number, and both Code and Location are CHAR and therefore
   blank-padded. The join back to STK_StockMaster is POSCode + Location.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.STK_StockMasterCritical') IS NULL
CREATE TABLE dbo.STK_StockMasterCritical (
    SSBranchId                       INT              NOT NULL,
    Code                             CHAR(16)         NOT NULL,
    [Location]                       CHAR(10)         NOT NULL,
    [Desc]                           CHAR(50)         NULL,
    CAT                              CHAR(20)         NULL,
    IsActive                         BIT              NOT NULL
);

/* ---------------------------------------------------------------------------
   dbo.DBF_STDB — cost, sell, quantity on hand, category and last-sold date,
   per branch per POS code. The stock master carries none of these.

   LOCATION is the POS system, the same six names STK_StockMaster.Location
   carries, and it is the third column of this table's primary key. Leaving it
   out of a join is the difference between 7,447 rows and 8,161 wrong ones.

   CODE is CHAR(16) and LOCATION CHAR(10) while the master's columns are
   NVARCHAR: the equality works only because SQL Server ignores trailing
   spaces when comparing. A LEN(), a CONCAT key or a client-side match will
   not, and will drop rows.

   L_SOLD is a DATE (last sold), M_SOLD is a QUANTITY (month sold). They read
   like a pair and are not one.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.DBF_STDB') IS NULL
CREATE TABLE dbo.DBF_STDB (
    SSBranchId                       INT              NOT NULL,
    CODE                             CHAR(16)         NOT NULL,
    LOCATION                         CHAR(10)         NOT NULL,
    [DESC]                           CHAR(50)         NULL,
    CAT                              CHAR(20)         NULL,
    STDCOST                          DECIMAL(18, 6)   NULL,
    STDSELL                          DECIMAL(18, 6)   NULL,
    QTY                              DECIMAL(18, 6)   NULL,
    M_SOLD                           DECIMAL(18, 6)   NULL,
    L_SOLD                           DATETIME         NULL,
    PACKSIZE                         DECIMAL(18, 6)   NULL,
    VATCODE                          CHAR(1)          NULL
);

/* ---------------------------------------------------------------------------
   dbo.BRN_POSVirtualStockItems — airtime, electricity, lotto and vouchers.
   14,941 rows live. Dated data with variance columns, NOT a master: the key
   is (SSBranchId, TransactionDate, ReconItemNo, StockItemNo).
   --------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.BRN_POSVirtualStockItems') IS NULL
CREATE TABLE dbo.BRN_POSVirtualStockItems (
    SSBranchId                       INT              NOT NULL,
    TransactionDate                  DATETIME         NOT NULL,
    ReconItemNo                      INT              NOT NULL,
    StockItemNo                      NVARCHAR(5)      NOT NULL,
    ComputerValue                    FLOAT            NULL,
    ActualPOSValue                   FLOAT            NULL,
    Computer_ActualPOSValue          FLOAT            NULL,
    IssuesValue                      FLOAT            NULL,
    SlipsValue                       FLOAT            NULL,
    Issues_SlipsValue                FLOAT            NULL,
    CreateDateTime                   DATETIME         NULL
);

/* ---------------------------------------------------------------------------
   dbo.BRN_MonthlyDOEFuelPricingMatrix — the Department of Energy price
   periods per branch and grade, against which BRN_FuelPrice is read.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.BRN_MonthlyDOEFuelPricingMatrix') IS NULL
CREATE TABLE dbo.BRN_MonthlyDOEFuelPricingMatrix (
    SSBranchId                       INT              NOT NULL,
    StartDate                        DATETIME         NOT NULL,
    EndDate                          DATETIME         NOT NULL,
    FuelTypeNo                       INT              NOT NULL,
    FuelTypeDescription              NVARCHAR(70)     NULL,
    CostPrice                        DECIMAL(18, 6)   NULL,
    SellingPrice                     DECIMAL(18, 6)   NULL,
    Margin                           DECIMAL(18, 6)   NULL,
    FleetMargin                      DECIMAL(18, 6)   NULL
);
