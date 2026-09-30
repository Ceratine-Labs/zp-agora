/* ============================================================================
   agora.usp_Cash_SaveDropSafe

   Drop a bag into the manual safe — PumpIT's drop safe capture, ported — or
   correct one that is still in the safe and not yet on a cashup.

   ** ONE OF THE PROCEDURES IN AGORA THAT WRITE TO THE CUSTOMER'S ESTATE.
   ** Approved by Ryan on 27 Sep 2026 (PM decisions 01a0e22a-97be /
   ** 01a0e22a-9aa9: drop safe captured in Agora writes the legacy
   ** BRN_DropSafe tables). What it writes, and nothing else:
   **
   **   BRN_DropSafe   @Mode 'new':  INSERT one row —
   **                    SSBranchId, BagNo, DropDate, DropTime, Amount,
   **                    ReasonForManualBagId, ReasonIfAmountMoreThanR2000,
   **                    RefNoFaultReported, CashierCode, ManagerCode,
   **                    IsSelectedForDailyBanking (0), CollectionId (0),
   **                    CreateDateTime
   **                  @Mode 'edit': UPDATE the one row, only while it is still
   **                    in the safe (CollectionId 0) and not on a cashup
   **                    (IsSelectedForDailyBanking 0, no cash-bags leg) —
   **                    BagNo, DropDate, DropTime, Amount, ReasonForManualBagId,
   **                    ReasonIfAmountMoreThanR2000, RefNoFaultReported,
   **                    CashierCode, ManagerCode
   **
   ** It and agora.DropSafeEvent — with exactly what was written and, for an
   ** edit, the values it replaced — in ONE transaction. Nothing is deleted.
   ** Read this header before changing a line of it.

   THE PORT. PumpIT has no procedure for this: its client writes BRN_DropSafe
   directly. What it writes was read off the customer's rows (27 Sep 2026,
   33,979 bags):
     DropDate        the day the bag was dropped, at midnight (every row);
                     usually captured the next day (26,046 bags within three)
     DropTime        'hh:mm:ss' text (every row)
     Reason…R2000    the reason for a bag over R2,000, as TEXT — picked from
                     BRN_DropSafe_ReasonAmountMoreThanR2000 or typed; '' when
                     none (never NULL). Every bag over R2,000 since October 2023
                     carries one bar a single row; R2,000.00 exactly needs none
                     (1,637 such bags have none).
     RefNoFault…     '' when none. Required where the manual-bag reason says so
                     (BRN_DropSafe_ReasonForManualBag.IsRefNoRequired): every
                     bag under those reasons carries one.
     ManagerCode     NOT NULL, and set on every bag. PumpIT's own collection
                     screen (dbo.sp_SelectDropSafeCapture) and drop-safe report
                     (dbo.sp_SelectDropSafe) INNER JOIN it to BRN_Employee, so a
                     bag without a manager would vanish from both. It is
                     required on every bag here, not only over the threshold.
     CreateDateTime  GETDATE() — PumpIT's clock
     MIST_EOD        NULL (every row)
   NOT PORTED, BY DECISION: the audit line the PumpIT client adds to
   MIST_Audit.dbo.ActionLog (MasterActionid 46, "PumpIT Drop Safe · New").
   agora.DropSafeEvent is the record; nothing is written to MIST_Audit (Ryan,
   27 Sep 2026).

   THE RULES, in order:
     · @WriteMode must be 'live' (cash.dropsafe.write_mode, a literal); the
       application does not call this when it is 'off' — the second lock.
     · A site Agora knows, a bag number (up to 20 characters), a day no later
       than today, a time, and an amount above nil in rands and cents.
     · A manual-bag reason PumpIT offers, and the fault reference when that
       reason needs one.
     · A cashier and a manager, each an employee at the site in PumpIT, and
       not the same person — a cashier does not witness their own bag (Ryan,
       27 Sep 2026; none of the customer's 33,979 bags has one person as both).
     · Over @Threshold (R2,000.00, cash.dropsafe.reason_threshold): a reason.
     · No enabled trigger on BRN_DropSafe.
     · 'new': the bag number is not already at the site — in the safe, collected
       or on a cashup. A bag number is used once.
     · 'edit': the bag is at the site, still in the safe and not on a cashup —
       a cashup's cash-bags leg carries the bag's amount, and a collection its
       total, so a bag on either is not changed. A new number (@NewBagNo) must
       be free, as for a new bag. An edit that changes nothing is refused.

   TWO CLOCKS: agora.DropSafeEvent is stamped SYSUTCDATETIME(); PumpIT's
   CreateDateTime and "today" are GETDATE(), which is what its screens read.

   Returns the writer status row (Ok, Code, Message, Id = the event) with
   BagNo, Amount, InSafeBags and InSafeAmount (the site's safe after the press)
   and SaveRef.

   Refusals: CASH_DROP_USER · CASH_WRITES_OFF · CASH_DROP_MODE · CASH_DROP_SITE ·
             CASH_DROP_BAG · CASH_DROP_DATE · CASH_DROP_TIME · CASH_DROP_AMOUNT ·
             CASH_DROP_REASON · CASH_DROP_REF · CASH_DROP_CASHIER ·
             CASH_DROP_MANAGER · CASH_DROP_MANAGER_UNKNOWN · CASH_DROP_SELF_WITNESS ·
             CASH_DROP_LIMIT_REASON · CASH_PUMPIT_TRIGGER · CASH_DROP_BAG_TAKEN ·
             CASH_DROP_BAG_TWICE · CASH_DROP_NO_BAG · CASH_DROP_COLLECTED ·
             CASH_DROP_ON_CASHUP · CASH_DROP_UNCHANGED

     EXEC agora.usp_Cash_SaveDropSafe @BranchId = 999, @Mode = 'new',
          @BagNo = 'TEST-390000000001', @DropDate = '2026-09-26', @DropTime = '13:30:35',
          @Amount = 2500, @ReasonForManualBagId = 1, @LimitReason = 'Notes rejected by Electronic safe',
          @CashierCode = 'TDS01', @ManagerCode = 'TDS02', @WriteMode = 'live', @UserId = 1;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_SaveDropSafe]
    @BranchId              INT,
    @Mode                  VARCHAR(10)    = 'new',
    @BagNo                 NVARCHAR(40),
    @NewBagNo              NVARCHAR(40)   = NULL,
    @DropDate              DATE,
    @DropTime              NVARCHAR(20)   = NULL,
    @Amount                MONEY,
    @ReasonForManualBagId  INT,
    @RefNoFaultReported    NVARCHAR(40)   = NULL,
    @LimitReason           NVARCHAR(100)  = NULL,
    @CashierCode           NVARCHAR(22),
    @ManagerCode           NVARCHAR(22)   = NULL,
    @Threshold             MONEY          = 2000.00,
    @Note                  NVARCHAR(400)  = NULL,
    @WriteMode             NVARCHAR(10)   = 'off',
    @UserId                INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Mode               = LOWER(LTRIM(RTRIM(ISNULL(@Mode, 'new'))));
    SET @BagNo              = NULLIF(LTRIM(RTRIM(@BagNo)), '');
    SET @NewBagNo           = CASE WHEN @Mode = 'edit' THEN NULLIF(LTRIM(RTRIM(@NewBagNo)), '') END;
    SET @RefNoFaultReported = NULLIF(LTRIM(RTRIM(@RefNoFaultReported)), '');
    SET @LimitReason        = NULLIF(LTRIM(RTRIM(@LimitReason)), '');
    SET @CashierCode        = NULLIF(LTRIM(RTRIM(@CashierCode)), '');
    SET @ManagerCode        = NULLIF(LTRIM(RTRIM(@ManagerCode)), '');
    SET @Note               = NULLIF(LTRIM(RTRIM(@Note)), '');
    SET @Threshold          = ISNULL(@Threshold, 2000.00);

    DECLARE @Msg NVARCHAR(400);
    DECLARE @Today DATE = CONVERT(DATE, GETDATE());
    DECLARE @Time TIME(0) = CASE WHEN NULLIF(LTRIM(RTRIM(@DropTime)), '') IS NULL THEN CONVERT(TIME(0), GETDATE())
                                 ELSE TRY_CONVERT(TIME(0), LTRIM(RTRIM(@DropTime))) END;

    /* ---- 1. The arguments ------------------------------------------------- */
    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_DROP_USER:A bag is captured by a person, and none was given.', 1;
    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:CASH_WRITES_OFF:Capturing drop safe bags to PumpIT is switched off. Nothing was written.', 1;
    IF @Mode NOT IN ('new', 'edit')
        THROW 51000, 'AGORA:CASH_DROP_MODE:A save is a new bag or a correction to one.', 1;
    IF @BranchId IS NULL OR NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:CASH_DROP_SITE:That site is not in Agora''s branch list.', 1;
    IF @BagNo IS NULL OR LEN(@BagNo) > 20 OR LEN(ISNULL(@NewBagNo, '')) > 20
        THROW 51000, 'AGORA:CASH_DROP_BAG:Give the number printed on the bag — up to 20 characters.', 1;
    IF @DropDate IS NULL OR @DropDate > @Today
        THROW 51000, 'AGORA:CASH_DROP_DATE:Give the day the bag was dropped — today or earlier.', 1;
    IF @Time IS NULL
        THROW 51000, 'AGORA:CASH_DROP_TIME:Give the time the bag was dropped, as hh:mm or hh:mm:ss.', 1;
    IF @Amount IS NULL OR @Amount <= 0 OR ROUND(@Amount, 2) <> @Amount
        THROW 51000, 'AGORA:CASH_DROP_AMOUNT:Give the amount in the bag, above nil, in rands and cents.', 1;

    DECLARE @Bag NVARCHAR(20) = ISNULL(@NewBagNo, @BagNo);   -- the number after the press
    DECLARE @TimeText NVARCHAR(8) = CONVERT(CHAR(8), @Time, 108);
    DECLARE @At DATETIME = @DropDate;                          -- midnight, as PumpIT keeps DropDate

    /* ---- 2. The reason, the cashier and the manager ------------------------ */
    DECLARE @ReasonFound BIT = 0, @ReasonText NVARCHAR(100), @ReasonActive BIT, @RefRequired BIT;
    SELECT TOP 1 @ReasonFound = 1, @ReasonText = LTRIM(RTRIM(r.ReasonForManualBag)),
                 @ReasonActive = ISNULL(r.IsActive, 1), @RefRequired = ISNULL(r.IsRefNoRequired, 0)
    FROM agora.vw_DropSafeReason r
    WHERE r.ReasonForManualBagId = @ReasonForManualBagId;

    IF @ReasonFound = 0
        THROW 51000, 'AGORA:CASH_DROP_REASON:Say why the bag went into the manual safe — choose one of the reasons PumpIT offers.', 1;
    IF @RefRequired = 1 AND @RefNoFaultReported IS NULL
    BEGIN
        SET @Msg = CONCAT('AGORA:CASH_DROP_REF:"', @ReasonText, '" needs the reference the fault was reported under. Nothing was written.');
        THROW 51000, @Msg, 1;
    END
    IF LEN(ISNULL(@RefNoFaultReported, '')) > 20
        THROW 51000, 'AGORA:CASH_DROP_REF:The fault reference is kept in 20 characters in PumpIT — shorten it.', 1;

    IF @CashierCode IS NULL
        THROW 51000, 'AGORA:CASH_DROP_CASHIER:Name the cashier whose cash is in the bag.', 1;
    IF LEN(@CashierCode) > 11
       OR NOT EXISTS (SELECT 1 FROM agora.vw_Employee e WHERE e.BranchId = @BranchId AND e.EmployeeCode = @CashierCode)
    BEGIN
        SET @Msg = CONCAT('AGORA:CASH_DROP_CASHIER:PumpIT has no employee ', @CashierCode, ' at this site.');
        THROW 51000, @Msg, 1;
    END

    IF @ManagerCode IS NULL
    BEGIN
        SET @Msg = CASE WHEN @Amount > @Threshold
            THEN CONCAT('AGORA:CASH_DROP_MANAGER:A bag over R', FORMAT(@Threshold, 'N2'), ' needs the manager who witnessed it named — this one is R',
                        FORMAT(@Amount, 'N2'), '. Nothing was written.')
            ELSE 'AGORA:CASH_DROP_MANAGER:Name the manager who witnessed the drop — PumpIT keeps one on every bag. Nothing was written.' END;
        THROW 51000, @Msg, 1;
    END
    IF LEN(@ManagerCode) > 11
       OR NOT EXISTS (SELECT 1 FROM agora.vw_Employee e WHERE e.BranchId = @BranchId AND e.EmployeeCode = @ManagerCode)
    BEGIN
        SET @Msg = CONCAT('AGORA:CASH_DROP_MANAGER_UNKNOWN:PumpIT has no employee ', @ManagerCode, ' at this site to witness the drop.');
        THROW 51000, @Msg, 1;
    END
    IF @ManagerCode = @CashierCode
    BEGIN
        SET @Msg = CONCAT('AGORA:CASH_DROP_SELF_WITNESS:', @CashierCode, ' dropped the bag and cannot also be the manager who witnessed it — name another manager. Nothing was written.');
        THROW 51000, @Msg, 1;
    END

    IF @Amount > @Threshold AND @LimitReason IS NULL
    BEGIN
        SET @Msg = CONCAT('AGORA:CASH_DROP_LIMIT_REASON:A bag over R', FORMAT(@Threshold, 'N2'), ' needs a reason — this one is R',
                          FORMAT(@Amount, 'N2'), '. Nothing was written.');
        THROW 51000, @Msg, 1;
    END
    IF LEN(ISNULL(@LimitReason, '')) > 50
        THROW 51000, 'AGORA:CASH_DROP_LIMIT_REASON:The reason for a bag over the limit is kept in 50 characters in PumpIT — shorten it.', 1;

    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID('[PumpIT].dbo.BRN_DropSafe') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:CASH_PUMPIT_TRIGGER:A trigger is enabled on PumpIT''s BRN_DropSafe. Agora does not write through one. Nothing was written.', 1;

    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Ref UNIQUEIDENTIFIER = NEWID();
    DECLARE @Writes NVARCHAR(MAX), @Changed NVARCHAR(400), @Leg NVARCHAR(120), @EventId BIGINT;
    DECLARE @Prior TABLE (
        BagNo                     NVARCHAR(20)  COLLATE DATABASE_DEFAULT,
        DropDate                  DATETIME,
        DropTime                  NVARCHAR(8)   COLLATE DATABASE_DEFAULT,
        Amount                    MONEY,
        ReasonForManualBagId      INT,
        LimitReason               NVARCHAR(50)  COLLATE DATABASE_DEFAULT,
        RefNoFaultReported        NVARCHAR(20)  COLLATE DATABASE_DEFAULT,
        CashierCode               NVARCHAR(11)  COLLATE DATABASE_DEFAULT,
        ManagerCode               NVARCHAR(11)  COLLATE DATABASE_DEFAULT,
        IsSelectedForDailyBanking BIT,
        CollectionId              BIGINT
    );

    BEGIN TRY
        BEGIN TRANSACTION;

        /* ---- 3. The bag as PumpIT holds it, locked (a range lock where there
                   is none, so a second capture of the same number waits) ---- */
        INSERT INTO @Prior
        SELECT d.BagNo, d.DropDate, d.DropTime, d.Amount, d.ReasonForManualBagId, d.ReasonIfAmountMoreThanR2000,
               d.RefNoFaultReported, d.CashierCode, d.ManagerCode, ISNULL(d.IsSelectedForDailyBanking, 0), ISNULL(d.CollectionId, 0)
        FROM [PumpIT].dbo.BRN_DropSafe d WITH (UPDLOCK, HOLDLOCK)
        WHERE d.SSBranchId = @BranchId AND d.BagNo = @BagNo;

        IF (SELECT COUNT(*) FROM @Prior) > 1
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_DROP_BAG_TWICE:PumpIT holds bag ', @BagNo, ' at this site more than once, so it cannot say which is meant. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        SET @Leg = (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23))
                    FROM [PumpIT].dbo.BRN_DailyBankingCashBags x
                    WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.CashBagNo)) = @BagNo
                    ORDER BY x.TransactionDate DESC);

        IF @Mode = 'new'
        BEGIN
            IF EXISTS (SELECT 1 FROM @Prior)
            BEGIN
                SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_DROP_BAG_TAKEN:Bag ', @BagNo, ' is already in PumpIT at this site — dropped ',
                                                CONVERT(CHAR(10), p.DropDate, 23), ' for R', FORMAT(p.Amount, 'N2'),
                                                CASE WHEN p.CollectionId <> 0 THEN ' and collected' ELSE ' and still in the safe' END,
                                                '. A bag number is used once. Nothing was written.')
                            FROM @Prior p);
                THROW 51000, @Msg, 1;
            END
            IF @Leg IS NOT NULL
            BEGIN
                SET @Msg = CONCAT('AGORA:CASH_DROP_BAG_TAKEN:Bag ', @BagNo, ' is already on the cashup for ', @Leg,
                                  '. A bag number is used once. Nothing was written.');
                THROW 51000, @Msg, 1;
            END
            IF @ReasonActive = 0
            BEGIN
                SET @Msg = CONCAT('AGORA:CASH_DROP_REASON:"', @ReasonText, '" is no longer offered by PumpIT. Choose another reason.');
                THROW 51000, @Msg, 1;
            END

            /* ---- 4a. The bag: INSERT, as the PumpIT client writes it ------ */
            -- INSERT SSBranchId, BagNo, DropDate, DropTime, Amount, ReasonForManualBagId,
            --        ReasonIfAmountMoreThanR2000, RefNoFaultReported, CashierCode, ManagerCode,
            --        IsSelectedForDailyBanking, CollectionId, CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DropSafe
                (SSBranchId, BagNo, DropDate, DropTime, Amount, ReasonForManualBagId, ReasonIfAmountMoreThanR2000,
                 RefNoFaultReported, CashierCode, ManagerCode, IsSelectedForDailyBanking, CollectionId, CreateDateTime)
            VALUES
                (@BranchId, @BagNo, @At, @TimeText, @Amount, @ReasonForManualBagId, ISNULL(@LimitReason, ''),
                 ISNULL(@RefNoFaultReported, ''), @CashierCode, @ManagerCode, 0, 0, GETDATE());

            SET @Writes = (
                SELECT JSON_QUERY((
                    SELECT 'insert' AS [action], 'BRN_DropSafe' AS [table],
                           JSON_QUERY((SELECT @BranchId AS SSBranchId, @BagNo AS BagNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS [key],
                           JSON_QUERY((SELECT CONVERT(CHAR(10), @DropDate, 23) AS DropDate, @TimeText AS DropTime, @Amount AS Amount,
                                              @ReasonForManualBagId AS ReasonForManualBagId,
                                              ISNULL(@LimitReason, '') AS ReasonIfAmountMoreThanR2000,
                                              ISNULL(@RefNoFaultReported, '') AS RefNoFaultReported,
                                              @CashierCode AS CashierCode, @ManagerCode AS ManagerCode,
                                              0 AS IsSelectedForDailyBanking, 0 AS CollectionId
                                       FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS new
                    FOR JSON PATH)) AS [rows]
                FOR JSON PATH, WITHOUT_ARRAY_WRAPPER);
        END
        ELSE
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM @Prior)
            BEGIN
                SET @Msg = CONCAT('AGORA:CASH_DROP_NO_BAG:PumpIT has no bag ', @BagNo, ' at this site to correct.');
                THROW 51000, @Msg, 1;
            END
            IF EXISTS (SELECT 1 FROM @Prior WHERE CollectionId <> 0)
            BEGIN
                SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_DROP_COLLECTED:Bag ', @BagNo, ' was collected',
                                                CASE WHEN c.CollectionDate IS NOT NULL
                                                     THEN CONCAT(' in ', LTRIM(RTRIM(c.DBagNo)), ' on ', CONVERT(CHAR(10), c.CollectionDate, 23)) ELSE '' END,
                                                ' — the collection''s total counts it, so it is not changed. Nothing was written.')
                            FROM @Prior p
                            OUTER APPLY (SELECT TOP 1 x.DBagNo, x.CollectionDate FROM agora.vw_DropSafeCollectionHeader x
                                         WHERE x.BranchId = @BranchId AND x.CollectionId = p.CollectionId) c);
                THROW 51000, @Msg, 1;
            END
            IF EXISTS (SELECT 1 FROM @Prior WHERE IsSelectedForDailyBanking = 1) OR @Leg IS NOT NULL
            BEGIN
                SET @Msg = CONCAT('AGORA:CASH_DROP_ON_CASHUP:Bag ', @BagNo, ' is on the cashup',
                                  CASE WHEN @Leg IS NOT NULL THEN CONCAT(' for ', @Leg) ELSE '' END,
                                  ' — its cash-bags leg carries the bag''s amount, so the bag is not changed. Nothing was written.');
                THROW 51000, @Msg, 1;
            END
            IF @ReasonActive = 0 AND NOT EXISTS (SELECT 1 FROM @Prior WHERE ReasonForManualBagId = @ReasonForManualBagId)
            BEGIN
                SET @Msg = CONCAT('AGORA:CASH_DROP_REASON:"', @ReasonText, '" is no longer offered by PumpIT. Choose another reason.');
                THROW 51000, @Msg, 1;
            END

            IF @Bag <> @BagNo
            BEGIN
                IF EXISTS (SELECT 1 FROM [PumpIT].dbo.BRN_DropSafe WITH (UPDLOCK, HOLDLOCK)
                           WHERE SSBranchId = @BranchId AND BagNo = @Bag)
                   OR EXISTS (SELECT 1 FROM [PumpIT].dbo.BRN_DailyBankingCashBags
                              WHERE SSBranchId = @BranchId AND LTRIM(RTRIM(CashBagNo)) = @Bag)
                BEGIN
                    SET @Msg = CONCAT('AGORA:CASH_DROP_BAG_TAKEN:Bag number ', @Bag, ' is already used at this site. A bag number is used once. Nothing was written.');
                    THROW 51000, @Msg, 1;
                END
            END

            SET @Changed = (
                SELECT CONCAT_WS(', ',
                    CASE WHEN p.BagNo <> @Bag THEN CONCAT('number ', LTRIM(RTRIM(p.BagNo)), N' → ', @Bag) END,
                    CASE WHEN p.DropDate IS NULL OR p.DropDate <> @At THEN CONCAT('day ', ISNULL(CONVERT(CHAR(10), p.DropDate, 23), '?'), N' → ', CONVERT(CHAR(10), @DropDate, 23)) END,
                    CASE WHEN ISNULL(p.DropTime, '') <> @TimeText THEN CONCAT('time ', ISNULL(p.DropTime, '?'), N' → ', @TimeText) END,
                    CASE WHEN p.Amount IS NULL OR p.Amount <> @Amount THEN CONCAT('amount R', FORMAT(ISNULL(p.Amount, 0), 'N2'), N' → R', FORMAT(@Amount, 'N2')) END,
                    CASE WHEN ISNULL(p.ReasonForManualBagId, -1) <> @ReasonForManualBagId THEN 'reason' END,
                    CASE WHEN ISNULL(p.LimitReason, '') <> ISNULL(@LimitReason, '') THEN 'reason over the limit' END,
                    CASE WHEN ISNULL(p.RefNoFaultReported, '') <> ISNULL(@RefNoFaultReported, '') THEN 'fault reference' END,
                    CASE WHEN ISNULL(p.CashierCode, '') <> @CashierCode THEN 'cashier' END,
                    CASE WHEN ISNULL(p.ManagerCode, '') <> @ManagerCode THEN 'manager' END)
                FROM @Prior p);

            IF ISNULL(@Changed, '') = ''
            BEGIN
                SET @Msg = CONCAT('AGORA:CASH_DROP_UNCHANGED:Nothing differs from what PumpIT holds for bag ', @BagNo, '. Nothing was written.');
                THROW 51000, @Msg, 1;
            END

            /* ---- 4b. The bag: UPDATE, only while it is still free to change -- */
            -- UPDATE BagNo, DropDate, DropTime, Amount, ReasonForManualBagId, ReasonIfAmountMoreThanR2000,
            --        RefNoFaultReported, CashierCode, ManagerCode
            UPDATE d
               SET d.BagNo = @Bag, d.DropDate = @At, d.DropTime = @TimeText, d.Amount = @Amount,
                   d.ReasonForManualBagId = @ReasonForManualBagId,
                   d.ReasonIfAmountMoreThanR2000 = ISNULL(@LimitReason, ''),
                   d.RefNoFaultReported = ISNULL(@RefNoFaultReported, ''),
                   d.CashierCode = @CashierCode, d.ManagerCode = @ManagerCode
            FROM [PumpIT].dbo.BRN_DropSafe d
            WHERE d.SSBranchId = @BranchId AND d.BagNo = @BagNo
              AND ISNULL(d.CollectionId, 0) = 0 AND ISNULL(d.IsSelectedForDailyBanking, 0) = 0;

            IF @@ROWCOUNT <> 1
            BEGIN
                SET @Msg = CONCAT('AGORA:CASH_DROP_NO_BAG:Bag ', @BagNo, ' moved while it was being corrected. Nothing was written.');
                THROW 51000, @Msg, 1;
            END

            SET @Writes = (
                SELECT JSON_QUERY((
                    SELECT 'update' AS [action], 'BRN_DropSafe' AS [table],
                           JSON_QUERY((SELECT @BranchId AS SSBranchId, @BagNo AS BagNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS [key],
                           JSON_QUERY((SELECT p.BagNo, CONVERT(CHAR(10), p.DropDate, 23) AS DropDate, p.DropTime, p.Amount,
                                              p.ReasonForManualBagId, p.LimitReason AS ReasonIfAmountMoreThanR2000,
                                              p.RefNoFaultReported, p.CashierCode, p.ManagerCode
                                       FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS prior,
                           JSON_QUERY((SELECT @Bag AS BagNo, CONVERT(CHAR(10), @DropDate, 23) AS DropDate, @TimeText AS DropTime, @Amount AS Amount,
                                              @ReasonForManualBagId AS ReasonForManualBagId,
                                              ISNULL(@LimitReason, '') AS ReasonIfAmountMoreThanR2000,
                                              ISNULL(@RefNoFaultReported, '') AS RefNoFaultReported,
                                              @CashierCode AS CashierCode, @ManagerCode AS ManagerCode
                                       FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS new
                    FROM @Prior p
                    FOR JSON PATH)) AS [rows]
                FOR JSON PATH, WITHOUT_ARRAY_WRAPPER);
        END

        /* ---- 5. Agora's record: what was written ---------------------------- */
        INSERT INTO agora.DropSafeEvent
            (BranchId, [Event], BagNo, PriorBagNo, Amount, WriteMode, SaveRef, WritesJson, Note, CreatedAt, CreatedBy)
        VALUES
            (@BranchId, CASE @Mode WHEN 'new' THEN 'dropped' ELSE 'edited' END, @Bag,
             CASE WHEN @Bag <> @BagNo THEN @BagNo END, @Amount, 'live', @Ref, @Writes, @Note, @Now, @UserId);

        SET @EventId = SCOPE_IDENTITY();

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT)                                                   AS Ok,
        CASE @Mode WHEN 'new' THEN 'CASH_DROP_SAVED' ELSE 'CASH_DROP_EDITED' END AS Code,
        CONVERT(NVARCHAR(400), CASE @Mode
            WHEN 'new' THEN CONCAT('Bag ', @Bag, ' for R', FORMAT(@Amount, 'N2'), ' is in PumpIT''s drop safe. ',
                                   s.Bags, CASE WHEN s.Bags = 1 THEN ' bag' ELSE ' bags' END, ' for R', FORMAT(s.Amount, 'N2'),
                                   ' now wait for collection at this site.')
            ELSE CONCAT('Bag ', @Bag, ' corrected in PumpIT''s drop safe: ', @Changed, '.') END) AS Message,
        @EventId                                                         AS Id,
        @Bag                                                             AS BagNo,
        @Amount                                                          AS Amount,
        s.Bags                                                           AS InSafeBags,
        s.Amount                                                         AS InSafeAmount,
        @Ref                                                             AS SaveRef
    FROM (SELECT COUNT(*) AS Bags, CONVERT(MONEY, ISNULL(SUM(d.Amount), 0)) AS Amount
          FROM agora.vw_DropSafeBag d
          WHERE d.BranchId = @BranchId AND ISNULL(d.CollectionId, 0) = 0) s;
END
