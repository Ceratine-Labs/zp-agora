/* ============================================================================
   [PumpIT] identity tables — LOCAL STUB ONLY.

   The shape, not the estate. agora.vw_LegacyUser names PumpIT across
   databases, and three-part naming is same-instance only: without these two
   tables the view will not CREATE and Core's 01a migration cannot run on the
   local container at all.

   Columns are the ones agora.vw_LegacyUser reads and no others, taken from the
   customer's own procedures rather than guessed — dbo.sp_c#LoadUsers,
   dbo.sp_csGetUser, dbo.sp_c#ChangeUserPassword and dbo.GetManagerPassword all
   name them, and dbo._WorkingSQL selects Autoidx, UserEmailAddress, UserName,
   MobileNumber, VersionNumber, VersionUpgradeDate, isLocked, isLoggedOn,
   LoggedOnDate and LoggedOffDate off SS_Users in one statement.

   `Password varchar(50)` is stubbed because the real column IS varchar(50) and
   the point of usp_Core_MigrateUsers is that it never reads it. A reviewer
   looking at this file should be able to see that the column exists and that
   the procedure does not touch it.

   pumpit-reports.sql already declares a five-column SS_Users, so this file
   WIDENS it rather than redefining it — the same thing that file does to
   SS_Branch, and the reason the stub list in scripts/local-sql.sh is ordered.
   Autoidx is a plain INT here and not an IDENTITY, again to match: a stub is a
   shape for the compiler, and the rows below name their own ids so the
   duplicate/unmatched fixtures are stable to assert against.

   This file is NEVER run against the customer's instance: there the tables
   already exist, with 85 rows in SS_Users.

   The eleven rows exist to exercise the outcomes the migration report is FOR:

     * two rows sharing one email address, differing only in case (duplicate),
     * one row with no email and one with whitespace only (unmatched),
     * one row with an address that has no @ in it (unmatched, other reason),
     * one locked row, which comes across inactive and cannot sign in,
     * one row whose UserTypeId matches nothing in SS_UserType, so the mapping
       falls through to head office and the report says it did,
     * and four ordinary rows, two head office and two branch.

   Run by scripts/local-sql.sh up, after pumpit-reports.sql.
   ============================================================================ */

IF OBJECT_ID('dbo.SS_UserType') IS NULL
CREATE TABLE dbo.SS_UserType (
    UserTypeId INT          NOT NULL,
    UserType   NVARCHAR(50) NOT NULL
);

IF OBJECT_ID('dbo.SS_Users') IS NULL
CREATE TABLE dbo.SS_Users (
    Autoidx          INT          NOT NULL,
    UserName         VARCHAR(50)  NULL,
    UserEmailAddress VARCHAR(100) NULL,
    UserTypeId       INT          NULL,
    isLocked         BIT          NULL
);

/* The columns pumpit-reports.sql did not need. Added rather than assumed, so
   the file is safe on a container that already has the narrower table. */
IF COL_LENGTH('dbo.SS_Users', 'Password') IS NULL ALTER TABLE dbo.SS_Users ADD [Password] VARCHAR(50) NULL;
IF COL_LENGTH('dbo.SS_Users', 'MobileNumber') IS NULL ALTER TABLE dbo.SS_Users ADD MobileNumber VARCHAR(30) NULL;
IF COL_LENGTH('dbo.SS_Users', 'isLoggedOn') IS NULL ALTER TABLE dbo.SS_Users ADD isLoggedOn BIT NULL;
IF COL_LENGTH('dbo.SS_Users', 'LoggedOnDate') IS NULL ALTER TABLE dbo.SS_Users ADD LoggedOnDate DATETIME NULL;
IF COL_LENGTH('dbo.SS_Users', 'LoggedOffDate') IS NULL ALTER TABLE dbo.SS_Users ADD LoggedOffDate DATETIME NULL;
IF COL_LENGTH('dbo.SS_Users', 'VersionNumber') IS NULL ALTER TABLE dbo.SS_Users ADD VersionNumber VARCHAR(20) NULL;
IF COL_LENGTH('dbo.SS_Users', 'VersionUpgradeDate') IS NULL ALTER TABLE dbo.SS_Users ADD VersionUpgradeDate DATETIME NULL;
GO

/* The user-type lookup. The customer's real values are NOT known to this
   checkout — dbo.sp_c#LoadUser joins the table but no dump of its rows exists
   here — so these are plausible fixtures, and agora.vw_LegacyUser derives
   ho/branch from the TEXT rather than from an id it cannot verify. The
   migration report prints the legacy text beside the derived value precisely
   so the reviewing session can check the rule against the real rows. */
IF NOT EXISTS (SELECT 1 FROM dbo.SS_UserType)
INSERT INTO dbo.SS_UserType (UserTypeId, UserType) VALUES
    (1, 'Head Office'),
    (2, 'Branch Manager'),
    (3, 'Site User'),
    (4, 'Administrator');

IF NOT EXISTS (SELECT 1 FROM dbo.SS_Users)
INSERT INTO dbo.SS_Users
    (Autoidx, UserName, UserEmailAddress, [Password], MobileNumber, UserTypeId, isLocked, LoggedOnDate)
VALUES
    -- Four ordinary rows: two head office, two branch.
    (901, 'TEST-Andries Geyser',   'test-andries@zp.invalid', 'plaintext1', '0821110001', 1, 0, '2026-08-31T06:12:00'),
    (902, 'TEST-Chantal Peens',    'test-chantal@zp.invalid', 'plaintext2', '0821110002', 4, 0, '2026-08-30T15:44:00'),
    (903, 'TEST-Sipho Ndlovu',     'test-sipho@zp.invalid',   'plaintext3', '0821110003', 2, 0, '2026-08-29T07:01:00'),
    (904, 'TEST-Thandi Mkhize',    'test-thandi@zp.invalid',  'plaintext4', '0821110004', 3, 0, NULL),

    -- Two rows sharing one address, differing only in case. The lower Autoidx
    -- wins; the other is reported and skipped, because (BranchId,
    -- EmailAddress) is unique and the instance collates case-insensitively.
    (905, 'TEST-Shared Mailbox A', 'test-shared@zp.invalid',  'plaintext5', NULL,         1, 0, '2026-08-28T09:00:00'),
    (906, 'TEST-Shared Mailbox B', 'TEST-Shared@zp.invalid',  'plaintext6', NULL,         1, 0, '2026-08-27T09:00:00'),

    -- No address at all, and whitespace only. Both unmatched.
    (907, 'TEST-No Address',       NULL,                      'plaintext7', NULL,         3, 0, NULL),
    (908, 'TEST-Blank Address',    '   ',                     'plaintext8', NULL,         3, 0, NULL),

    -- An address with no @ in it. Unmatched, but for a different reason, and
    -- the report has to say which.
    (909, 'TEST-Not An Address',   'notanaddress',            'plaintext9', NULL,         1, 0, NULL),

    -- Locked. Comes across inactive and cannot sign in.
    (910, 'TEST-Left The Company', 'test-left@zp.invalid',    'plaintextA', NULL,         2, 1, '2024-02-11T11:20:00'),

    -- A UserTypeId that is not in SS_UserType. The LEFT JOIN keeps the row and
    -- the report flags the unmapped type rather than dropping the person.
    (911, 'TEST-Unknown Type',     'test-unknown@zp.invalid', 'plaintextB', NULL,         9, 0, NULL);

/*
 * SS_UserBranches — which branches a person may see.
 *
 * Added 6 September 2026. This is where head-office-vs-branch actually lives,
 * and the real table was read for the first time that day: 1,189 grants across
 * 86 of the customer's 88 users. Twenty-seven of them hold exactly one branch
 * and are sites; the rest hold between two and all thirty-one and are head
 * office. Two users hold none at all.
 *
 * vw_LegacyUser derives UserType from the COUNT of these rows. It used to
 * derive it from SS_UserType's text, which cannot answer the question — that
 * table is a privilege level (Super User / Standard User / Read Only User /
 * User) and contains neither the word "branch" nor "site", so every user came
 * out as head office. See v1__01c_core_legacy_user_type.
 *
 * The column spelling is the estate's: Userid and Branchid, not UserId and
 * BranchId. It only works locally because the collation is case-insensitive;
 * the stub keeps the real spelling so a query written against it is a query
 * that will run against production.
 */
IF OBJECT_ID('dbo.SS_UserBranches') IS NULL
CREATE TABLE dbo.SS_UserBranches (
    Autoidx  INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    Userid   INT NULL,
    Branchid INT NULL,
    MIST_EOD VARCHAR(10) NULL
);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.SS_UserBranches)
INSERT INTO dbo.SS_UserBranches (Userid, Branchid) VALUES
    -- 901: every branch in the stub -> head office.
    (901, 1), (901, 2), (901, 3), (901, 4),
    -- 902: several -> head office.
    (902, 1), (902, 2), (902, 3),
    -- 903: exactly one -> a branch user. This is the case the whole
    -- derivation exists to get right.
    (903, 2),
    -- 904: exactly one -> a branch user.
    (904, 3),
    -- 905 and 906 share an email; give them different shapes so the
    -- duplicate report is not also a UserType tie.
    (905, 1), (905, 2),
    (906, 1),
    -- 910 and 911 have grants; 907, 908 and 909 have NONE, so their
    -- UserType comes out NULL rather than being guessed.
    (910, 1), (910, 2), (910, 3), (910, 4),
    (911, 4);
GO
