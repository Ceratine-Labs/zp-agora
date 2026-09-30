/* ============================================================================
   [PumpIT] utility meter tables — LOCAL STUB ONLY.

   The shape, not the estate. Utilities (T056) ADOPTS dbo.BRN_UtilityTransaction:
   the table stays the customer's, Agora reads it through agora.vw_UtilityReading
   and writes it through agora.usp_Utilities_Save and nothing else. That
   procedure is developed and tested against THIS stub only — it is never run
   against the customer's instance to test it.

   WHY THIS FILE EXISTS WHEN pumpit-reports.sql ALREADY CREATES THE TABLE.
   pumpit-reports.sql declares `UtilityTransactionId INT NOT NULL` — a plain
   column, because the Reports module only ever READ it. On the customer's
   instance it is an IDENTITY (measured 27 Sep 2026):

     UtilityTransactionId  int IDENTITY   NOT NULL
     SSBranchId            int            NOT NULL
     TransactionDate       datetime       NOT NULL   always midnight, 0 of 86,732 carry a time
     UtilityType           nvarchar(1)    NOT NULL   SS_UtilityType.Code, A..Z
     MeterNo               nvarchar(50)   NOT NULL   BRN_UtilityMeter.MeterNo — the NAME, not the id
     OpeningReading        float          NULL
     ClosingReading        float          NULL
     PrepaidUnitsPurchased float          NULL
     Usage                 float          NULL
     CreateDateTime        datetime       NULL       DEFAULT (getdate())
     MIST_EOD              varchar(10)    NULL       NULL on every row
     PK_BRN_UtilityTransaction CLUSTERED (UtilityTransactionId, SSBranchId,
                                          TransactionDate, UtilityType, MeterNo)

   The save procedure inserts WITHOUT an id, exactly as the legacy client does,
   so against a stub where the id is a plain NOT NULL column every insert fails.
   A test that passed here by supplying the id would prove nothing about the
   insert that will run in production. So this file makes the stub table the
   customer's shape: if it is missing it is created that way, and if
   pumpit-reports.sql got there first it is REBUILT that way, rows kept.

   NOTE: `agora:sync-local-reports` copies explicit UtilityTransactionId values
   into this table. Against the IDENTITY column that insert needs
   SET IDENTITY_INSERT ON for this one table — reported to the owner of that
   command rather than changed from here.

   The PK is the customer's too, and it does NOT stop two rows for one meter on
   one day: UtilityTransactionId leads it. Live holds four such pairs (branch 21,
   Generator Aksa Power, 1-4 Sep 2026). agora.vw_UtilityReading takes the
   newest of a pair and says how many there were; the stub leaves the door open
   so a test can prove that.

   dbo.BRN_UtilityMeter and dbo.SS_UtilityType are left exactly as
   pumpit-reports.sql made them: Agora reads meters and never writes one.

   This file is NEVER run against the customer's instance.

   Run by scripts/local-sql.sh up, AFTER pumpit-reports.sql. Idempotent: run it
   twice and the second run changes nothing.

     docker exec -i agora-sql /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa \
         -P "$AGORA_DB_PASSWORD" -C -b -d PumpIT -i /dev/stdin < database/stubs/pumpit-utilities.sql
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ---------------------------------------------------------------------------
   1. Missing altogether: create it in the customer's shape.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.BRN_UtilityTransaction') IS NULL
BEGIN
    CREATE TABLE dbo.BRN_UtilityTransaction (
        UtilityTransactionId  INT IDENTITY(1,1) NOT NULL,
        SSBranchId            INT               NOT NULL,
        TransactionDate       DATETIME          NOT NULL,
        UtilityType           NVARCHAR(1)       NOT NULL,
        MeterNo               NVARCHAR(50)      NOT NULL,
        OpeningReading        FLOAT             NULL,
        ClosingReading        FLOAT             NULL,
        PrepaidUnitsPurchased FLOAT             NULL,
        [Usage]               FLOAT             NULL,
        CreateDateTime        DATETIME          NULL CONSTRAINT DF_BRN_UtilityTransaction_CreateDateTime DEFAULT (GETDATE()),
        MIST_EOD              VARCHAR(10)       NULL
    );
END
GO

/* ---------------------------------------------------------------------------
   2. Present but made by pumpit-reports.sql, with a plain id column: rebuild
      it with the IDENTITY, keeping every row and every id.

      A column cannot be altered INTO an identity, so this is the only way.
      Rows synced from live keep their real ids, and the identity is reseeded
      past the highest of them, so a row Agora inserts never collides with
      one the sync brought in.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.BRN_UtilityTransaction') IS NOT NULL
   AND COLUMNPROPERTY(OBJECT_ID('dbo.BRN_UtilityTransaction'), 'UtilityTransactionId', 'IsIdentity') = 0
BEGIN
    IF OBJECT_ID('dbo.BRN_UtilityTransaction__rebuild') IS NOT NULL
        DROP TABLE dbo.BRN_UtilityTransaction__rebuild;

    CREATE TABLE dbo.BRN_UtilityTransaction__rebuild (
        UtilityTransactionId  INT IDENTITY(1,1) NOT NULL,
        SSBranchId            INT               NOT NULL,
        TransactionDate       DATETIME          NOT NULL,
        UtilityType           NVARCHAR(1)       NOT NULL,
        MeterNo               NVARCHAR(50)      NOT NULL,
        OpeningReading        FLOAT             NULL,
        ClosingReading        FLOAT             NULL,
        PrepaidUnitsPurchased FLOAT             NULL,
        [Usage]               FLOAT             NULL,
        CreateDateTime        DATETIME          NULL,
        MIST_EOD              VARCHAR(10)       NULL
    );

    SET IDENTITY_INSERT dbo.BRN_UtilityTransaction__rebuild ON;

    EXEC('
        INSERT INTO dbo.BRN_UtilityTransaction__rebuild
            (UtilityTransactionId, SSBranchId, TransactionDate, UtilityType, MeterNo,
             OpeningReading, ClosingReading, PrepaidUnitsPurchased, [Usage], CreateDateTime)
        SELECT UtilityTransactionId, SSBranchId, TransactionDate, UtilityType, MeterNo,
               OpeningReading, ClosingReading, PrepaidUnitsPurchased, [Usage], CreateDateTime
        FROM dbo.BRN_UtilityTransaction;
    ');

    SET IDENTITY_INSERT dbo.BRN_UtilityTransaction__rebuild OFF;

    DROP TABLE dbo.BRN_UtilityTransaction;
    EXEC sp_rename 'dbo.BRN_UtilityTransaction__rebuild', 'BRN_UtilityTransaction';

    /* Only when rows came across: reseeding an empty table to 0 would make the
       first insert id 0, which the customer's table has never held. */
    DECLARE @top INT = (SELECT MAX(UtilityTransactionId) FROM dbo.BRN_UtilityTransaction);
    IF @top IS NOT NULL
        DBCC CHECKIDENT ('dbo.BRN_UtilityTransaction', RESEED, @top) WITH NO_INFOMSGS;
END
GO

/* ---------------------------------------------------------------------------
   3. The two things either path may still be missing: the default on
      CreateDateTime and the primary key. Named as the customer names them.
   --------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.BRN_UtilityTransaction', 'MIST_EOD') IS NULL
    ALTER TABLE dbo.BRN_UtilityTransaction ADD MIST_EOD VARCHAR(10) NULL;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.default_constraints d
    JOIN sys.columns c ON c.object_id = d.parent_object_id AND c.column_id = d.parent_column_id
    WHERE d.parent_object_id = OBJECT_ID('dbo.BRN_UtilityTransaction') AND c.name = 'CreateDateTime'
)
    ALTER TABLE dbo.BRN_UtilityTransaction
        ADD CONSTRAINT DF_BRN_UtilityTransaction_CreateDateTime DEFAULT (GETDATE()) FOR CreateDateTime;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.key_constraints
    WHERE type = 'PK' AND parent_object_id = OBJECT_ID('dbo.BRN_UtilityTransaction')
)
    ALTER TABLE dbo.BRN_UtilityTransaction
        ADD CONSTRAINT PK_BRN_UtilityTransaction PRIMARY KEY CLUSTERED
            (UtilityTransactionId, SSBranchId, TransactionDate, UtilityType, MeterNo);
GO
