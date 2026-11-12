/* LOCAL ONLY. The fuel source columns verified on 8 November 2026.
   pumpit-reports.sql creates an older BRN_FuelPrice subset. Add its missing
   observation metadata with defaults so existing report fixtures remain valid.
   This file is never a customer migration. */
USE PumpIT;
GO
IF COL_LENGTH('dbo.BRN_FuelPrice', 'S_Date') IS NULL
    ALTER TABLE dbo.BRN_FuelPrice ADD S_Date datetime NOT NULL CONSTRAINT DF_TEST_Fuel_SourceDate DEFAULT ('19000101');
IF COL_LENGTH('dbo.BRN_FuelPrice', 'POSType') IS NULL
    ALTER TABLE dbo.BRN_FuelPrice ADD POSType nvarchar(50) NOT NULL CONSTRAINT DF_TEST_Fuel_POSType DEFAULT ('TEST-POS');
IF COL_LENGTH('dbo.BRN_FuelPrice', 'POSIdentifier') IS NULL
    ALTER TABLE dbo.BRN_FuelPrice ADD POSIdentifier nvarchar(50) NOT NULL CONSTRAINT DF_TEST_Fuel_POSIdentifier DEFAULT ('TEST-POS');
IF COL_LENGTH('dbo.BRN_FuelPrice', 'HandlingFee') IS NULL
    ALTER TABLE dbo.BRN_FuelPrice ADD HandlingFee money NOT NULL CONSTRAINT DF_TEST_Fuel_HandlingFee DEFAULT (0);
IF COL_LENGTH('dbo.BRN_FuelPrice', 'EOD_CNTR') IS NULL
    ALTER TABLE dbo.BRN_FuelPrice ADD EOD_CNTR char(8) NOT NULL CONSTRAINT DF_TEST_Fuel_Eod DEFAULT ('TEST');
IF COL_LENGTH('dbo.BRN_FuelPrice', 'Code') IS NULL
    ALTER TABLE dbo.BRN_FuelPrice ADD Code char(16) NULL;
IF COL_LENGTH('dbo.BRN_FuelPrice', 'SalesVariance') IS NULL
    ALTER TABLE dbo.BRN_FuelPrice ADD SalesVariance decimal(18,6) NULL;
GO
IF OBJECT_ID('dbo.BRN_MonthlyDOEFuelPricingMatrix', 'U') IS NULL
    CREATE TABLE dbo.BRN_MonthlyDOEFuelPricingMatrix (
        SSBranchId int NOT NULL,
        StartDate datetime NOT NULL,
        EndDate datetime NOT NULL,
        FuelTypeNo int NOT NULL,
        FuelTypeDescription nvarchar(35) NULL,
        CostPrice decimal(18,6) NULL,
        SellingPrice decimal(18,6) NULL,
        Margin decimal(18,6) NULL,
        FleetMargin decimal(18,6) NULL,
        MIST_EOD varchar(10) NULL,
        CONSTRAINT PK_TEST_DOEFuelPrice PRIMARY KEY (SSBranchId, StartDate, EndDate, FuelTypeNo)
    );
GO

IF COL_LENGTH('dbo.BRN_MonthlyDOEFuelPricingMatrix', 'MIST_EOD') IS NULL
    ALTER TABLE dbo.BRN_MonthlyDOEFuelPricingMatrix ADD MIST_EOD varchar(10) NULL;
GO
