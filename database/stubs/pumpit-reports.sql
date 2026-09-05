/* ============================================================================
   [PumpIT] reporting tables — LOCAL STUB ONLY.

   The shape, not the estate. Agora lives in its own database now, so every
   agora.vw_* view names PumpIT across databases, and three-part naming is
   same-instance only: without these tables the views will not CREATE and the
   Reports procedures cannot be deployed locally at all.

   Generated from the live instance on 4 Sep 2026 — every type here is the type
   the customer's column actually has, not one that looked close. Only the
   columns the agora.vw_* views enumerate are present; a column that is not in
   a view is not in the stub, which is the cheapest way to keep the two honest
   about each other.

   Two deviations from live, both deliberate:

     * `ntext` becomes NVARCHAR(MAX). ntext is deprecated, cannot be compared
       or sorted, and every view already CONVERTs it — stubbing it as ntext
       would make the local database the only place the conversion matters.
     * Nothing is keyed or indexed. This is a shape for the compiler, not a
       performance rehearsal; a plan measured here would be a lie about a
       249 GB production database.

   This file is NEVER run against the customer's instance. There the tables
   already exist, with 7.1 million stock recon lines and 378,395 pump readings
   between them.

   Run by scripts/local-sql.sh up, after pumpit-recon.sql.
   ============================================================================ */

IF OBJECT_ID('dbo.BRN_DayEnd') IS NULL
CREATE TABLE dbo.BRN_DayEnd (
    SSBranchId                       INT              NOT NULL,
    DayEndDate                       DATETIME         NOT NULL,
    DayEndTypeNo                     INT              NOT NULL,
    FuelTO                           MONEY            NULL,
    QShopTO                          MONEY            NULL,
    LottoTO                          MONEY            NULL,
    VVTO                             MONEY            NULL,
    OilTO                            MONEY            NULL,
    FastFoodTO                       MONEY            NULL,
    CarWashTO                        MONEY            NULL,
    OtherTO                          MONEY            NULL,
    DayEndTO                         MONEY            NULL,
    DayEndNo                         NVARCHAR(50)     NOT NULL,
    Imported                         BIT              NOT NULL,
    CreateDateTime                   DATETIME         NULL
);

IF OBJECT_ID('dbo.BRN_DayEndType') IS NULL
CREATE TABLE dbo.BRN_DayEndType (
    SSBranchId                       INT              NOT NULL,
    DayEndTypeNo                     INT              NOT NULL,
    DayEndTypeDescription            NVARCHAR(50)     NULL
);

IF OBJECT_ID('dbo.RCN_ReconImports') IS NULL
CREATE TABLE dbo.RCN_ReconImports (
    SSBranchId                       INT              NOT NULL,
    ReconDate                        DATETIME         NOT NULL,
    DayBalance                       BIT              NOT NULL,
    ShortsConfirmed                  BIT              NOT NULL,
    SiteClosedOff                    BIT              NOT NULL,
    ABSARecon                        BIT              NOT NULL,
    CBRecon                          BIT              NOT NULL,
    FNBRecon                         BIT              NOT NULL,
    DepositaRecon                    BIT              NOT NULL,
    SmartATMRecon                    BIT              NOT NULL,
    DirectDepositsRecon              BIT              NOT NULL,
    HOImport                         BIT              NOT NULL,
    PastelImport                     BIT              NOT NULL,
    ReadyToImport                    BIT              NOT NULL,
    CashierShort                     MONEY            NULL,
    PumpShort                        MONEY            NULL,
    ZReading                         MONEY            NULL,
    TOIncl                           MONEY            NULL,
    TotalAmount                      MONEY            NULL,
    EODNo                            NVARCHAR(50)     NULL,
    CreateDateTime                   DATETIME         NULL
);

IF OBJECT_ID('dbo.BRN_PumpReadings') IS NULL
CREATE TABLE dbo.BRN_PumpReadings (
    SSBranchId                       INT              NOT NULL,
    PumpNo                           NVARCHAR(4)      NOT NULL,
    ReadingDate                      DATETIME         NOT NULL,
    OpenReading                      DECIMAL(18,6)    NULL,
    CloseReading                     DECIMAL(18,6)    NULL,
    OpenReadingElectronic            DECIMAL(18,6)    NULL,
    CloseReadingElectronic           DECIMAL(18,6)    NULL,
    POSSales                         DECIMAL(18,6)    NULL,
    IsClocked                        BIT              NOT NULL,
    Imported                         BIT              NOT NULL,
    CreateDateTime                   DATETIME         NULL
);

IF OBJECT_ID('dbo.BRN_Pump') IS NULL
CREATE TABLE dbo.BRN_Pump (
    SSBranchId                       INT              NOT NULL,
    PumpNo                           NVARCHAR(4)      NOT NULL,
    FuelTypeNo                       INT              NOT NULL,
    TankNo                           NVARCHAR(4)      NOT NULL,
    IsActive                         BIT              NOT NULL
);

IF OBJECT_ID('dbo.BRN_FuelInput') IS NULL
CREATE TABLE dbo.BRN_FuelInput (
    SSBranchId                       INT              NOT NULL,
    FuelTypeNo                       INT              NOT NULL,
    FuelDate                         DATETIME         NOT NULL,
    FuelVolume                       DECIMAL(18,6)    NULL,
    FuelDip                          DECIMAL(18,6)    NULL,
    FuelDelivery                     DECIMAL(18,6)    NULL,
    Imported                         BIT              NOT NULL,
    CreateDateTime                   DATETIME         NULL
);

IF OBJECT_ID('dbo.BRN_FuelType') IS NULL
CREATE TABLE dbo.BRN_FuelType (
    SSBranchId                       INT              NOT NULL,
    FuelTypeNo                       INT              NOT NULL,
    FuelTypeDescription              NVARCHAR(35)     NULL,
    CostPrice                        MONEY            NULL,
    SellingPrice                     MONEY            NULL,
    IsFleetCard                      BIT              NOT NULL
);

IF OBJECT_ID('dbo.DBF_P3TRANS_ZREAD') IS NULL
CREATE TABLE dbo.DBF_P3TRANS_ZREAD (
    SSBranchId                       INT              NOT NULL,
    [DATE]                           DATETIME         NOT NULL,
    EOD_CNTR                         CHAR(8)          NOT NULL,
    TERMNUM                          CHAR(3)          NOT NULL,
    LOGFILE                          CHAR(10)         NOT NULL,
    USERID                           CHAR(6)          NOT NULL,
    Sales                            DECIMAL(38,6)    NULL,
    TillNo                           INT              NULL,
    ShiftNo                          INT              NULL,
    EmployeeCode                     NVARCHAR(11)     NULL,
    isCaptured                       BIT              NULL,
    MIST_EOD                         VARCHAR(10)      NULL
);

IF OBJECT_ID('dbo.BRN_Tills') IS NULL
CREATE TABLE dbo.BRN_Tills (
    SSBranchId                       INT              NOT NULL,
    TillNo                           INT              NOT NULL,
    TillDescription                  NVARCHAR(30)     NULL,
    [Location]                       NVARCHAR(10)     NULL
);

IF OBJECT_ID('dbo.BRN_Shifts') IS NULL
CREATE TABLE dbo.BRN_Shifts (
    SSBranchId                       INT              NOT NULL,
    ShiftNo                          INT              NOT NULL,
    ShiftDescription                 NVARCHAR(20)     NULL,
    IsActive                         BIT              NOT NULL
);

IF OBJECT_ID('dbo.BRN_Employee') IS NULL
CREATE TABLE dbo.BRN_Employee (
    SSBranchId                       INT              NOT NULL,
    EmployeeCode                     NVARCHAR(11)     NOT NULL,
    FirstName                        NVARCHAR(35)     NULL,
    Surname                          NVARCHAR(20)     NOT NULL,
    NickName                         NVARCHAR(35)     NULL,
    IsActive                         BIT              NOT NULL,
    IsPumpAttendant                  BIT              NOT NULL,
    PostNo                           SMALLINT         NOT NULL
);

IF OBJECT_ID('dbo.BRN_DailyBanking') IS NULL
CREATE TABLE dbo.BRN_DailyBanking (
    SSBranchId                       INT              NOT NULL,
    TransactionDate                  DATETIME         NOT NULL,
    ShiftNo                          INT              NOT NULL,
    TillNo                           INT              NOT NULL,
    EmployeeCode                     NVARCHAR(11)     NOT NULL,
    ClosingBalance                   MONEY            NULL,
    CreditCardAmount                 MONEY            NULL,
    CardAmount1                      MONEY            NULL,
    CardAmount2                      MONEY            NULL,
    EFuelAmount                      MONEY            NULL,
    DirectDepositAmount              MONEY            NULL,
    EmployeeAmount                   MONEY            NULL,
    Note                             NVARCHAR(MAX)    NULL,
    DayEndNo                         NVARCHAR(50)     NULL,
    Posted                           BIT              NOT NULL,
    Imported                         BIT              NOT NULL,
    CreateDateTime                   DATETIME         NULL
);

IF OBJECT_ID('dbo.BRN_DailyBankingEmployees') IS NULL
CREATE TABLE dbo.BRN_DailyBankingEmployees (
    SSBranchId                       INT              NOT NULL,
    TransactionDate                  DATETIME         NOT NULL,
    ShiftNo                          INT              NOT NULL,
    TillNo                           INT              NOT NULL,
    EmployeeCode                     NVARCHAR(11)     NOT NULL,
    AmountShort                      MONEY            NULL,
    Reason                           NVARCHAR(MAX)    NULL,
    Note                             NVARCHAR(MAX)    NULL,
    StaffShortsNo                    INT              NULL
);

IF OBJECT_ID('dbo.BRN_StaffShorts') IS NULL
CREATE TABLE dbo.BRN_StaffShorts (
    SSBranchId                       INT              NOT NULL,
    StaffShortsNo                    INT              NOT NULL,
    EmployeeCode                     NVARCHAR(11)     NOT NULL,
    AmountShort                      MONEY            NULL,
    Note                             NVARCHAR(MAX)    NULL,
    Reason                           NVARCHAR(MAX)    NULL,
    TransactionDate                  DATETIME         NULL,
    CreatedByUserId                  INT              NULL,
    Approved                         BIT              NOT NULL,
    ApprovedDate                     DATETIME         NULL,
    ApprovedByUserId                 INT              NULL,
    IsDeclined                       BIT              NOT NULL,
    DeclinedReason                   NVARCHAR(50)     NULL,
    DeclinedByUserId                 INT              NOT NULL,
    DeclinedDate                     DATETIME         NULL,
    CreateDateTime                   DATETIME         NULL
);

IF OBJECT_ID('dbo.BRN_Transaction') IS NULL
CREATE TABLE dbo.BRN_Transaction (
    SSBranchId                       INT              NOT NULL,
    TransactionNo                    NVARCHAR(15)     NOT NULL,
    TypeCode                         NVARCHAR(2)      NOT NULL,
    SupplierCode                     NVARCHAR(8)      NOT NULL,
    PurchaseRequestDate              DATETIME         NULL,
    QuoteNo                          NVARCHAR(30)     NOT NULL,
    ShiftNo                          INT              NULL,
    EmailAddress                     NVARCHAR(100)    NULL,
    Approved                         BIT              NOT NULL,
    ApprovedByUserId                 INT              NULL,
    ApprovedDate                     DATETIME         NULL,
    IsDeclined                       BIT              NOT NULL,
    DeclinedByUserId                 INT              NULL,
    DeclinedDate                     DATETIME         NULL,
    DeclinedReason                   NVARCHAR(50)     NULL,
    Posted                           BIT              NOT NULL,
    CreateDateTime                   DATETIME         NULL
);

IF OBJECT_ID('dbo.BRN_TransactionLine') IS NULL
CREATE TABLE dbo.BRN_TransactionLine (
    SSBranchId                       INT              NOT NULL,
    TransactionNo                    NVARCHAR(15)     NOT NULL,
    TypeCode                         NVARCHAR(2)      NOT NULL,
    ItemNo                           SMALLINT         NOT NULL,
    ExpenseNo                        INT              NOT NULL,
    PurchaseAmount                   MONEY            NULL,
    PurchaseRequestDescription       NVARCHAR(MAX)    NULL,
    PurchaseRequestJustification     NVARCHAR(MAX)    NULL,
    InvoiceNo                        NVARCHAR(75)     NULL,
    InvoiceDate                      DATETIME         NULL,
    IsEmailSent                      BIT              NOT NULL,
    IsApprovedEmailSent              BIT              NOT NULL,
    Posted                           BIT              NOT NULL,
    VATInd                           INT              NULL
);

IF OBJECT_ID('dbo.BRN_TransactionType') IS NULL
CREATE TABLE dbo.BRN_TransactionType (
    SSBranchId                       INT              NOT NULL,
    TypeCode                         NVARCHAR(2)      NOT NULL,
    TypeDescription                  NVARCHAR(50)     NULL
);

IF OBJECT_ID('dbo.BRN_Suppliers') IS NULL
CREATE TABLE dbo.BRN_Suppliers (
    SSBranchId                       INT              NOT NULL,
    SupplierCode                     NVARCHAR(8)      NOT NULL,
    SupplierName                     NVARCHAR(55)     NULL,
    IsCash                           BIT              NOT NULL,
    IsStock                          BIT              NOT NULL,
    isActive                         BIT              NULL
);

IF OBJECT_ID('dbo.BRN_Expenses') IS NULL
CREATE TABLE dbo.BRN_Expenses (
    SSBranchId                       INT              NOT NULL,
    ExpenseNo                        INT              NOT NULL,
    ExpenseDescription               NVARCHAR(60)     NULL,
    GLCode                           NVARCHAR(8)      NULL,
    ApproverUserId                   INT              NOT NULL,
    IsAsset                          BIT              NULL,
    IsActive                         BIT              NULL,
    Vat                              INT              NULL
);

IF OBJECT_ID('dbo.BRN_ApproverUserlevel') IS NULL
CREATE TABLE dbo.BRN_ApproverUserlevel (
    ApproverUserlevelId              INT              NOT NULL,
    ApproverUserLevel                INT              NOT NULL
);

IF OBJECT_ID('dbo.SS_Users') IS NULL
CREATE TABLE dbo.SS_Users (
    Autoidx                          INT              NOT NULL,
    UserName                         VARCHAR(50)      NULL,
    UserEmailAddress                 VARCHAR(100)     NULL,
    UserTypeId                       INT              NOT NULL,
    isLocked                         BIT              NULL
);

IF OBJECT_ID('dbo.STK_StockRecon') IS NULL
CREATE TABLE dbo.STK_StockRecon (
    SSBranchId                       INT              NOT NULL,
    TransactionDate                  DATETIME         NOT NULL,
    ShiftNo                          INT              NOT NULL,
    AreaNo                           INT              NOT NULL,
    Imported                         BIT              NOT NULL,
    CreateDateTime                   DATETIME         NULL
);

IF OBJECT_ID('dbo.STK_StockReconLine') IS NULL
CREATE TABLE dbo.STK_StockReconLine (
    SSBranchId                       INT              NOT NULL,
    TransactionDate                  DATETIME         NOT NULL,
    ShiftNo                          INT              NOT NULL,
    AreaNo                           INT              NOT NULL,
    StockItemNo                      NVARCHAR(5)      NOT NULL,
    SellPrice                        MONEY            NULL,
    QtyOpen_Original                 FLOAT            NOT NULL,
    QtyIssued_Original               FLOAT            NOT NULL,
    QtyClose_Original                FLOAT            NOT NULL,
    QtyComputer_Original             FLOAT            NOT NULL,
    QtyOpen                          FLOAT            NULL,
    QtyIssued                        FLOAT            NULL,
    QtyClose                         FLOAT            NULL,
    QtyComputer                      FLOAT            NULL
);

IF OBJECT_ID('dbo.STK_StockMaster') IS NULL
CREATE TABLE dbo.STK_StockMaster (
    SSBranchId                       INT              NOT NULL,
    StockItemNo                      NVARCHAR(5)      NOT NULL,
    StockItemDescription             NVARCHAR(50)     NULL,
    [Location]                       NVARCHAR(10)     NULL,
    UOMCode                          NVARCHAR(10)     NOT NULL,
    SellingPrice                     MONEY            NULL,
    AreaNo                           INT              NOT NULL,
    QtyVarAllowance                  FLOAT            NOT NULL,
    isMonitoredItem                  BIT              NULL,
    POSCode                          NVARCHAR(50)     NULL
);

IF OBJECT_ID('dbo.STK_Area') IS NULL
CREATE TABLE dbo.STK_Area (
    SSBranchId                       INT              NOT NULL,
    AreaNo                           INT              NOT NULL,
    AreaDescription                  NVARCHAR(50)     NULL,
    AreaGroup                        NVARCHAR(50)     NULL,
    DayShift                         BIT              NOT NULL,
    AfternoonShift                   BIT              NOT NULL,
    NightShift                       BIT              NOT NULL,
    ShowReport                       BIT              NOT NULL,
    IsCaptureWaste                   BIT              NOT NULL
);

IF OBJECT_ID('dbo.STK_StockWasteLine') IS NULL
CREATE TABLE dbo.STK_StockWasteLine (
    SSBranchId                       INT              NOT NULL,
    TransactionDate                  DATETIME         NOT NULL,
    ShiftNo                          INT              NOT NULL,
    AreaNo                           INT              NOT NULL,
    StockItemNo                      NVARCHAR(5)      NOT NULL,
    QtyGoodWaste                     FLOAT            NULL,
    QtyBadWaste                      FLOAT            NULL,
    CreateDateTime                   DATETIME         NULL
);

IF OBJECT_ID('dbo.BRN_UtilityTransaction') IS NULL
CREATE TABLE dbo.BRN_UtilityTransaction (
    UtilityTransactionId             INT              NOT NULL,
    SSBranchId                       INT              NOT NULL,
    TransactionDate                  DATETIME         NOT NULL,
    UtilityType                      NVARCHAR(1)      NOT NULL,
    MeterNo                          NVARCHAR(50)     NOT NULL,
    OpeningReading                   FLOAT            NULL,
    ClosingReading                   FLOAT            NULL,
    PrepaidUnitsPurchased            FLOAT            NULL,
    [Usage]                          FLOAT            NULL,
    CreateDateTime                   DATETIME         NULL
);

IF OBJECT_ID('dbo.BRN_UtilityMeter') IS NULL
CREATE TABLE dbo.BRN_UtilityMeter (
    UtilityMeterId                   INT              NOT NULL,
    SSBranchId                       INT              NOT NULL,
    UtilityType                      NVARCHAR(1)      NOT NULL,
    MeterNo                          NVARCHAR(50)     NOT NULL,
    Description                      NVARCHAR(50)     NULL
);

IF OBJECT_ID('dbo.SS_UtilityType') IS NULL
CREATE TABLE dbo.SS_UtilityType (
    UtilityTypeId                    INT              NOT NULL,
    Code                             NVARCHAR(1)      NOT NULL,
    Description                      NVARCHAR(50)     NULL
);

IF OBJECT_ID('dbo.BRN_POSImportSelection') IS NULL
CREATE TABLE dbo.BRN_POSImportSelection (
    SSBranchId                       INT              NOT NULL,
    POSType                          NVARCHAR(50)     NOT NULL,
    POSImportType                    NVARCHAR(50)     NOT NULL,
    ImportFromDrive                  NVARCHAR(50)     NULL,
    MIST_EOD                         VARCHAR(10)      NULL,
    CreateDateTime                   DATETIME         NULL
);

IF OBJECT_ID('dbo.BRN_DropSafe_ReasonForManualBag') IS NULL
CREATE TABLE dbo.BRN_DropSafe_ReasonForManualBag (
    ReasonForManualBagId             INT              NOT NULL,
    ReasonForManualBag               NVARCHAR(50)     NOT NULL,
    IsRefNoRequired                  BIT              NOT NULL,
    IsActive                         BIT              NULL
);

/* ---------------------------------------------------------------------------
   Three tables the container already has, from scripts/local-sql.sh (SS_Branch)
   and database/stubs/pumpit-recon.sql (the two drop-safe tables). Reports reads
   more of them than those two files knew about, so the extra columns are added
   rather than the tables redefined — whichever file runs first, both modules
   end up with the columns they read.
   --------------------------------------------------------------------------- */

IF COL_LENGTH('dbo.SS_Branch', 'POSType') IS NULL ALTER TABLE dbo.SS_Branch ADD POSType NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.SS_Branch', 'IsPOSImport') IS NULL ALTER TABLE dbo.SS_Branch ADD IsPOSImport BIT NULL;
IF COL_LENGTH('dbo.SS_Branch', 'IsARCHImport') IS NULL ALTER TABLE dbo.SS_Branch ADD IsARCHImport BIT NULL;
IF COL_LENGTH('dbo.SS_Branch', 'IsMSACCESSImport') IS NULL ALTER TABLE dbo.SS_Branch ADD IsMSACCESSImport BIT NULL;
IF COL_LENGTH('dbo.SS_Branch', 'BRN_LastImportDateStamp') IS NULL ALTER TABLE dbo.SS_Branch ADD BRN_LastImportDateStamp DATETIME NULL;
IF COL_LENGTH('dbo.SS_Branch', 'STK_LastImportDateStamp') IS NULL ALTER TABLE dbo.SS_Branch ADD STK_LastImportDateStamp DATETIME NULL;
IF COL_LENGTH('dbo.SS_Branch', 'RCN_LastImportDateStamp') IS NULL ALTER TABLE dbo.SS_Branch ADD RCN_LastImportDateStamp DATETIME NULL;
IF COL_LENGTH('dbo.SS_Branch', 'NAMOS_LastImportDateStamp') IS NULL ALTER TABLE dbo.SS_Branch ADD NAMOS_LastImportDateStamp DATETIME NULL;

IF COL_LENGTH('dbo.BRN_DropSafe', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD SSBranchId INT NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'BagNo') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD BagNo NVARCHAR(20) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'CollectionId') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD CollectionId BIGINT NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'Amount') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD Amount MONEY NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'DropDate') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD DropDate DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'DropTime') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD DropTime NVARCHAR(8) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'CashierCode') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD CashierCode NVARCHAR(11) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'ManagerCode') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD ManagerCode NVARCHAR(11) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'ReasonForManualBagId') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD ReasonForManualBagId INT NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'ReasonIfAmountMoreThanR2000') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD ReasonIfAmountMoreThanR2000 NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'RefNoFaultReported') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD RefNoFaultReported NVARCHAR(20) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'IsSelectedForDailyBanking') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD IsSelectedForDailyBanking BIT NULL;
IF COL_LENGTH('dbo.BRN_DropSafe', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DropSafe ADD CreateDateTime DATETIME NULL;

IF COL_LENGTH('dbo.BRN_DropSafe_Collection', 'SSBranchId') IS NULL ALTER TABLE dbo.BRN_DropSafe_Collection ADD SSBranchId INT NULL;
IF COL_LENGTH('dbo.BRN_DropSafe_Collection', 'CollectionId') IS NULL ALTER TABLE dbo.BRN_DropSafe_Collection ADD CollectionId BIGINT NULL;
IF COL_LENGTH('dbo.BRN_DropSafe_Collection', 'DBagNo') IS NULL ALTER TABLE dbo.BRN_DropSafe_Collection ADD DBagNo NVARCHAR(20) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe_Collection', 'CollectionDate') IS NULL ALTER TABLE dbo.BRN_DropSafe_Collection ADD CollectionDate DATETIME NULL;
IF COL_LENGTH('dbo.BRN_DropSafe_Collection', 'CollectionTime') IS NULL ALTER TABLE dbo.BRN_DropSafe_Collection ADD CollectionTime NVARCHAR(8) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe_Collection', 'ManagerCode') IS NULL ALTER TABLE dbo.BRN_DropSafe_Collection ADD ManagerCode NVARCHAR(10) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe_Collection', 'SecurityName') IS NULL ALTER TABLE dbo.BRN_DropSafe_Collection ADD SecurityName NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.BRN_DropSafe_Collection', 'TotalAmount') IS NULL ALTER TABLE dbo.BRN_DropSafe_Collection ADD TotalAmount MONEY NULL;
IF COL_LENGTH('dbo.BRN_DropSafe_Collection', 'TotalNoOfBags') IS NULL ALTER TABLE dbo.BRN_DropSafe_Collection ADD TotalNoOfBags FLOAT NULL;
IF COL_LENGTH('dbo.BRN_DropSafe_Collection', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_DropSafe_Collection ADD CreateDateTime DATETIME NULL;


/* The fuel price HISTORY — one row per price change. BRN_FuelType's own
   CostPrice/SellingPrice are 0 on all 41 live rows, so this is where a
   litre gets a value. Added after usp_Reports_GridFuelInput was measured
   returning R0.00 variance for every branch. */
IF OBJECT_ID('dbo.BRN_FuelPrice') IS NULL
CREATE TABLE dbo.BRN_FuelPrice (
    SSBranchId                       INT              NOT NULL,
    FuelTypeNo                       INT              NOT NULL,
    FuelTypeDescription              NVARCHAR(35)     NOT NULL,
    [Date]                           DATETIME         NOT NULL,
    CostPrice                        DECIMAL(18,6)    NULL,
    SellingPrice                     DECIMAL(18,6)    NOT NULL,
    Margin                           DECIMAL(18,6)    NULL,
    SellingPriceMatrix               DECIMAL(18,6)    NULL,
    IsFleetCard                      BIT              NOT NULL,
    MIST_EOD                         VARCHAR(10)      NULL
);
