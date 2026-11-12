/* ============================================================================
   [PumpIT] people sources — LOCAL STUB ONLY.

   The shape, not the estate. People's v1__03 creates three views naming these
   tables across databases (vw_PeopleEmployee, vw_PeoplePost,
   vw_PeopleEmployeeArea), and three-part naming is same-instance only: without
   them the views will not CREATE on the local container.

   BRN_Employee and BRN_EmployeePost already exist in the stub (pumpit-reports
   and pumpit-cash); this widens BRN_Employee with the columns the employee
   master reads and adds BRN_EmployeeArea. Column names and types are the live
   ones (INFORMATION_SCHEMA.COLUMNS on the customer's instance, 12 Nov 2026).
   Every added column is NULLable or defaulted, because other modules' tests
   insert employees with only the eight columns pumpit-reports.sql declares.

   Deliberately NOT carried: IDNo, Password, the bank, tax, medical-aid and
   pension numbers, addresses, birth date and photo. The People views do not
   read them, and a local copy of the estate has no business holding them.

   Runs after pumpit-reports.sql and pumpit-cash.sql (scripts/local-sql.sh).
   This file is NEVER run against the customer's instance: there the tables
   already exist.
   ============================================================================ */

IF OBJECT_ID('dbo.BRN_Employee') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.BRN_Employee', 'Title') IS NULL ALTER TABLE dbo.BRN_Employee ADD Title NVARCHAR(4) NULL;
    IF COL_LENGTH('dbo.BRN_Employee', 'Initials') IS NULL ALTER TABLE dbo.BRN_Employee ADD Initials NVARCHAR(5) NULL;
    IF COL_LENGTH('dbo.BRN_Employee', 'DateJoined') IS NULL ALTER TABLE dbo.BRN_Employee ADD DateJoined DATETIME NULL;
    IF COL_LENGTH('dbo.BRN_Employee', 'DateDepart') IS NULL ALTER TABLE dbo.BRN_Employee ADD DateDepart DATETIME NULL;
    IF COL_LENGTH('dbo.BRN_Employee', 'CashDeviceCode') IS NULL ALTER TABLE dbo.BRN_Employee ADD CashDeviceCode NVARCHAR(15) NULL;
    IF COL_LENGTH('dbo.BRN_Employee', 'DepositorName') IS NULL ALTER TABLE dbo.BRN_Employee ADD DepositorName NVARCHAR(50) NULL;
    IF COL_LENGTH('dbo.BRN_Employee', 'CanSignIn') IS NULL ALTER TABLE dbo.BRN_Employee ADD CanSignIn BIT NULL;
    IF COL_LENGTH('dbo.BRN_Employee', 'CanAccessManager') IS NULL ALTER TABLE dbo.BRN_Employee ADD CanAccessManager BIT NULL;
    IF COL_LENGTH('dbo.BRN_Employee', 'CanAccessSetup') IS NULL ALTER TABLE dbo.BRN_Employee ADD CanAccessSetup BIT NULL;
    IF COL_LENGTH('dbo.BRN_Employee', 'CreateDateTime') IS NULL ALTER TABLE dbo.BRN_Employee ADD CreateDateTime DATETIME NULL;
END;
GO

-- Which counting areas a person may be signed on to. Keyed by Autoidx on the
-- customer's instance (an IDENTITY), 3,792 rows, no duplicate
-- (BranchId, EmployeeCode, AreaNo) — read 12 Nov 2026. Note the branch column
-- is BranchId here, not SSBranchId as everywhere else in the estate.
IF OBJECT_ID('dbo.BRN_EmployeeArea') IS NULL
CREATE TABLE dbo.BRN_EmployeeArea (
    Autoidx       INT IDENTITY(1, 1) NOT NULL,
    BranchId      INT            NOT NULL,
    EmployeeCode  NVARCHAR(11)   NULL,
    AreaNo        INT            NOT NULL,
    MIST_EOD      VARCHAR(10)    NULL,
    CONSTRAINT [PK_BRN_EmployeeArea] PRIMARY KEY CLUSTERED ([Autoidx])
);
GO
