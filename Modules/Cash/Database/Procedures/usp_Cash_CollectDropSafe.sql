/* ============================================================================
   agora.usp_Cash_CollectDropSafe

   Hand bags from the drop safe to the security company — PumpIT's drop safe
   collection, ported. The bags leave the safe: each one's CollectionId names
   the new collection, which is the whole of "collected" in PumpIT's data.

   ** ONE OF THE PROCEDURES IN AGORA THAT WRITE TO THE CUSTOMER'S ESTATE.
   ** Approved by Ryan on 27 Sep 2026 (PM decisions 01a0e22a-97be /
   ** 01a0e22a-9aa9: drop safe captured in Agora writes the legacy
   ** BRN_DropSafe tables). What it writes, and nothing else:
   **
   **   BRN_DropSafe_Collection  INSERT one row — SSBranchId, DBagNo,
   **                            ManagerCode, SecurityName, CollectionDate,
   **                            CollectionTime, TotalNoOfBags, TotalAmount,
   **                            CreateDateTime. CollectionId is the table's own
   **                            IDENTITY, read back through OUTPUT.
   **   BRN_DropSafe             UPDATE CollectionId, from 0 to the new id, on
   **                            each bag collected — and nothing else on it
   **
   ** Both, and agora.DropSafeEvent with exactly what was written, in ONE
   ** transaction. Read this header before changing a line of it.

   THE PORT. PumpIT has no procedure for this either: its client writes both
   tables. Read off the customer's rows (27 Sep 2026, 4,450 collections):
     CollectionId    IDENTITY; there is no SS_UniqueNumber section for it
     DBagNo          the security company's bag, typed — the bank statement
                     quotes it, and recon matches the deposit to the bags by it
     CollectionDate  the day, at midnight; CollectionTime 'hh:mm:ss' text
     TotalNoOfBags   the bags collected (float, as PumpIT has it) and
     TotalAmount     their amounts summed: both agree with the bags on 4,402 of
                     4,450 collections (833 of the 836 made in 2026) — the rest
                     are bags deleted or edited after the collection. Here both
                     are counted from the bags, never typed.
     ManagerCode     the manager handing over (an employee at the site, 10
                     characters in this table); SecurityName the guard, text
     CreateDateTime  GETDATE(); MIST_EOD left NULL (every row)
   A bag already on a cashup is collected like any other (the legacy does it
   every day). NOT PORTED, BY DECISION: the MIST_Audit.dbo.ActionLog line
   (MasterActionid 49, "PumpIT Drop Safe · Collection Process") —
   agora.DropSafeEvent is the record (Ryan, 27 Sep 2026).

   ONE SECURITY-COMPANY BAG PER COLLECTION AT A SITE (Ryan, 27 Sep 2026). The
   legacy lets a DBagNo be used again — 477 of the customer's 4,450 collections
   repeat one at the same site, most of them junk ('3900' 32 times) — and bank
   recon matches the deposit to the bags by it, so a repeat makes that match
   ambiguous. A DBagNo already used at the site is refused, naming the earlier
   collection and its day. The existing repeats are left as they are.

   THE RULES, in order:
     · @WriteMode must be 'live' (cash.dropsafe.write_mode) — the second lock.
     · A site Agora knows; at least one bag (a JSON array of bag numbers);
       the security company's bag number (up to 20 characters); the manager
       (an employee at the site, up to 10 characters — PumpIT's column); the
       guard's name (up to 50); a day no later than today; a time.
     · No enabled trigger on BRN_DropSafe or BRN_DropSafe_Collection.
     · The security company's bag number is not already used by a collection
       at the site (checked under lock, so two presses cannot both take it).
     · Every bag is at the site and still in the safe (CollectionId 0), taken
       under lock, so a bag is never collected twice.

   Returns the writer status row (Ok, Code, Message, Id = the CollectionId)
   with CollectionId, DBagNo, Bags, Amount, InSafeBags and InSafeAmount (what
   is left in the site's safe) and SaveRef.

   Refusals: CASH_COLLECT_USER · CASH_WRITES_OFF · CASH_COLLECT_SITE ·
             CASH_COLLECT_BAGS · CASH_COLLECT_DBAG · CASH_COLLECT_DBAG_TAKEN ·
             CASH_COLLECT_MANAGER ·
             CASH_COLLECT_SECURITY · CASH_COLLECT_DATE · CASH_COLLECT_TIME ·
             CASH_PUMPIT_TRIGGER · CASH_COLLECT_NO_BAG · CASH_COLLECT_COLLECTED ·
             CASH_COLLECT_ID

     EXEC agora.usp_Cash_CollectDropSafe @BranchId = 999,
          @BagsJson = N'["TEST-390000000001","TEST-390000000002"]',
          @DBagNo = 'TEST-6870200321', @ManagerCode = 'TDS02', @SecurityName = 'TEST guard',
          @CollectionDate = '2026-09-27', @CollectionTime = '10:15', @WriteMode = 'live', @UserId = 1;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_CollectDropSafe]
    @BranchId        INT,
    @BagsJson        NVARCHAR(MAX),
    @DBagNo          NVARCHAR(40),
    @ManagerCode     NVARCHAR(22),
    @SecurityName    NVARCHAR(100),
    @CollectionDate  DATE,
    @CollectionTime  NVARCHAR(20)   = NULL,
    @Note            NVARCHAR(400)  = NULL,
    @WriteMode       NVARCHAR(10)   = 'off',
    @UserId          INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @DBagNo       = NULLIF(LTRIM(RTRIM(@DBagNo)), '');
    SET @ManagerCode  = NULLIF(LTRIM(RTRIM(@ManagerCode)), '');
    SET @SecurityName = NULLIF(LTRIM(RTRIM(@SecurityName)), '');
    SET @Note         = NULLIF(LTRIM(RTRIM(@Note)), '');

    DECLARE @Msg NVARCHAR(400);
    DECLARE @Today DATE = CONVERT(DATE, GETDATE());
    DECLARE @Time TIME(0) = CASE WHEN NULLIF(LTRIM(RTRIM(@CollectionTime)), '') IS NULL THEN CONVERT(TIME(0), GETDATE())
                                 ELSE TRY_CONVERT(TIME(0), LTRIM(RTRIM(@CollectionTime))) END;

    /* ---- 1. The arguments ------------------------------------------------- */
    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_COLLECT_USER:A collection is recorded by a person, and none was given.', 1;
    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:CASH_WRITES_OFF:Capturing drop safe collections to PumpIT is switched off. Nothing was written.', 1;
    IF @BranchId IS NULL OR NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:CASH_COLLECT_SITE:That site is not in Agora''s branch list.', 1;

    DECLARE @Bags TABLE (BagNo NVARCHAR(40) COLLATE DATABASE_DEFAULT PRIMARY KEY);

    IF @BagsJson IS NULL OR ISJSON(@BagsJson) = 0
        THROW 51000, 'AGORA:CASH_COLLECT_BAGS:Tick the bags the security company is taking.', 1;

    INSERT INTO @Bags (BagNo)
    SELECT DISTINCT LTRIM(RTRIM(j.value))
    FROM OPENJSON(@BagsJson) j
    WHERE j.[type] IN (1, 2) AND LTRIM(RTRIM(j.value)) <> '';

    IF NOT EXISTS (SELECT 1 FROM @Bags)
        THROW 51000, 'AGORA:CASH_COLLECT_BAGS:Tick the bags the security company is taking.', 1;
    IF EXISTS (SELECT 1 FROM @Bags WHERE LEN(BagNo) > 20)
        THROW 51000, 'AGORA:CASH_COLLECT_BAGS:A bag number is at most 20 characters.', 1;
    IF @DBagNo IS NULL OR LEN(@DBagNo) > 20
        THROW 51000, 'AGORA:CASH_COLLECT_DBAG:Give the number on the security company''s bag — up to 20 characters. The bank statement quotes it.', 1;
    IF @ManagerCode IS NULL
        THROW 51000, 'AGORA:CASH_COLLECT_MANAGER:Name the manager handing the bags over.', 1;
    IF LEN(@ManagerCode) > 10
       OR NOT EXISTS (SELECT 1 FROM agora.vw_Employee e WHERE e.BranchId = @BranchId AND e.EmployeeCode = @ManagerCode)
    BEGIN
        SET @Msg = CASE WHEN LEN(@ManagerCode) > 10
            THEN CONCAT('AGORA:CASH_COLLECT_MANAGER:PumpIT keeps the collecting manager in 10 characters, and ', @ManagerCode, ' is longer. Choose another manager.')
            ELSE CONCAT('AGORA:CASH_COLLECT_MANAGER:PumpIT has no employee ', @ManagerCode, ' at this site.') END;
        THROW 51000, @Msg, 1;
    END
    IF @SecurityName IS NULL OR LEN(@SecurityName) > 50
        THROW 51000, 'AGORA:CASH_COLLECT_SECURITY:Give the name of the guard who took the bags — up to 50 characters.', 1;
    IF @CollectionDate IS NULL OR @CollectionDate > @Today
        THROW 51000, 'AGORA:CASH_COLLECT_DATE:Give the day the bags were collected — today or earlier.', 1;
    IF @Time IS NULL
        THROW 51000, 'AGORA:CASH_COLLECT_TIME:Give the time the bags were collected, as hh:mm or hh:mm:ss.', 1;

    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id IN (OBJECT_ID('[PumpIT].dbo.BRN_DropSafe'), OBJECT_ID('[PumpIT].dbo.BRN_DropSafe_Collection'))
                 AND t.is_disabled = 0)
        THROW 51000, 'AGORA:CASH_PUMPIT_TRIGGER:A trigger is enabled on a PumpIT table a collection writes (BRN_DropSafe or BRN_DropSafe_Collection). Agora does not write through one. Nothing was written.', 1;

    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Ref UNIQUEIDENTIFIER = NEWID();
    DECLARE @At DATETIME = @CollectionDate;
    DECLARE @TimeText NVARCHAR(8) = CONVERT(CHAR(8), @Time, 108);
    DECLARE @Count INT = (SELECT COUNT(*) FROM @Bags);
    DECLARE @Total MONEY, @CollectionId BIGINT, @Writes NVARCHAR(MAX);
    DECLARE @New TABLE (CollectionId BIGINT);
    DECLARE @Held TABLE (
        BagNo        NVARCHAR(20) COLLATE DATABASE_DEFAULT,
        Amount       MONEY,
        CollectionId BIGINT
    );

    BEGIN TRY
        BEGIN TRANSACTION;

        /* ---- 2a. The security company's bag, once per site ---------------- */
        IF EXISTS (SELECT 1 FROM [PumpIT].dbo.BRN_DropSafe_Collection c WITH (UPDLOCK, HOLDLOCK)
                   WHERE c.SSBranchId = @BranchId AND LTRIM(RTRIM(c.DBagNo)) = @DBagNo)
        BEGIN
            SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_COLLECT_DBAG_TAKEN:Security company bag ', @DBagNo,
                                            ' was already used at this site — collection ', c.CollectionId, ' on ',
                                            CONVERT(CHAR(10), c.CollectionDate, 23),
                                            '. Bank recon matches the deposit to its bags by this number, so it is used once. Nothing was written.')
                        FROM [PumpIT].dbo.BRN_DropSafe_Collection c
                        WHERE c.SSBranchId = @BranchId AND LTRIM(RTRIM(c.DBagNo)) = @DBagNo
                        ORDER BY c.CollectionDate DESC, c.CollectionId DESC);
            THROW 51000, @Msg, 1;
        END

        /* ---- 2b. The bags, locked, and each still in the safe ------------- */
        INSERT INTO @Held (BagNo, Amount, CollectionId)
        SELECT d.BagNo, d.Amount, ISNULL(d.CollectionId, 0)
        FROM [PumpIT].dbo.BRN_DropSafe d WITH (UPDLOCK, HOLDLOCK)
        JOIN @Bags b ON d.BagNo = b.BagNo
        WHERE d.SSBranchId = @BranchId;

        IF EXISTS (SELECT 1 FROM @Bags b WHERE NOT EXISTS (SELECT 1 FROM @Held h WHERE h.BagNo = b.BagNo))
        BEGIN
            SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_COLLECT_NO_BAG:PumpIT has no bag ', b.BagNo, ' at this site. Nothing was written.')
                        FROM @Bags b WHERE NOT EXISTS (SELECT 1 FROM @Held h WHERE h.BagNo = b.BagNo) ORDER BY b.BagNo);
            THROW 51000, @Msg, 1;
        END

        IF (SELECT COUNT(*) FROM @Held) <> @Count
            THROW 51000, 'AGORA:CASH_COLLECT_NO_BAG:PumpIT holds one of these bag numbers more than once at this site, so it cannot say which is meant. Nothing was written.', 1;

        IF EXISTS (SELECT 1 FROM @Held WHERE CollectionId <> 0)
        BEGIN
            SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_COLLECT_COLLECTED:Bag ', h.BagNo, ' has already left the safe',
                                            CASE WHEN c.CollectionDate IS NOT NULL
                                                 THEN CONCAT(' in ', LTRIM(RTRIM(c.DBagNo)), ' on ', CONVERT(CHAR(10), c.CollectionDate, 23)) ELSE '' END,
                                            '. A bag is collected once. Nothing was written.')
                        FROM @Held h
                        OUTER APPLY (SELECT TOP 1 x.DBagNo, x.CollectionDate FROM agora.vw_DropSafeCollectionHeader x
                                     WHERE x.BranchId = @BranchId AND x.CollectionId = h.CollectionId) c
                        WHERE h.CollectionId <> 0 ORDER BY h.BagNo);
            THROW 51000, @Msg, 1;
        END

        SET @Total = (SELECT ISNULL(SUM(Amount), 0) FROM @Held);

        /* ---- 3. The collection: INSERT, the id from the IDENTITY ---------- */
        -- INSERT SSBranchId, DBagNo, ManagerCode, SecurityName, CollectionDate, CollectionTime,
        --        TotalNoOfBags, TotalAmount, CreateDateTime
        INSERT INTO [PumpIT].dbo.BRN_DropSafe_Collection
            (SSBranchId, DBagNo, ManagerCode, SecurityName, CollectionDate, CollectionTime, TotalNoOfBags, TotalAmount, CreateDateTime)
        OUTPUT inserted.CollectionId INTO @New (CollectionId)
        VALUES
            (@BranchId, @DBagNo, @ManagerCode, @SecurityName, @At, @TimeText, CONVERT(FLOAT, @Count), @Total, GETDATE());

        SET @CollectionId = (SELECT MAX(CollectionId) FROM @New);

        IF @CollectionId IS NULL OR @CollectionId = 0 OR (SELECT COUNT(*) FROM @New) <> 1
            THROW 51000, 'AGORA:CASH_COLLECT_ID:PumpIT gave the collection no id, so its bags cannot be marked. Nothing was written.', 1;

        /* ---- 4. The bags leave the safe: UPDATE CollectionId, and only it -- */
        -- UPDATE CollectionId (0 -> the new id)
        UPDATE d
           SET d.CollectionId = @CollectionId
        FROM [PumpIT].dbo.BRN_DropSafe d
        JOIN @Bags b ON d.BagNo = b.BagNo
        WHERE d.SSBranchId = @BranchId AND ISNULL(d.CollectionId, 0) = 0;

        IF @@ROWCOUNT <> @Count
            THROW 51000, 'AGORA:CASH_COLLECT_COLLECTED:A bag left the safe while the collection was being written. Nothing was written.', 1;

        /* ---- 5. Agora's record: what was written ---------------------------- */
        SET @Writes = (
            SELECT
                JSON_QUERY((SELECT 'insert' AS [action], 'BRN_DropSafe_Collection' AS [table],
                                   JSON_QUERY((SELECT @CollectionId AS CollectionId FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS [key],
                                   JSON_QUERY((SELECT @BranchId AS SSBranchId, @DBagNo AS DBagNo, @ManagerCode AS ManagerCode,
                                                      @SecurityName AS SecurityName, CONVERT(CHAR(10), @CollectionDate, 23) AS CollectionDate,
                                                      @TimeText AS CollectionTime, @Count AS TotalNoOfBags, @Total AS TotalAmount
                                               FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS new
                            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS collection,
                JSON_QUERY((SELECT 'update' AS [action], 'BRN_DropSafe' AS [table],
                                   JSON_QUERY((SELECT @BranchId AS SSBranchId, h.BagNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS [key],
                                   JSON_QUERY((SELECT 0 AS CollectionId FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS prior,
                                   JSON_QUERY((SELECT @CollectionId AS CollectionId FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS new,
                                   h.Amount AS amount
                            FROM @Held h ORDER BY h.BagNo
                            FOR JSON PATH)) AS [rows]
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER);

        INSERT INTO agora.DropSafeEvent
            (BranchId, [Event], CollectionId, DBagNo, Bags, Amount, WriteMode, SaveRef, WritesJson, Note, CreatedAt, CreatedBy)
        VALUES
            (@BranchId, 'collected', @CollectionId, @DBagNo, @Count, @Total, 'live', @Ref, @Writes, @Note, @Now, @UserId);

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT)                                                   AS Ok,
        'CASH_COLLECTED'                                                 AS Code,
        CONVERT(NVARCHAR(400), CONCAT(
            @Count, CASE WHEN @Count = 1 THEN ' bag' ELSE ' bags' END, ' for R', FORMAT(@Total, 'N2'),
            ' collected in ', @DBagNo, ' — collection ', @CollectionId, ' in PumpIT. ',
            CASE WHEN s.Bags = 0 THEN 'The safe is empty.'
                 ELSE CONCAT(s.Bags, CASE WHEN s.Bags = 1 THEN ' bag' ELSE ' bags' END, ' for R', FORMAT(s.Amount, 'N2'), ' still in the safe.') END)) AS Message,
        @CollectionId                                                    AS Id,
        @CollectionId                                                    AS CollectionId,
        @DBagNo                                                          AS DBagNo,
        @Count                                                           AS Bags,
        @Total                                                           AS Amount,
        s.Bags                                                           AS InSafeBags,
        s.Amount                                                         AS InSafeAmount,
        @Ref                                                             AS SaveRef
    FROM (SELECT COUNT(*) AS Bags, CONVERT(MONEY, ISNULL(SUM(d.Amount), 0)) AS Amount
          FROM agora.vw_DropSafeBag d
          WHERE d.BranchId = @BranchId AND ISNULL(d.CollectionId, 0) = 0) s;
END
