/* ============================================================================
   agora.usp_Cash_VoidDropSafe

   Void a bag captured in error: take its row out of PumpIT's drop safe.

   ** ONE OF THE PROCEDURES IN AGORA THAT WRITE TO THE CUSTOMER'S ESTATE, AND
   ** THE ONLY ONE THAT DELETES. Ryan's named go-ahead, 27 Sep 2026, for THIS
   ** delete only. What it writes, and nothing else:
   **
   **   BRN_DropSafe   DELETE the one row (SSBranchId, BagNo) — only while the
   **                  bag is still in the safe (CollectionId 0), not marked
   **                  banked (IsSelectedForDailyBanking 0) and on no cash-bags
   **                  leg of any cashup
   **
   ** The WHOLE row — every column, as PumpIT holds it — is written to
   ** agora.DropSafeEvent ('voided', WritesJson.rows[0].prior) BEFORE the
   ** delete, in the same transaction, under an UPDLOCK/HOLDLOCK on the row's
   ** full key. The bag can be read back from there; nothing else keeps it.

   WHY A DELETE. PumpIT's own drop screen deletes a wrong bag (525 deletes
   logged in MIST_Audit between January 2025 and September 2026). A bag that
   was never dropped otherwise sits in the safe for ever: it can be corrected
   (agora.usp_Cash_SaveDropSafe, 'edit'), but not made not to exist, and it
   holds the site's "In the safe" and the console's drop safe step open.
   A bag on a cashup or in a collection is part of a figure somebody signed,
   so it is refused, never deleted.

   NOT PORTED: the MIST_Audit.dbo.ActionLog line the PumpIT client writes
   (MasterActionid 47, "PumpIT Drop Safe · Delete") — agora.DropSafeEvent is the
   record (Ryan, 27 Sep 2026).

   THE RULES, in order:
     · @WriteMode must be 'live' (cash.dropsafe.write_mode) — the second lock.
     · A site Agora knows, a bag number, and a reason (up to 400 characters).
     · No enabled trigger on BRN_DropSafe.
     · The bag is at the site exactly once, still in the safe, not marked
       banked and on no cashup's cash-bags leg.

   Returns the writer status row (Ok, Code, Message, Id = the event) with
   BagNo, Amount, InSafeBags and InSafeAmount (the site's safe after the void)
   and SaveRef.

   Refusals: CASH_VOID_USER · CASH_WRITES_OFF · CASH_VOID_SITE · CASH_VOID_BAG ·
             CASH_VOID_REASON · CASH_PUMPIT_TRIGGER · CASH_VOID_NO_BAG ·
             CASH_DROP_BAG_TWICE · CASH_VOID_COLLECTED · CASH_VOID_ON_CASHUP

     EXEC agora.usp_Cash_VoidDropSafe @BranchId = 999, @BagNo = 'TEST-390000000001',
          @Reason = 'Captured twice — the bag is 390000000002', @WriteMode = 'live', @UserId = 1;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_VoidDropSafe]
    @BranchId   INT,
    @BagNo      NVARCHAR(40),
    @Reason     NVARCHAR(400),
    @WriteMode  NVARCHAR(10)  = 'off',
    @UserId     INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @BagNo  = NULLIF(LTRIM(RTRIM(@BagNo)), '');
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), '');

    DECLARE @Msg NVARCHAR(400);

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_VOID_USER:A bag is voided by a person, and none was given.', 1;
    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:CASH_WRITES_OFF:Capturing drop safe bags to PumpIT is switched off. Nothing was written.', 1;
    IF @BranchId IS NULL OR NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:CASH_VOID_SITE:That site is not in Agora''s branch list.', 1;
    IF @BagNo IS NULL OR LEN(@BagNo) > 20
        THROW 51000, 'AGORA:CASH_VOID_BAG:Name the bag to void — its number, up to 20 characters.', 1;
    IF @Reason IS NULL
        THROW 51000, 'AGORA:CASH_VOID_REASON:Say why the bag is being voided — the reason is the only record of why it left PumpIT. Nothing was written.', 1;

    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID('[PumpIT].dbo.BRN_DropSafe') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:CASH_PUMPIT_TRIGGER:A trigger is enabled on PumpIT''s BRN_DropSafe. Agora does not write through one. Nothing was written.', 1;

    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Ref UNIQUEIDENTIFIER = NEWID();
    DECLARE @Rows INT, @Amount MONEY, @CollectionId BIGINT, @Banked BIT, @Leg NVARCHAR(120);
    DECLARE @Whole NVARCHAR(MAX), @Writes NVARCHAR(MAX), @EventId BIGINT;

    BEGIN TRY
        BEGIN TRANSACTION;

        /* ---- 1. The row, on its full key, locked -------------------------- */
        SELECT @Rows = COUNT(*), @Amount = MAX(d.Amount), @CollectionId = MAX(ISNULL(d.CollectionId, 0)),
               @Banked = CONVERT(BIT, MAX(CONVERT(INT, ISNULL(d.IsSelectedForDailyBanking, 0))))
        FROM [PumpIT].dbo.BRN_DropSafe d WITH (UPDLOCK, HOLDLOCK)
        WHERE d.SSBranchId = @BranchId AND d.BagNo = @BagNo;

        IF @Rows = 0
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_VOID_NO_BAG:PumpIT has no bag ', @BagNo, ' at this site. Nothing was written.');
            THROW 51000, @Msg, 1;
        END
        IF @Rows > 1
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_DROP_BAG_TWICE:PumpIT holds bag ', @BagNo, ' at this site more than once, so it cannot say which is meant. Nothing was written.');
            THROW 51000, @Msg, 1;
        END
        IF @CollectionId <> 0
        BEGIN
            SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_VOID_COLLECTED:Bag ', @BagNo, ' has left the safe',
                                            CASE WHEN c.CollectionDate IS NOT NULL
                                                 THEN CONCAT(' in ', LTRIM(RTRIM(c.DBagNo)), ' on ', CONVERT(CHAR(10), c.CollectionDate, 23)) ELSE '' END,
                                            ' — the collection''s total counts it, so it is not voided. Nothing was written.')
                        FROM (SELECT 1 AS one) x
                        OUTER APPLY (SELECT TOP 1 h.DBagNo, h.CollectionDate FROM agora.vw_DropSafeCollectionHeader h
                                     WHERE h.BranchId = @BranchId AND h.CollectionId = @CollectionId) c);
            THROW 51000, @Msg, 1;
        END

        SET @Leg = (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23))
                    FROM [PumpIT].dbo.BRN_DailyBankingCashBags x
                    WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.CashBagNo)) = @BagNo
                    ORDER BY x.TransactionDate DESC);

        IF @Banked = 1 OR @Leg IS NOT NULL
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_VOID_ON_CASHUP:Bag ', @BagNo, ' is on the cashup',
                              CASE WHEN @Leg IS NOT NULL THEN CONCAT(' for ', @Leg) ELSE '' END,
                              ' — its cash-bags leg carries the bag''s amount, so it is not voided. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        /* ---- 2. The whole row, every column, kept BEFORE the delete -------- */
        SET @Whole = (SELECT d.* FROM [PumpIT].dbo.BRN_DropSafe d
                      WHERE d.SSBranchId = @BranchId AND d.BagNo = @BagNo
                      FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES);

        SET @Writes = (
            SELECT JSON_QUERY((
                SELECT 'delete' AS [action], 'BRN_DropSafe' AS [table],
                       JSON_QUERY((SELECT @BranchId AS SSBranchId, @BagNo AS BagNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS [key],
                       JSON_QUERY(@Whole) AS prior
                FOR JSON PATH)) AS [rows]
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER);

        INSERT INTO agora.DropSafeEvent
            (BranchId, [Event], BagNo, Amount, WriteMode, SaveRef, WritesJson, Note, CreatedAt, CreatedBy)
        VALUES
            (@BranchId, 'voided', @BagNo, @Amount, 'live', @Ref, @Writes, @Reason, @Now, @UserId);

        SET @EventId = SCOPE_IDENTITY();

        /* ---- 3. The delete: the one row, still free to go ------------------ */
        -- DELETE BRN_DropSafe WHERE SSBranchId, BagNo (and CollectionId 0, IsSelectedForDailyBanking 0)
        DELETE d
        FROM [PumpIT].dbo.BRN_DropSafe d
        WHERE d.SSBranchId = @BranchId AND d.BagNo = @BagNo
          AND ISNULL(d.CollectionId, 0) = 0 AND ISNULL(d.IsSelectedForDailyBanking, 0) = 0;

        IF @@ROWCOUNT <> 1
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_VOID_NO_BAG:Bag ', @BagNo, ' moved while it was being voided. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT)                                                   AS Ok,
        'CASH_DROP_VOIDED'                                               AS Code,
        CONVERT(NVARCHAR(400), CONCAT('Bag ', @BagNo, ' for R', FORMAT(@Amount, 'N2'),
            ' voided — its row is gone from PumpIT''s drop safe, and kept whole in Agora. ',
            CASE WHEN s.Bags = 0 THEN 'The safe is empty.'
                 ELSE CONCAT(s.Bags, CASE WHEN s.Bags = 1 THEN ' bag' ELSE ' bags' END, ' for R', FORMAT(s.Amount, 'N2'), ' still in the safe.') END)) AS Message,
        @EventId                                                         AS Id,
        @BagNo                                                           AS BagNo,
        @Amount                                                          AS Amount,
        s.Bags                                                           AS InSafeBags,
        s.Amount                                                         AS InSafeAmount,
        @Ref                                                             AS SaveRef
    FROM (SELECT COUNT(*) AS Bags, CONVERT(MONEY, ISNULL(SUM(d.Amount), 0)) AS Amount
          FROM agora.vw_DropSafeBag d
          WHERE d.BranchId = @BranchId AND ISNULL(d.CollectionId, 0) = 0) s;
END
