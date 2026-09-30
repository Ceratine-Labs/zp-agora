/* ============================================================================
   agora.usp_Cash_SaveCashupLeg

   Save one leg of one cashup — and the cashup's header with it, and, for a
   leg chosen from a pool, the pool row's "banked" mark.

   ** ONE OF THE PROCEDURES IN AGORA THAT WRITE TO THE CUSTOMER'S ESTATE.
   ** Approved by Ryan on 27 Sep 2026 (PM decisions 01a0e22a-97be /
   ** 01a0e22a-9aa9: cashup legs write the legacy BRN_DailyBanking* tables;
   ** Q1 of the T037 review: each leg AND its pool row's banked mark, in the
   ** same transaction). What it writes, and nothing else:
   **
   **   BRN_DailyBanking   (the header, every save)
   **     INSERT  SSBranchId, TillNo, ShiftNo, TransactionDate, EmployeeCode,
   **             ClosingBalance, CreditCardAmount, EFuelAmount (0),
   **             DirectDepositAmount (0), EmployeeAmount, Posted (0), DayEndNo,
   **             Imported (0), CardAmount1, CardAmount2 (0), Note,
   **             CreateDateTime                  — when the cashup has none yet
   **     UPDATE  ClosingBalance, EmployeeAmount, CreditCardAmount, CardAmount1,
   **             Note                            — only the ones that change
   **
   **   Legs — INSERT only, except FNB — and their pools' marks:
   **
   **   @Channel       leg table (INSERT)              pool table: column(s) UPDATEd 0 -> 1
   **   FNB            BRN_DailyBankingFNB             — (from the card batch file);
   **                    also UPDATE Amount on an unreconciled line
   **   CashBags       BRN_DailyBankingCashBags        BRN_DropSafe: IsSelectedForDailyBanking
   **   CashDevices    BRN_DailyBankingDeposita        BRN_CashDevices: IsSelectedForDailyBanking
   **   SmartATM       BRN_DailyBankingSmartATM        BRN_SmartATM: IsSelectedForDailyBanking
   **   ABSA           BRN_DailyBankingABSA            BRN_ABSAMarkoff: IsSelectedForDailyBanking
   **   Yumbi          BRN_DailyBankingYumbi           BRN_Yumbi: IsSelectedForDailyBanking
   **   YumbiVoucher   BRN_DailyBankingYumbiVoucher    BRN_YumbiVoucher: IsSelectedForDailyBanking
   **   Infinity       BRN_DailyBankingInfinity        BRN_Infinity: IsSelectedForDailyBanking
   **   Zapper         BRN_DailyBankingZapper          BRN_Zapper: IsSelectedForDailyBanking
   **   Debtors        BRN_DailyBankingCustomers       DBF_INVHISTH_CUSTOMER: IsSelectedForBanking
   **   Suppliers      BRN_DailyBankingSuppliers       DBF_POSTR_SUPPLIER: IsSelectedForBanking
   **   Expenses       BRN_DailyBankingExpenses        BRN_Transaction: Posted (0 -> 1), ShiftNo
   **   StaffShorts    BRN_DailyBankingEmployees       BRN_StaffShorts: IsSelectedForDailyBanking
   **
   ** The columns each leg INSERT writes are listed at its block below. Every
   ** row written — header, leg and pool mark — is recorded in
   ** agora.CashupEvent.WritesJson with the value it replaced, inside the same
   ** transaction, so a correction can put it back.
   ** Read this header before changing a line of it.

   STAFF SHORTS (T040, un-held 27 Sep 2026): the leg is chosen from the
   site's approved staff shorts and saving one POSTS it — see the block.

   HELD — refused with CASH_LEG_HELD, and why:
     FleetCard       its price, cost, margin, rebate and payout are worked out
                     by the legacy client; no PumpIT procedure states the rule
                     (searched 27 Sep 2026), so Agora does not invent one.
     DirectDeposits  the legacy capture types the description, may take a
                     different amount from the bank line (61 of 1,034 in 2026)
                     and links cash-deposit lines as well as direct ones; none
                     of that is in any procedure.

   THE RULES, in order:
     · @WriteMode must be 'live' (cash.cashup.write_mode, a literal); the
       application does not call this when it is 'off' — this is the second
       lock.
     · No enabled trigger on the header or the leg being written:
       dbo.trg_Sync_BRN_DailyBanking rewrites the whole of
       Alteryx.dbo.Rev_BRN_DailyBanking on every statement (disabled on the
       customer's instance, 27 Sep 2026), and Agora never writes Alteryx.
     · A closed cashup is not changed.
     · A RECONCILED LINE IS NEVER CHANGED: an FNB line whose ReconBatchNoPumpIT
       is not 0 is refused if the save would move its amount, naming the batch.
       The pool legs only ever INSERT.
     · A POOL ROW IS BANKED ONCE. Each posted key must name one pool row of this
       site, locked (UPDLOCK, HOLDLOCK) on its full primary key. It is refused,
       by name, when PumpIT already marks it banked, or when any cashup already
       carries it by the legacy's own match (dbo.sp_IsSelectedForDailyBanking_
       DailyBanking_Update) — saying which cashup. A staff short must also be
       approved and not declined, its person on BRN_Employee (the legacy total
       drops anyone else), and nobody's second line on this cashup.
     · An account sale or supplier paid-out is copied onto the leg under the
       customer or supplier whose DebtorID / SupplierID is the POS account; none
       or two is a refusal. A purchase request must be an approved cash request
       (TypeCode 01, not declined).
     · Nothing is ever deleted. Taking a line off a cashup is a correction of
       its own, not a save.
     · A cashup with no header gets one from its ONE allocated Z-read (every
       till-shift-day in 2026 has exactly one).

   THE HEADER'S TOTALS, as the legacy client leaves them:
     ClosingBalance     the Z-read's sales, when one is allocated
     EmployeeAmount     the variance, exactly as agora.vw_CashupTotals computes it
     CreditCardAmount,  the FNB leg's total (FNB saves only)
     CardAmount1

   A CONFIRMED CASHUP whose figures a save moves goes back to 'open' and says so
   ('unconfirmed').

   TWO CLOCKS: Agora's rows are stamped SYSUTCDATETIME(); PumpIT's CreateDateTime
   keeps GETDATE(), which is what the customer's own screens read.

   @LinesJson
     FNB           [{"BatchNo":"0000000235","MerchantNo":"924272","Amount":"1704.00"}, ...]
     a pool leg    [<PoolKey>, ...] — each the JSON object agora.vw_CashupPool
                   gives the row, e.g. {"BagNo":"304795625197"}; a staff short
                   is {"StaffShortsNo":4851} (agora.usp_Cash_GetCashupPool)

   Returns the writer status row (Ok, Code, Message, Id = the cashup's id) with
   Inserted, Updated, Unchanged, ZRead, ActualTotal, Variance and SaveRef.

   Refusals: CASH_CASHUP_USER · CASH_WRITES_OFF · CASH_CASHUP_KEY ·
             CASH_CASHUP_SITE · CASH_LEG_CHANNEL · CASH_LEG_HELD ·
             CASH_LEG_LINES · CASH_LEG_LINE · CASH_LEG_DUPLICATE ·
             CASH_PUMPIT_TRIGGER · CASH_CASHUP_CLOSED · CASH_CASHUP_NO_ZREAD ·
             CASH_CASHUP_ZREAD_AMBIGUOUS · CASH_LEG_RECONCILED ·
             CASH_POOL_NOT_FOUND · CASH_POOL_BANKED · CASH_POOL_MASTER ·
             CASH_POOL_MOVED · CASH_SHORT_FROM_RECON
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_SaveCashupLeg]
    @BranchId         INT,
    @TransactionDate  DATE,
    @TillNo           INT,
    @ShiftNo          INT,
    @Channel          NVARCHAR(20)  = NULL,
    @LinesJson        NVARCHAR(MAX) = NULL,
    @Note             NVARCHAR(400) = NULL,
    @WriteMode        NVARCHAR(10)  = 'off',
    @UserId           INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Channel = NULLIF(LTRIM(RTRIM(@Channel)), '');
    SET @Note    = NULLIF(LTRIM(RTRIM(@Note)), '');

    DECLARE @PoolLegs NVARCHAR(200) = ',CashBags,CashDevices,SmartATM,ABSA,Yumbi,YumbiVoucher,Infinity,Zapper,Debtors,Suppliers,Expenses,StaffShorts,';
    DECLARE @IsPool BIT = CASE WHEN CHARINDEX(',' + ISNULL(@Channel, '-') + ',', @PoolLegs) > 0 THEN 1 ELSE 0 END;

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_CASHUP_USER:A save is made by a person, and none was given.', 1;
    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:CASH_WRITES_OFF:Saving cashups to PumpIT is switched off. Nothing was written.', 1;
    IF @BranchId IS NULL OR @TransactionDate IS NULL OR @TillNo IS NULL OR @ShiftNo IS NULL
        THROW 51000, 'AGORA:CASH_CASHUP_KEY:A cashup is one site, one day, one till and one shift. Give all four.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:CASH_CASHUP_SITE:That site is not in Agora''s branch list.', 1;
    IF @Channel IS NULL AND @Note IS NULL
        THROW 51000, 'AGORA:CASH_LEG_CHANNEL:Say which leg is being saved, or give the note to save.', 1;
    IF @Channel IS NOT NULL AND @Channel NOT IN ('CashBags', 'Debtors', 'SmartATM', 'CashDevices', 'DirectDeposits',
                                                'StaffShorts', 'Expenses', 'FleetCard', 'Suppliers', 'ABSA', 'Zapper',
                                                'Yumbi', 'YumbiVoucher', 'FNB', 'Infinity')
        THROW 51000, 'AGORA:CASH_LEG_CHANNEL:That is not a leg of a cashup.', 1;
    IF @Channel = 'FleetCard'
        THROW 51000, 'AGORA:CASH_LEG_HELD:A fleet-card line carries a price, cost, margin, rebate and payout that the PumpIT client works out; no PumpIT procedure states how, so Agora does not write it. Capture it in PumpIT.', 1;
    IF @Channel = 'DirectDeposits'
        THROW 51000, 'AGORA:CASH_LEG_HELD:A direct deposit is typed against a bank line in PumpIT, sometimes for a different amount, and the rule is not in any procedure. Capture it in PumpIT.', 1;
    IF @Channel IS NOT NULL AND (@LinesJson IS NULL OR ISJSON(@LinesJson) = 0 OR LEFT(LTRIM(@LinesJson), 1) <> '[')
        THROW 51000, 'AGORA:CASH_LEG_LINES:The lines did not arrive as a list.', 1;

    DECLARE @LegTable NVARCHAR(128) = CASE @Channel
        WHEN 'FNB' THEN 'BRN_DailyBankingFNB'            WHEN 'CashBags' THEN 'BRN_DailyBankingCashBags'
        WHEN 'CashDevices' THEN 'BRN_DailyBankingDeposita' WHEN 'SmartATM' THEN 'BRN_DailyBankingSmartATM'
        WHEN 'ABSA' THEN 'BRN_DailyBankingABSA'          WHEN 'Yumbi' THEN 'BRN_DailyBankingYumbi'
        WHEN 'YumbiVoucher' THEN 'BRN_DailyBankingYumbiVoucher' WHEN 'Infinity' THEN 'BRN_DailyBankingInfinity'
        WHEN 'Zapper' THEN 'BRN_DailyBankingZapper'      WHEN 'Debtors' THEN 'BRN_DailyBankingCustomers'
        WHEN 'Suppliers' THEN 'BRN_DailyBankingSuppliers' WHEN 'Expenses' THEN 'BRN_DailyBankingExpenses'
        WHEN 'StaffShorts' THEN 'BRN_DailyBankingEmployees' END;
    DECLARE @PoolTable NVARCHAR(128) = CASE @Channel
        WHEN 'CashBags' THEN 'BRN_DropSafe'       WHEN 'CashDevices' THEN 'BRN_CashDevices'
        WHEN 'SmartATM' THEN 'BRN_SmartATM'       WHEN 'ABSA' THEN 'BRN_ABSAMarkoff'
        WHEN 'Yumbi' THEN 'BRN_Yumbi'             WHEN 'YumbiVoucher' THEN 'BRN_YumbiVoucher'
        WHEN 'Infinity' THEN 'BRN_Infinity'       WHEN 'Zapper' THEN 'BRN_Zapper'
        WHEN 'Debtors' THEN 'DBF_INVHISTH_CUSTOMER' WHEN 'Suppliers' THEN 'DBF_POSTR_SUPPLIER'
        WHEN 'Expenses' THEN 'BRN_Transaction'    WHEN 'StaffShorts' THEN 'BRN_StaffShorts' END;
    DECLARE @PoolName NVARCHAR(80) = CASE @Channel
        WHEN 'CashBags' THEN 'drop safe'          WHEN 'CashDevices' THEN 'cash devices'
        WHEN 'SmartATM' THEN 'Smart ATM deposits' WHEN 'ABSA' THEN 'ABSA mark-off file'
        WHEN 'Yumbi' THEN 'Yumbi orders'          WHEN 'YumbiVoucher' THEN 'Yumbi vouchers'
        WHEN 'Infinity' THEN 'Infinity redemptions' WHEN 'Zapper' THEN 'Zapper payments'
        WHEN 'Debtors' THEN 'POS account sales'   WHEN 'Suppliers' THEN 'POS supplier paid-outs'
        WHEN 'Expenses' THEN 'cash purchase requests' WHEN 'StaffShorts' THEN 'approved staff shorts' END;
    DECLARE @Mark NVARCHAR(40) = CASE WHEN @Channel IN ('Debtors', 'Suppliers') THEN 'IsSelectedForBanking'
                                      WHEN @Channel = 'Expenses' THEN 'Posted'
                                      ELSE 'IsSelectedForDailyBanking' END;

    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id IN (OBJECT_ID('[PumpIT].dbo.BRN_DailyBanking'),
                                     OBJECT_ID('[PumpIT].dbo.' + ISNULL(@LegTable, 'BRN_DailyBanking')),
                                     OBJECT_ID('[PumpIT].dbo.' + ISNULL(@PoolTable, 'BRN_DailyBanking')))
                 AND t.is_disabled = 0)
        THROW 51000, 'AGORA:CASH_PUMPIT_TRIGGER:A trigger is enabled on a PumpIT table this save writes (dbo.trg_Sync_BRN_DailyBanking rewrites the Alteryx copy on every change). Agora does not write through one. Nothing was written.', 1;

    DECLARE @At DATETIME = @TransactionDate;
    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Ref UNIQUEIDENTIFIER = NEWID();
    DECLARE @Msg NVARCHAR(400);

    /* ---- 1. What arrived ---------------------------------------------------- */

    -- FNB: the card lines themselves.
    DECLARE @Line TABLE (
        Seq         INT IDENTITY(1,1) PRIMARY KEY,
        BatchNo     NVARCHAR(60)  NULL,
        MerchantNo  NVARCHAR(60)  NULL,
        Amount      MONEY         NULL,
        OnLeg       BIT           NOT NULL DEFAULT 0,
        OldAmount   MONEY         NULL,
        ReconBatch  INT           NULL,
        [Action]    VARCHAR(10)   NULL
    );

    IF @Channel = 'FNB'
    BEGIN
        INSERT INTO @Line (BatchNo, MerchantNo, Amount)
        SELECT NULLIF(LTRIM(RTRIM(JSON_VALUE(j.value, '$.BatchNo'))), ''),
               NULLIF(LTRIM(RTRIM(JSON_VALUE(j.value, '$.MerchantNo'))), ''),
               TRY_CONVERT(MONEY, JSON_VALUE(j.value, '$.Amount'))
        FROM OPENJSON(@LinesJson) j;

        IF NOT EXISTS (SELECT 1 FROM @Line)
            THROW 51000, 'AGORA:CASH_LEG_LINES:There are no lines to save.', 1;
        IF EXISTS (SELECT 1 FROM @Line WHERE BatchNo IS NULL OR LEN(BatchNo) > 11 OR MerchantNo IS NULL
                                         OR LEN(MerchantNo) > 6 OR Amount IS NULL)
            THROW 51000, 'AGORA:CASH_LEG_LINE:Every card line needs a batch number (up to 11 characters), a merchant number (up to 6) and an amount.', 1;
        IF EXISTS (SELECT 1 FROM @Line GROUP BY BatchNo, MerchantNo HAVING COUNT(*) > 1)
            THROW 51000, 'AGORA:CASH_LEG_DUPLICATE:The same batch and merchant appear twice. A batch is one line.', 1;
    END

    -- A pool leg: the keys of the pool rows ticked.
    CREATE TABLE #sl_k (Seq INT PRIMARY KEY, KeyJson NVARCHAR(4000) COLLATE DATABASE_DEFAULT);

    IF @IsPool = 1
    BEGIN
        INSERT INTO #sl_k (Seq, KeyJson)
        SELECT CONVERT(INT, j.[key]) + 1, j.value FROM OPENJSON(@LinesJson) j WHERE j.[type] = 5;

        IF NOT EXISTS (SELECT 1 FROM #sl_k)
            THROW 51000, 'AGORA:CASH_LEG_LINES:Tick at least one row to add.', 1;
        IF (SELECT COUNT(*) FROM OPENJSON(@LinesJson)) <> (SELECT COUNT(*) FROM #sl_k)
            THROW 51000, 'AGORA:CASH_LEG_LINE:Every line must be a pool row''s key, as the pool list gives it.', 1;
        IF EXISTS (SELECT 1 FROM #sl_k GROUP BY KeyJson HAVING COUNT(*) > 1)
            THROW 51000, 'AGORA:CASH_LEG_DUPLICATE:The same pool row was ticked twice.', 1;
    END

    /* ---- 2. The Z-read behind the cashup ------------------------------------ */
    DECLARE @Zreads INT, @ZSales MONEY, @ZEmployee NVARCHAR(22), @ZEod NVARCHAR(8);

    SELECT @Zreads = COUNT(*), @ZSales = CONVERT(MONEY, SUM(ISNULL(z.Sales, 0))),
           @ZEmployee = MAX(z.EmployeeCode), @ZEod = MAX(z.EodCounter)
    FROM agora.vw_CashZread z
    WHERE z.BranchId = @BranchId AND z.ZReadAt = @At AND z.TillNo = @TillNo AND z.ShiftNo = @ShiftNo;

    IF @Zreads > 1
        THROW 51000, 'AGORA:CASH_CASHUP_ZREAD_AMBIGUOUS:More than one Z-read is allocated to this till and shift that day, so there is no telling which one the cashup is balanced against. Correct the allocation first.', 1;

    /* ---- 3. The write -------------------------------------------------------- */
    DECLARE @CashupId BIGINT, @State NVARCHAR(12), @Created BIT = 0;
    DECLARE @HPrior TABLE (EmployeeCode NVARCHAR(11), ClosingBalance MONEY, CreditCardAmount MONEY, CardAmount1 MONEY,
                           EmployeeAmount MONEY, DayEndNo NVARCHAR(50), Note NVARCHAR(MAX));
    DECLARE @Inserted INT = 0, @Updated INT = 0, @Unchanged INT = 0, @Marked INT = 0;
    DECLARE @ZRead MONEY, @Actual MONEY, @Variance MONEY, @Fnb MONEY;
    DECLARE @HeaderChanged BIT = 0, @Moved BIT = 0;
    DECLARE @Posted NVARCHAR(400);   -- the staff shorts a StaffShorts save posted, for the message

    -- Every leg and pool row written, with what it replaced — WritesJson.
    DECLARE @W TABLE (
        Seq INT IDENTITY(1,1) PRIMARY KEY, [Action] VARCHAR(10), KeyJson NVARCHAR(4000), PriorJson NVARCHAR(4000),
        NewJson NVARCHAR(4000), PoolKey NVARCHAR(4000), PoolPrior NVARCHAR(400), PoolNew NVARCHAR(400)
    );

    -- A pool leg's rows, checked: found, already marked, already on a cashup,
    -- or not bankable yet — the refusals are common to every pool.
    CREATE TABLE #sl_c (
        Seq INT PRIMARY KEY, Ref NVARCHAR(200) COLLATE DATABASE_DEFAULT, Found BIT NOT NULL DEFAULT 0, Flag BIT NULL,
        OnLeg NVARCHAR(200) COLLATE DATABASE_DEFAULT NULL, Problem NVARCHAR(200) COLLATE DATABASE_DEFAULT NULL
    );

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @CashupId = c.Id, @State = c.[Status]
        FROM agora.Cashup c WITH (UPDLOCK, HOLDLOCK)
        WHERE c.BranchId = @BranchId AND c.TransactionDate = @TransactionDate AND c.TillNo = @TillNo AND c.ShiftNo = @ShiftNo;

        IF @State = 'closed'
            THROW 51000, 'AGORA:CASH_CASHUP_CLOSED:This cashup has been closed by a manager and is not changed until it is reopened.', 1;

        /* The header, on its full key, locked until the end. */
        INSERT INTO @HPrior (EmployeeCode, ClosingBalance, CreditCardAmount, CardAmount1, EmployeeAmount, DayEndNo, Note)
        SELECT h.EmployeeCode, h.ClosingBalance, h.CreditCardAmount, h.CardAmount1, h.EmployeeAmount, h.DayEndNo,
               CONVERT(NVARCHAR(MAX), h.Note)
        FROM [PumpIT].dbo.BRN_DailyBanking h WITH (UPDLOCK, HOLDLOCK)
        WHERE h.SSBranchId = @BranchId AND h.TillNo = @TillNo AND h.ShiftNo = @ShiftNo AND h.TransactionDate = @At;

        IF NOT EXISTS (SELECT 1 FROM @HPrior)
        BEGIN
            IF ISNULL(@Zreads, 0) = 0
                THROW 51000, 'AGORA:CASH_CASHUP_NO_ZREAD:This till and shift has no cashup yet and no Z-read allocated to it that day. Allocate the Z-read first — the cashup takes its person, its day-end number and its sales from it.', 1;

            INSERT INTO [PumpIT].dbo.BRN_DailyBanking
                (SSBranchId, TillNo, ShiftNo, TransactionDate, EmployeeCode, ClosingBalance, CreditCardAmount,
                 EFuelAmount, DirectDepositAmount, EmployeeAmount, Posted, DayEndNo, Imported, CardAmount1,
                 CardAmount2, Note, CreateDateTime)
            VALUES
                (@BranchId, @TillNo, @ShiftNo, @At, LEFT(@ZEmployee, 11), @ZSales, 0,
                 0, 0, 0, 0, @ZEod, 0, 0,
                 0, @Note, GETDATE());

            SET @Created = 1;
        END

        /* ================================================================== FNB */
        IF @Channel = 'FNB'
        BEGIN
            UPDATE l
               SET OnLeg = 1, OldAmount = f.Amount, ReconBatch = ISNULL(f.ReconBatchNoPumpIT, 0)
            FROM @Line l
            JOIN [PumpIT].dbo.BRN_DailyBankingFNB f WITH (UPDLOCK, HOLDLOCK)
              ON f.SSBranchId = @BranchId AND f.TillNo = @TillNo AND f.ShiftNo = @ShiftNo AND f.TransactionDate = @At
             AND LTRIM(RTRIM(f.BatchNo)) = l.BatchNo AND LTRIM(RTRIM(f.MerchantNo)) = l.MerchantNo;

            UPDATE @Line
               SET [Action] = CASE WHEN OnLeg = 0 THEN 'insert'
                                   WHEN ABS(ISNULL(OldAmount, 0) - Amount) < 0.00005 AND OldAmount IS NOT NULL THEN 'same'
                                   ELSE 'update' END;

            IF EXISTS (SELECT 1 FROM @Line WHERE [Action] = 'update' AND ReconBatch <> 0)
            BEGIN
                SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_LEG_RECONCILED:Card batch ', BatchNo, ' (merchant ', MerchantNo,
                                                ') is already reconciled in recon batch ', ReconBatch,
                                                ', so its amount is not changed from here. Nothing was written.')
                            FROM @Line WHERE [Action] = 'update' AND ReconBatch <> 0 ORDER BY Seq);
                THROW 51000, @Msg, 1;
            END

            -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, BatchNo, MerchantNo, Amount, CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingFNB
                (SSBranchId, TillNo, ShiftNo, TransactionDate, BatchNo, MerchantNo, Amount, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, l.BatchNo, l.MerchantNo, l.Amount, GETDATE()
            FROM @Line l WHERE l.[Action] = 'insert';
            SET @Inserted = @@ROWCOUNT;

            -- UPDATE Amount — never where ReconBatchNoPumpIT <> 0
            UPDATE f
               SET f.Amount = l.Amount
            FROM [PumpIT].dbo.BRN_DailyBankingFNB f
            JOIN @Line l
              ON LTRIM(RTRIM(f.BatchNo)) = l.BatchNo AND LTRIM(RTRIM(f.MerchantNo)) = l.MerchantNo
            WHERE f.SSBranchId = @BranchId AND f.TillNo = @TillNo AND f.ShiftNo = @ShiftNo AND f.TransactionDate = @At
              AND l.[Action] = 'update' AND ISNULL(f.ReconBatchNoPumpIT, 0) = 0;
            SET @Updated = @@ROWCOUNT;

            IF @Updated <> (SELECT COUNT(*) FROM @Line WHERE [Action] = 'update')
                THROW 51000, 'AGORA:CASH_LEG_RECONCILED:A card line changed under the save (reconciled while it ran). Nothing was written.', 1;

            SELECT @Unchanged = COUNT(*) FROM @Line WHERE [Action] = 'same';

            INSERT INTO @W ([Action], KeyJson, PriorJson, NewJson)
            SELECT l.[Action],
                   (SELECT l.BatchNo AS BatchNo, l.MerchantNo AS MerchantNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT l.OldAmount AS Amount FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES),
                   (SELECT l.Amount AS Amount FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)
            FROM @Line l WHERE l.[Action] IN ('insert', 'update') ORDER BY l.Seq;
        END

        /* ============================================ POOL LEGS: find and check */

        -- Cash bags <- BRN_DropSafe (SSBranchId, BagNo)
        IF @Channel = 'CashBags'
        BEGIN
            CREATE TABLE #sl_cb (Seq INT PRIMARY KEY, BagNo NVARCHAR(20) COLLATE DATABASE_DEFAULT);
            INSERT INTO #sl_cb (Seq, BagNo)
            SELECT k.Seq, x.BagNo FROM #sl_k k CROSS APPLY OPENJSON(k.KeyJson) WITH (BagNo NVARCHAR(20) '$.BagNo') x;

            INSERT INTO #sl_c (Seq, Ref, Found, Flag, OnLeg)
            SELECT c.Seq, CONCAT('Bag ', LTRIM(RTRIM(c.BagNo))), CASE WHEN d.BagNo IS NULL THEN 0 ELSE 1 END,
                   d.IsSelectedForDailyBanking, o.OnLeg
            FROM #sl_cb c
            LEFT JOIN [PumpIT].dbo.BRN_DropSafe d WITH (UPDLOCK, HOLDLOCK)
                   ON d.SSBranchId = @BranchId AND d.BagNo = c.BagNo
            OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23)) AS OnLeg
                         FROM [PumpIT].dbo.BRN_DailyBankingCashBags x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.CashBagNo)) = LTRIM(RTRIM(c.BagNo))) o;
        END

        -- Cash devices <- BRN_CashDevices (SSBranchId, TransactionDateTime, SafeId, TransactionNo)
        IF @Channel = 'CashDevices'
        BEGIN
            CREATE TABLE #sl_cd (Seq INT PRIMARY KEY, TxAt DATETIME, SafeId NVARCHAR(10) COLLATE DATABASE_DEFAULT,
                                 TransactionNo NVARCHAR(15) COLLATE DATABASE_DEFAULT);
            INSERT INTO #sl_cd (Seq, TxAt, SafeId, TransactionNo)
            SELECT k.Seq, TRY_CONVERT(DATETIME, x.TxAt, 126), x.SafeId, x.TransactionNo
            FROM #sl_k k CROSS APPLY OPENJSON(k.KeyJson)
                 WITH (TxAt NVARCHAR(30) '$.TransactionDateTime', SafeId NVARCHAR(10) '$.SafeId', TransactionNo NVARCHAR(15) '$.TransactionNo') x;

            INSERT INTO #sl_c (Seq, Ref, Found, Flag, OnLeg)
            SELECT c.Seq, CONCAT('Cash device ', LTRIM(RTRIM(c.SafeId)), ' slip ', LTRIM(RTRIM(c.TransactionNo))),
                   CASE WHEN d.SafeId IS NULL THEN 0 ELSE 1 END, d.IsSelectedForDailyBanking, o.OnLeg
            FROM #sl_cd c
            LEFT JOIN [PumpIT].dbo.BRN_CashDevices d WITH (UPDLOCK, HOLDLOCK)
                   ON d.SSBranchId = @BranchId AND d.TransactionDateTime = c.TxAt AND d.SafeId = c.SafeId AND d.TransactionNo = c.TransactionNo
            OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23)) AS OnLeg
                         FROM [PumpIT].dbo.BRN_DailyBankingDeposita x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.TerminalNo)) = LTRIM(RTRIM(c.SafeId))
                           AND LTRIM(RTRIM(x.SlipNo)) = LTRIM(RTRIM(c.TransactionNo))) o;
        END

        -- Smart ATM <- BRN_SmartATM (SSBranchId, DepositDateTime, TraceNo, TerminalId)
        IF @Channel = 'SmartATM'
        BEGIN
            CREATE TABLE #sl_sa (Seq INT PRIMARY KEY, DepAt DATETIME, TraceNo NVARCHAR(7) COLLATE DATABASE_DEFAULT,
                                 TerminalId NVARCHAR(8) COLLATE DATABASE_DEFAULT);
            INSERT INTO #sl_sa (Seq, DepAt, TraceNo, TerminalId)
            SELECT k.Seq, TRY_CONVERT(DATETIME, x.DepAt, 126), x.TraceNo, x.TerminalId
            FROM #sl_k k CROSS APPLY OPENJSON(k.KeyJson)
                 WITH (DepAt NVARCHAR(30) '$.DepositDateTime', TraceNo NVARCHAR(7) '$.TraceNo', TerminalId NVARCHAR(8) '$.TerminalId') x;

            INSERT INTO #sl_c (Seq, Ref, Found, Flag, OnLeg, Problem)
            SELECT c.Seq, CONCAT('Smart ATM ', LTRIM(RTRIM(c.TerminalId)), ' trace ', LTRIM(RTRIM(c.TraceNo))),
                   CASE WHEN d.TerminalId IS NULL THEN 0 ELSE 1 END, d.IsSelectedForDailyBanking, o.OnLeg,
                   CASE WHEN d.TerminalId IS NOT NULL AND d.UniqueNo IS NULL THEN 'the deposit carries no unique number' END
            FROM #sl_sa c
            LEFT JOIN [PumpIT].dbo.BRN_SmartATM d WITH (UPDLOCK, HOLDLOCK)
                   ON d.SSBranchId = @BranchId AND d.DepositDateTime = c.DepAt AND d.TraceNo = c.TraceNo AND d.TerminalId = c.TerminalId
            OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.DepositDateTime, 23)) AS OnLeg
                         FROM [PumpIT].dbo.BRN_DailyBankingSmartATM x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.TerminalId)) = LTRIM(RTRIM(c.TerminalId))
                           AND LTRIM(RTRIM(x.TraceNo)) = LTRIM(RTRIM(c.TraceNo))
                           AND TRY_CONVERT(FLOAT, x.UniqueNo) = TRY_CONVERT(FLOAT, d.UniqueNo)) o;
        END

        -- ABSA <- BRN_ABSAMarkoff (SSBranchId, Dtl5_TransactionDate, Dtl8_TransactionTime, Dtl9_TransactionAmount, Dtl23_RetrievalRefNumber)
        IF @Channel = 'ABSA'
        BEGIN
            CREATE TABLE #sl_ab (Seq INT PRIMARY KEY, TxDate DATETIME, TxTime DATETIME, Amount MONEY,
                                 Rrn NVARCHAR(12) COLLATE DATABASE_DEFAULT);
            INSERT INTO #sl_ab (Seq, TxDate, TxTime, Amount, Rrn)
            SELECT k.Seq, TRY_CONVERT(DATETIME, x.TxDate, 126), TRY_CONVERT(DATETIME, x.TxTime, 126), TRY_CONVERT(MONEY, x.Amount), x.Rrn
            FROM #sl_k k CROSS APPLY OPENJSON(k.KeyJson)
                 WITH (TxDate NVARCHAR(30) '$.TransactionDate', TxTime NVARCHAR(30) '$.TransactionTime',
                       Amount NVARCHAR(40) '$.Amount', Rrn NVARCHAR(12) '$.RetrievalRefNumber') x;

            INSERT INTO #sl_c (Seq, Ref, Found, Flag, OnLeg)
            SELECT c.Seq, CONCAT('ABSA transaction ', LTRIM(RTRIM(c.Rrn))),
                   CASE WHEN d.SSBranchId IS NULL THEN 0 ELSE 1 END, d.IsSelectedForDailyBanking, o.OnLeg
            FROM #sl_ab c
            LEFT JOIN [PumpIT].dbo.BRN_ABSAMarkoff d WITH (UPDLOCK, HOLDLOCK)
                   ON d.SSBranchId = @BranchId AND d.Dtl5_TransactionDate = c.TxDate AND d.Dtl8_TransactionTime = c.TxTime
                  AND d.Dtl9_TransactionAmount = c.Amount AND d.Dtl23_RetrievalRefNumber = c.Rrn
            OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23)) AS OnLeg
                         FROM [PumpIT].dbo.BRN_DailyBankingABSA x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.RetrievalRefNumber)) = LTRIM(RTRIM(c.Rrn))
                           AND LTRIM(RTRIM(x.TransactionAuthCode)) = LTRIM(RTRIM(ISNULL(d.Dtl12_TransactionAuthCode, '')))
                           AND x.ABSA_TransactionDate = c.TxDate AND x.ABSA_TransactionTime = c.TxTime
                           AND x.TransactionAmount + ISNULL(x.AdjustedBy, 0) = c.Amount) o;
        END

        -- Yumbi <- BRN_Yumbi (SSBranchId, ID)
        IF @Channel = 'Yumbi'
        BEGIN
            CREATE TABLE #sl_yu (Seq INT PRIMARY KEY, ID NVARCHAR(10) COLLATE DATABASE_DEFAULT);
            INSERT INTO #sl_yu (Seq, ID)
            SELECT k.Seq, x.ID FROM #sl_k k CROSS APPLY OPENJSON(k.KeyJson) WITH (ID NVARCHAR(10) '$.ID') x;

            INSERT INTO #sl_c (Seq, Ref, Found, Flag, OnLeg)
            SELECT c.Seq, CONCAT('Yumbi order ', LTRIM(RTRIM(c.ID))), CASE WHEN d.ID IS NULL THEN 0 ELSE 1 END,
                   ISNULL(d.IsSelectedForDailyBanking, 0), o.OnLeg
            FROM #sl_yu c
            LEFT JOIN [PumpIT].dbo.BRN_Yumbi d WITH (UPDLOCK, HOLDLOCK) ON d.SSBranchId = @BranchId AND d.ID = c.ID
            OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23)) AS OnLeg
                         FROM [PumpIT].dbo.BRN_DailyBankingYumbi x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.ID)) = LTRIM(RTRIM(c.ID))) o;
        END

        -- Yumbi vouchers <- BRN_YumbiVoucher (SSBranchId, RedemptionDate, CustomerReference)
        IF @Channel = 'YumbiVoucher'
        BEGIN
            CREATE TABLE #sl_yv (Seq INT PRIMARY KEY, RedAt DATETIME, CustRef NVARCHAR(50) COLLATE DATABASE_DEFAULT);
            INSERT INTO #sl_yv (Seq, RedAt, CustRef)
            SELECT k.Seq, TRY_CONVERT(DATETIME, x.RedAt, 126), x.CustRef
            FROM #sl_k k CROSS APPLY OPENJSON(k.KeyJson)
                 WITH (RedAt NVARCHAR(30) '$.RedemptionDate', CustRef NVARCHAR(50) '$.CustomerReference') x;

            INSERT INTO #sl_c (Seq, Ref, Found, Flag, OnLeg)
            SELECT c.Seq, CONCAT('Yumbi voucher ', LTRIM(RTRIM(c.CustRef))), CASE WHEN d.CustomerReference IS NULL THEN 0 ELSE 1 END,
                   d.IsSelectedForDailyBanking, o.OnLeg
            FROM #sl_yv c
            LEFT JOIN [PumpIT].dbo.BRN_YumbiVoucher d WITH (UPDLOCK, HOLDLOCK)
                   ON d.SSBranchId = @BranchId AND d.RedemptionDate = c.RedAt AND d.CustomerReference = c.CustRef
            OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23)) AS OnLeg
                         FROM [PumpIT].dbo.BRN_DailyBankingYumbiVoucher x
                         WHERE x.SSBranchId = @BranchId AND x.RedemptionDate = c.RedAt AND x.CustomerReference = c.CustRef) o;
        END

        -- Infinity <- BRN_Infinity (SSBranchId, TransactionDate, Cardholder, RewardPurchaseValue, RewardReference,
        --                           RedeemReference, TerminalReference, TerminalQuantity)
        IF @Channel = 'Infinity'
        BEGIN
            CREATE TABLE #sl_in (Seq INT PRIMARY KEY, TxDate DATETIME, Cardholder NVARCHAR(50) COLLATE DATABASE_DEFAULT,
                                 Rpv FLOAT, RewardRef NVARCHAR(50) COLLATE DATABASE_DEFAULT, RedeemRef NVARCHAR(50) COLLATE DATABASE_DEFAULT,
                                 TermRef NVARCHAR(50) COLLATE DATABASE_DEFAULT, TermQty FLOAT);
            INSERT INTO #sl_in (Seq, TxDate, Cardholder, Rpv, RewardRef, RedeemRef, TermRef, TermQty)
            SELECT k.Seq, TRY_CONVERT(DATETIME, x.TxDate, 126), x.Cardholder, TRY_CONVERT(FLOAT, x.Rpv), x.RewardRef,
                   x.RedeemRef, x.TermRef, TRY_CONVERT(FLOAT, x.TermQty)
            FROM #sl_k k CROSS APPLY OPENJSON(k.KeyJson)
                 WITH (TxDate NVARCHAR(30) '$.TransactionDate', Cardholder NVARCHAR(50) '$.Cardholder',
                       Rpv NVARCHAR(40) '$.RewardPurchaseValue', RewardRef NVARCHAR(50) '$.RewardReference',
                       RedeemRef NVARCHAR(50) '$.RedeemReference', TermRef NVARCHAR(50) '$.TerminalReference',
                       TermQty NVARCHAR(40) '$.TerminalQuantity') x;

            INSERT INTO #sl_c (Seq, Ref, Found, Flag, OnLeg)
            SELECT c.Seq, CONCAT('Infinity redemption ', LTRIM(RTRIM(c.RedeemRef))), CASE WHEN d.Cardholder IS NULL THEN 0 ELSE 1 END,
                   ISNULL(d.IsSelectedForDailyBanking, 0), o.OnLeg
            FROM #sl_in c
            LEFT JOIN [PumpIT].dbo.BRN_Infinity d WITH (UPDLOCK, HOLDLOCK)
                   ON d.SSBranchId = @BranchId AND d.TransactionDate = c.TxDate AND d.Cardholder = c.Cardholder
                  AND d.RewardPurchaseValue = c.Rpv AND d.RewardReference = c.RewardRef AND d.RedeemReference = c.RedeemRef
                  AND d.TerminalReference = c.TermRef AND d.TerminalQuantity = c.TermQty
            OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23)) AS OnLeg
                         FROM [PumpIT].dbo.BRN_DailyBankingInfinity x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.CardHolder)) = LTRIM(RTRIM(c.Cardholder))
                           AND x.RewardPurchaseValue = c.Rpv AND LTRIM(RTRIM(x.RewardReference)) = LTRIM(RTRIM(c.RewardRef))
                           AND LTRIM(RTRIM(x.RedeemReference)) = LTRIM(RTRIM(c.RedeemRef))
                           AND LTRIM(RTRIM(x.TerminalReference)) = LTRIM(RTRIM(c.TermRef)) AND x.TerminalQuantity = c.TermQty) o;
        END

        -- Zapper <- BRN_Zapper (SSBranchId, TransactionDate, UniqueField)
        IF @Channel = 'Zapper'
        BEGIN
            CREATE TABLE #sl_za (Seq INT PRIMARY KEY, TxDate DATETIME, UniqueField NVARCHAR(150) COLLATE DATABASE_DEFAULT);
            INSERT INTO #sl_za (Seq, TxDate, UniqueField)
            SELECT k.Seq, TRY_CONVERT(DATETIME, x.TxDate, 126), x.UniqueField
            FROM #sl_k k CROSS APPLY OPENJSON(k.KeyJson) WITH (TxDate NVARCHAR(30) '$.TransactionDate', UniqueField NVARCHAR(150) '$.UniqueField') x;

            INSERT INTO #sl_c (Seq, Ref, Found, Flag, OnLeg)
            SELECT c.Seq, CONCAT('Zapper payment ', LTRIM(RTRIM(c.UniqueField))), CASE WHEN d.UniqueField IS NULL THEN 0 ELSE 1 END,
                   d.IsSelectedForDailyBanking, o.OnLeg
            FROM #sl_za c
            LEFT JOIN [PumpIT].dbo.BRN_Zapper d WITH (UPDLOCK, HOLDLOCK)
                   ON d.SSBranchId = @BranchId AND d.TransactionDate = c.TxDate AND d.UniqueField = c.UniqueField
            OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23)) AS OnLeg
                         FROM [PumpIT].dbo.BRN_DailyBankingZapper x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.UniqueField)) = LTRIM(RTRIM(c.UniqueField))) o;
        END

        -- Accounts <- DBF_INVHISTH_CUSTOMER (SSBranchId, DRC, LOGFILE, TYPE, REF, DATE, CNUM, LOCATION, EOD_CNTR)
        IF @Channel = 'Debtors'
        BEGIN
            CREATE TABLE #sl_de (Seq INT PRIMARY KEY, DRC CHAR(1) COLLATE DATABASE_DEFAULT, LOGFILE CHAR(10) COLLATE DATABASE_DEFAULT,
                                 [TYPE] CHAR(1) COLLATE DATABASE_DEFAULT, REF CHAR(20) COLLATE DATABASE_DEFAULT, [DATE] DATETIME,
                                 CNUM CHAR(10) COLLATE DATABASE_DEFAULT, [LOCATION] CHAR(10) COLLATE DATABASE_DEFAULT,
                                 EOD_CNTR CHAR(8) COLLATE DATABASE_DEFAULT, CustomerCode NVARCHAR(10) COLLATE DATABASE_DEFAULT NULL,
                                 ItemNo INT NULL);
            INSERT INTO #sl_de (Seq, DRC, LOGFILE, [TYPE], REF, [DATE], CNUM, [LOCATION], EOD_CNTR)
            SELECT k.Seq, x.DRC, x.LOGFILE, x.[TYPE], x.REF, TRY_CONVERT(DATETIME, x.[DATE], 126), x.CNUM, x.[LOCATION], x.EOD_CNTR
            FROM #sl_k k CROSS APPLY OPENJSON(k.KeyJson)
                 WITH (DRC CHAR(1) '$.DRC', LOGFILE CHAR(10) '$.LOGFILE', [TYPE] CHAR(1) '$.TYPE', REF CHAR(20) '$.REF',
                       [DATE] NVARCHAR(30) '$.DATE', CNUM CHAR(10) '$.CNUM', [LOCATION] CHAR(10) '$.LOCATION',
                       EOD_CNTR CHAR(8) '$.EOD_CNTR') x;

            UPDATE c SET CustomerCode = m.Code
            FROM #sl_de c
            CROSS APPLY (SELECT MIN(x.CustomerCode) AS Code, COUNT(*) AS n FROM [PumpIT].dbo.BRN_Customers x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.DebtorID)) = LTRIM(RTRIM(c.CNUM))) m
            WHERE m.n = 1;

            INSERT INTO #sl_c (Seq, Ref, Found, Flag, OnLeg, Problem)
            SELECT c.Seq, CONCAT('Account sale ', LTRIM(RTRIM(c.REF))), CASE WHEN d.REF IS NULL THEN 0 ELSE 1 END,
                   d.IsSelectedForBanking, o.OnLeg,
                   CASE WHEN c.CustomerCode IS NULL
                        THEN CONCAT('POS account ', LTRIM(RTRIM(c.CNUM)), ' is on no single PumpIT customer (DebtorID)') END
            FROM #sl_de c
            LEFT JOIN [PumpIT].dbo.DBF_INVHISTH_CUSTOMER d WITH (UPDLOCK, HOLDLOCK)
                   ON d.SSBranchId = @BranchId AND d.DRC = c.DRC AND d.LOGFILE = c.LOGFILE AND d.[TYPE] = c.[TYPE]
                  AND d.REF = c.REF AND d.[DATE] = c.[DATE] AND d.CNUM = c.CNUM AND d.[LOCATION] = c.[LOCATION]
                  AND d.EOD_CNTR = c.EOD_CNTR
            OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23)) AS OnLeg
                         FROM [PumpIT].dbo.BRN_DailyBankingCustomers x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.Reference1)) = LTRIM(RTRIM(c.REF))
                           AND x.Reference2 = d.[DESC]) o;
        END

        -- Supplier paid-outs <- DBF_POSTR_SUPPLIER (SSBranchId, DATE, DESC, REF, LOCATION, EOD_CNTR)
        IF @Channel = 'Suppliers'
        BEGIN
            CREATE TABLE #sl_su (Seq INT PRIMARY KEY, [DATE] DATETIME, [DESC] CHAR(20) COLLATE DATABASE_DEFAULT,
                                 REF CHAR(20) COLLATE DATABASE_DEFAULT, [LOCATION] CHAR(10) COLLATE DATABASE_DEFAULT,
                                 EOD_CNTR CHAR(8) COLLATE DATABASE_DEFAULT, SupplierCode NVARCHAR(8) COLLATE DATABASE_DEFAULT NULL);
            INSERT INTO #sl_su (Seq, [DATE], [DESC], REF, [LOCATION], EOD_CNTR)
            SELECT k.Seq, TRY_CONVERT(DATETIME, x.[DATE], 126), x.[DESC], x.REF, x.[LOCATION], x.EOD_CNTR
            FROM #sl_k k CROSS APPLY OPENJSON(k.KeyJson)
                 WITH ([DATE] NVARCHAR(30) '$.DATE', [DESC] CHAR(20) '$.DESC', REF CHAR(20) '$.REF',
                       [LOCATION] CHAR(10) '$.LOCATION', EOD_CNTR CHAR(8) '$.EOD_CNTR') x;

            INSERT INTO #sl_c (Seq, Ref, Found, Flag, OnLeg, Problem)
            SELECT c.Seq, CONCAT('Supplier paid-out ', LTRIM(RTRIM(c.REF))), CASE WHEN d.REF IS NULL THEN 0 ELSE 1 END,
                   d.IsSelectedForBanking, o.OnLeg,
                   CASE WHEN d.REF IS NOT NULL AND ISNULL(m.n, 0) <> 1
                        THEN CONCAT('POS supplier ', LTRIM(RTRIM(d.CNUM)), ' is on no single PumpIT supplier (SupplierID)') END
            FROM #sl_su c
            LEFT JOIN [PumpIT].dbo.DBF_POSTR_SUPPLIER d WITH (UPDLOCK, HOLDLOCK)
                   ON d.SSBranchId = @BranchId AND d.[DATE] = c.[DATE] AND d.[DESC] = c.[DESC] AND d.REF = c.REF
                  AND d.[LOCATION] = c.[LOCATION] AND d.EOD_CNTR = c.EOD_CNTR
            OUTER APPLY (SELECT MIN(x.SupplierCode) AS Code, COUNT(*) AS n FROM [PumpIT].dbo.BRN_Suppliers x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.SupplierID)) = LTRIM(RTRIM(d.CNUM))) m
            OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23)) AS OnLeg
                         FROM [PumpIT].dbo.BRN_DailyBankingSuppliers x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.DocumentNo)) = LTRIM(RTRIM(c.REF))
                           AND x.Reference = c.[DESC]) o;

            UPDATE c SET SupplierCode = m.Code
            FROM #sl_su c
            JOIN [PumpIT].dbo.DBF_POSTR_SUPPLIER d
              ON d.SSBranchId = @BranchId AND d.[DATE] = c.[DATE] AND d.[DESC] = c.[DESC] AND d.REF = c.REF
             AND d.[LOCATION] = c.[LOCATION] AND d.EOD_CNTR = c.EOD_CNTR
            CROSS APPLY (SELECT MIN(x.SupplierCode) AS Code, COUNT(*) AS n FROM [PumpIT].dbo.BRN_Suppliers x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.SupplierID)) = LTRIM(RTRIM(d.CNUM))) m
            WHERE m.n = 1;
        END

        -- Expenses <- BRN_Transaction (SSBranchId, TransactionNo, TypeCode): approved cash requests
        IF @Channel = 'Expenses'
        BEGIN
            CREATE TABLE #sl_ex (Seq INT PRIMARY KEY, TransactionNo NVARCHAR(15) COLLATE DATABASE_DEFAULT,
                                 TypeCode NVARCHAR(2) COLLATE DATABASE_DEFAULT, PriorShift INT NULL);
            INSERT INTO #sl_ex (Seq, TransactionNo, TypeCode)
            SELECT k.Seq, x.TransactionNo, x.TypeCode
            FROM #sl_k k CROSS APPLY OPENJSON(k.KeyJson) WITH (TransactionNo NVARCHAR(15) '$.TransactionNo', TypeCode NVARCHAR(2) '$.TypeCode') x;

            UPDATE c SET PriorShift = d.ShiftNo
            FROM #sl_ex c
            JOIN [PumpIT].dbo.BRN_Transaction d WITH (UPDLOCK, HOLDLOCK)
              ON d.SSBranchId = @BranchId AND d.TransactionNo = c.TransactionNo AND d.TypeCode = c.TypeCode;

            INSERT INTO #sl_c (Seq, Ref, Found, Flag, OnLeg, Problem)
            SELECT c.Seq, CONCAT('Purchase request ', LTRIM(RTRIM(c.TransactionNo))), CASE WHEN d.TransactionNo IS NULL THEN 0 ELSE 1 END,
                   d.Posted, o.OnLeg,
                   CASE WHEN d.TransactionNo IS NOT NULL AND NOT (d.TypeCode = '01' AND d.Approved = 1 AND d.IsDeclined = 0)
                        THEN 'it is not an approved cash purchase request' END
            FROM #sl_ex c
            LEFT JOIN [PumpIT].dbo.BRN_Transaction d
                   ON d.SSBranchId = @BranchId AND d.TransactionNo = c.TransactionNo AND d.TypeCode = c.TypeCode
            OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23)) AS OnLeg
                         FROM [PumpIT].dbo.BRN_DailyBankingExpenses x
                         WHERE x.SSBranchId = @BranchId AND LTRIM(RTRIM(x.TransactionNo)) = LTRIM(RTRIM(c.TransactionNo))) o;
        END

        /*
     STAFF SHORTS (T040, un-held 27 Sep 2026). The pump-shortages leg is chosen
     from the site's APPROVED staff shorts — dbo.sp_Load_ApprovedStaffShorts:
     Approved 1, IsDeclined 0, not yet on a cashup, the person on BRN_Employee —
     and saving one POSTS it: the line is copied as the legacy copies it (person,
     amount, note, reason and StaffShortsNo; 4,160 of 4,160 on the customer's
     instance) and the short is flagged IsSelectedForDailyBanking, as
     dbo.sp_IsSelectedForDailyBanking_DailyBanking_Update 'Staff Shorts' does.
     The flag is the one write on BRN_StaffShorts outside the Cash staff-shorts
     procedures, approved for this leg by name (T040 brief, 27 Sep 2026); the
     rules it is written under are those procedures' — approved, not declined,
     posted once. agora.usp_Cash_PostStaffShort calls this for the staff-shorts
     screen. Each short posted is also an agora.StaffShortEvent ('posted'), in
     the same transaction. PumpIT keys the leg ONE LINE PER PERSON PER CASHUP
     (SSBranchId, TillNo, ShiftNo, TransactionDate, EmployeeCode), so a second
     short for the same person on the same cashup is refused, not merged.
     A short raised by a cash-bag match is refused outright
     (CASH_SHORT_FROM_RECON, PM decision 01a0e5b5): deductions read it directly.

     (Kept here rather than in the header: with it in the header this procedure
     created cleanly and then failed to compile on its first EXEC, citing its own
     CREATE line — reproduced on the local container, 27 Sep 2026.)
        */
        -- Staff shorts <- BRN_StaffShorts (SSBranchId, StaffShortsNo): the approved ones
        -- (dbo.sp_Load_ApprovedStaffShorts), one pump-shortage line per person per cashup
        IF @Channel = 'StaffShorts'
        BEGIN
            CREATE TABLE #sl_ss (Seq INT PRIMARY KEY, StaffShortsNo INT NULL,
                                 EmployeeCode NVARCHAR(11) COLLATE DATABASE_DEFAULT NULL, Amount MONEY NULL,
                                 OnList BIT NOT NULL DEFAULT 0);
            INSERT INTO #sl_ss (Seq, StaffShortsNo)
            SELECT k.Seq, x.StaffShortsNo FROM #sl_k k CROSS APPLY OPENJSON(k.KeyJson) WITH (StaffShortsNo INT '$.StaffShortsNo') x;

            IF EXISTS (SELECT 1 FROM #sl_ss WHERE StaffShortsNo IS NULL)
                THROW 51000, 'AGORA:CASH_LEG_LINE:Every staff short ticked is named by its number, as the pool list gives it.', 1;

            /* A short a cash-bag match raised is never a cashup line (Ryan, PM
               decision 01a0e5b5): the cashup already counted those bags in
               full, so a pump-shortage line would put it over by the same
               amount. The deduction schedule reads such a short directly. */
            IF EXISTS (SELECT 1 FROM #sl_ss c
                       JOIN agora.StaffShort a ON a.BranchId = @BranchId AND a.StaffShortsNo = c.StaffShortsNo
                       WHERE a.Source = 'recon')
            BEGIN
                SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_SHORT_FROM_RECON:Staff short ', c.StaffShortsNo,
                                                ' was raised by a cash-bag match (', ISNULL(a.SourceRef, 'recon'),
                                                ') and is not put on a cashup: the cashup already counted those bags in full, and deductions read the short directly. Nothing was written.')
                            FROM #sl_ss c
                            JOIN agora.StaffShort a ON a.BranchId = @BranchId AND a.StaffShortsNo = c.StaffShortsNo
                            WHERE a.Source = 'recon' ORDER BY c.Seq);
                THROW 51000, @Msg, 1;
            END

            UPDATE c SET EmployeeCode = LTRIM(RTRIM(d.EmployeeCode)), Amount = d.AmountShort
            FROM #sl_ss c
            JOIN [PumpIT].dbo.BRN_StaffShorts d WITH (UPDLOCK, HOLDLOCK)
              ON d.SSBranchId = @BranchId AND d.StaffShortsNo = c.StaffShortsNo;

            UPDATE c SET OnList = 1
            FROM #sl_ss c
            WHERE EXISTS (SELECT 1 FROM [PumpIT].dbo.BRN_Employee e WHERE e.SSBranchId = @BranchId AND e.EmployeeCode = c.EmployeeCode);

            INSERT INTO #sl_c (Seq, Ref, Found, Flag, OnLeg, Problem)
            SELECT c.Seq, CONCAT('Staff short ', ISNULL(CONVERT(NVARCHAR(12), c.StaffShortsNo), '?')),
                   CASE WHEN d.StaffShortsNo IS NULL THEN 0 ELSE 1 END,
                   ISNULL(d.IsSelectedForDailyBanking, 0), o.OnLeg,
                   CASE WHEN d.StaffShortsNo IS NULL THEN NULL
                        WHEN d.IsDeclined = 1 THEN 'it was declined'
                        WHEN d.Approved = 0 THEN 'it is waiting for approval — only an approved short is posted'
                        WHEN ISNULL(d.AmountShort, 0) <= 0 THEN 'it carries no amount'
                        WHEN c.OnList = 0
                             THEN CONCAT('its person ', c.EmployeeCode, ' is not on PumpIT''s employee list, so the cashup would not count it')
                        WHEN EXISTS (SELECT 1 FROM #sl_ss z WHERE z.EmployeeCode = c.EmployeeCode AND z.Seq < c.Seq)
                             THEN CONCAT('another short ticked is ', c.EmployeeCode, '''s too — a cashup carries one pump-shortage line per person')
                        WHEN EXISTS (SELECT 1 FROM [PumpIT].dbo.BRN_DailyBankingEmployees y WITH (UPDLOCK, HOLDLOCK)
                                     WHERE y.SSBranchId = @BranchId AND y.TillNo = @TillNo AND y.ShiftNo = @ShiftNo
                                       AND y.TransactionDate = @At AND y.EmployeeCode = c.EmployeeCode)
                             THEN CONCAT('this cashup already has a pump-shortage line for ', c.EmployeeCode,
                                         ' — PumpIT keeps one per person per cashup')
                        END
            FROM #sl_ss c
            LEFT JOIN [PumpIT].dbo.BRN_StaffShorts d ON d.SSBranchId = @BranchId AND d.StaffShortsNo = c.StaffShortsNo
            OUTER APPLY (SELECT TOP 1 CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23)) AS OnLeg
                         FROM [PumpIT].dbo.BRN_DailyBankingEmployees x
                         WHERE x.SSBranchId = @BranchId AND x.StaffShortsNo = c.StaffShortsNo AND ISNULL(c.StaffShortsNo, 0) <> 0) o;
        END

        /* ============================================= POOL LEGS: the refusals */
        IF @IsPool = 1
        BEGIN
            IF EXISTS (SELECT 1 FROM #sl_c WHERE Found = 0)
            BEGIN
                SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_POOL_NOT_FOUND:', Ref, ' is not in PumpIT''s ', @PoolName,
                                                ' for this site. Nothing was written.')
                            FROM #sl_c WHERE Found = 0 ORDER BY Seq);
                THROW 51000, @Msg, 1;
            END

            IF EXISTS (SELECT 1 FROM #sl_c WHERE OnLeg IS NOT NULL OR Flag = 1)
            BEGIN
                SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_POOL_BANKED:', Ref, ' is already banked — ',
                                                CASE WHEN OnLeg IS NOT NULL THEN CONCAT('on the cashup for ', OnLeg)
                                                     ELSE CONCAT('PumpIT marks it banked (', @Mark, ') though no cashup carries it') END,
                                                '. Nothing was written.')
                            FROM #sl_c WHERE OnLeg IS NOT NULL OR Flag = 1 ORDER BY Seq);
                THROW 51000, @Msg, 1;
            END

            IF EXISTS (SELECT 1 FROM #sl_c WHERE Problem IS NOT NULL)
            BEGIN
                SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_POOL_MASTER:', Ref, ' cannot be banked: ', Problem, '. Nothing was written.')
                            FROM #sl_c WHERE Problem IS NOT NULL ORDER BY Seq);
                THROW 51000, @Msg, 1;
            END
        END

        /* ============================================ POOL LEGS: write and mark */

        IF @Channel = 'CashBags'
        BEGIN
            -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, CashBagNo, CashBagAmount, CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingCashBags
                (SSBranchId, TillNo, ShiftNo, TransactionDate, CashBagNo, CashBagAmount, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, d.BagNo, d.Amount, GETDATE()
            FROM #sl_cb c JOIN [PumpIT].dbo.BRN_DropSafe d ON d.SSBranchId = @BranchId AND d.BagNo = c.BagNo;
            SET @Inserted = @@ROWCOUNT;

            INSERT INTO @W ([Action], KeyJson, NewJson, PoolKey, PoolPrior, PoolNew)
            SELECT 'insert', (SELECT d.BagNo AS CashBagNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT d.Amount AS CashBagAmount FOR JSON PATH, WITHOUT_ARRAY_WRAPPER), k.KeyJson,
                   '{"IsSelectedForDailyBanking":0}', '{"IsSelectedForDailyBanking":1}'
            FROM #sl_cb c JOIN #sl_k k ON k.Seq = c.Seq
            JOIN [PumpIT].dbo.BRN_DropSafe d ON d.SSBranchId = @BranchId AND d.BagNo = c.BagNo ORDER BY c.Seq;

            UPDATE d SET d.IsSelectedForDailyBanking = 1
            FROM [PumpIT].dbo.BRN_DropSafe d JOIN #sl_cb c ON d.SSBranchId = @BranchId AND d.BagNo = c.BagNo
            WHERE d.IsSelectedForDailyBanking = 0;
            SET @Marked = @@ROWCOUNT;
        END

        IF @Channel = 'CashDevices'
        BEGIN
            -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, TerminalNo, SlipNo, DepositaAmount, CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingDeposita
                (SSBranchId, TillNo, ShiftNo, TransactionDate, TerminalNo, SlipNo, DepositaAmount, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, d.SafeId, d.TransactionNo, d.TransactionValue, GETDATE()
            FROM #sl_cd c JOIN [PumpIT].dbo.BRN_CashDevices d
              ON d.SSBranchId = @BranchId AND d.TransactionDateTime = c.TxAt AND d.SafeId = c.SafeId AND d.TransactionNo = c.TransactionNo;
            SET @Inserted = @@ROWCOUNT;

            INSERT INTO @W ([Action], KeyJson, NewJson, PoolKey, PoolPrior, PoolNew)
            SELECT 'insert', (SELECT d.SafeId AS TerminalNo, d.TransactionNo AS SlipNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT d.TransactionValue AS DepositaAmount FOR JSON PATH, WITHOUT_ARRAY_WRAPPER), k.KeyJson,
                   '{"IsSelectedForDailyBanking":0}', '{"IsSelectedForDailyBanking":1}'
            FROM #sl_cd c JOIN #sl_k k ON k.Seq = c.Seq
            JOIN [PumpIT].dbo.BRN_CashDevices d
              ON d.SSBranchId = @BranchId AND d.TransactionDateTime = c.TxAt AND d.SafeId = c.SafeId AND d.TransactionNo = c.TransactionNo
            ORDER BY c.Seq;

            UPDATE d SET d.IsSelectedForDailyBanking = 1
            FROM [PumpIT].dbo.BRN_CashDevices d JOIN #sl_cd c
              ON d.SSBranchId = @BranchId AND d.TransactionDateTime = c.TxAt AND d.SafeId = c.SafeId AND d.TransactionNo = c.TransactionNo
            WHERE d.IsSelectedForDailyBanking = 0;
            SET @Marked = @@ROWCOUNT;
        END

        IF @Channel = 'SmartATM'
        BEGIN
            -- INSERT SSBranchId, TillNo, ShiftNo, DepositDateTime (the cashup's day), TerminalId, TraceNo, UniqueNo,
            --        Deposited, SettlementDate, SubTerminalId, CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingSmartATM
                (SSBranchId, TillNo, ShiftNo, DepositDateTime, TerminalId, TraceNo, UniqueNo, Deposited,
                 SettlementDate, SubTerminalId, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, d.TerminalId, d.TraceNo, d.UniqueNo, d.Deposited,
                   d.SettlementDate, d.SubTerminalId, GETDATE()
            FROM #sl_sa c JOIN [PumpIT].dbo.BRN_SmartATM d
              ON d.SSBranchId = @BranchId AND d.DepositDateTime = c.DepAt AND d.TraceNo = c.TraceNo AND d.TerminalId = c.TerminalId;
            SET @Inserted = @@ROWCOUNT;

            INSERT INTO @W ([Action], KeyJson, NewJson, PoolKey, PoolPrior, PoolNew)
            SELECT 'insert', (SELECT d.TerminalId AS TerminalId, d.TraceNo AS TraceNo, CONVERT(DECIMAL(38, 6), TRY_CONVERT(FLOAT, d.UniqueNo)) AS UniqueNo
                              FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT d.Deposited AS Deposited FOR JSON PATH, WITHOUT_ARRAY_WRAPPER), k.KeyJson,
                   '{"IsSelectedForDailyBanking":0}', '{"IsSelectedForDailyBanking":1}'
            FROM #sl_sa c JOIN #sl_k k ON k.Seq = c.Seq
            JOIN [PumpIT].dbo.BRN_SmartATM d
              ON d.SSBranchId = @BranchId AND d.DepositDateTime = c.DepAt AND d.TraceNo = c.TraceNo AND d.TerminalId = c.TerminalId
            ORDER BY c.Seq;

            UPDATE d SET d.IsSelectedForDailyBanking = 1
            FROM [PumpIT].dbo.BRN_SmartATM d JOIN #sl_sa c
              ON d.SSBranchId = @BranchId AND d.DepositDateTime = c.DepAt AND d.TraceNo = c.TraceNo AND d.TerminalId = c.TerminalId
            WHERE d.IsSelectedForDailyBanking = 0;
            SET @Marked = @@ROWCOUNT;
        END

        IF @Channel = 'ABSA'
        BEGIN
            -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, RetrievalRefNumber, TransactionAuthCode,
            --        ABSA_TransactionDate, ABSA_TransactionTime, TransactionAmount, BatchNumber, MerchantNumber, CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingABSA
                (SSBranchId, TillNo, ShiftNo, TransactionDate, RetrievalRefNumber, TransactionAuthCode, ABSA_TransactionDate,
                 ABSA_TransactionTime, TransactionAmount, BatchNumber, MerchantNumber, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, d.Dtl23_RetrievalRefNumber, ISNULL(d.Dtl12_TransactionAuthCode, ''),
                   d.Dtl5_TransactionDate, d.Dtl8_TransactionTime, d.Dtl9_TransactionAmount, d.Dtl22BatchNumber,
                   d.Dtl3_MerchantNumber, GETDATE()
            FROM #sl_ab c JOIN [PumpIT].dbo.BRN_ABSAMarkoff d
              ON d.SSBranchId = @BranchId AND d.Dtl5_TransactionDate = c.TxDate AND d.Dtl8_TransactionTime = c.TxTime
             AND d.Dtl9_TransactionAmount = c.Amount AND d.Dtl23_RetrievalRefNumber = c.Rrn;
            SET @Inserted = @@ROWCOUNT;

            INSERT INTO @W ([Action], KeyJson, NewJson, PoolKey, PoolPrior, PoolNew)
            SELECT 'insert', (SELECT d.Dtl23_RetrievalRefNumber AS RetrievalRefNumber, d.Dtl12_TransactionAuthCode AS TransactionAuthCode,
                                     CONVERT(VARCHAR(23), d.Dtl5_TransactionDate, 126) AS ABSA_TransactionDate,
                                     CONVERT(VARCHAR(23), d.Dtl8_TransactionTime, 126) AS ABSA_TransactionTime
                              FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT d.Dtl9_TransactionAmount AS TransactionAmount, d.Dtl22BatchNumber AS BatchNumber
                    FOR JSON PATH, WITHOUT_ARRAY_WRAPPER), k.KeyJson,
                   '{"IsSelectedForDailyBanking":0}', '{"IsSelectedForDailyBanking":1}'
            FROM #sl_ab c JOIN #sl_k k ON k.Seq = c.Seq
            JOIN [PumpIT].dbo.BRN_ABSAMarkoff d
              ON d.SSBranchId = @BranchId AND d.Dtl5_TransactionDate = c.TxDate AND d.Dtl8_TransactionTime = c.TxTime
             AND d.Dtl9_TransactionAmount = c.Amount AND d.Dtl23_RetrievalRefNumber = c.Rrn
            ORDER BY c.Seq;

            UPDATE d SET d.IsSelectedForDailyBanking = 1
            FROM [PumpIT].dbo.BRN_ABSAMarkoff d JOIN #sl_ab c
              ON d.SSBranchId = @BranchId AND d.Dtl5_TransactionDate = c.TxDate AND d.Dtl8_TransactionTime = c.TxTime
             AND d.Dtl9_TransactionAmount = c.Amount AND d.Dtl23_RetrievalRefNumber = c.Rrn
            WHERE d.IsSelectedForDailyBanking = 0;
            SET @Marked = @@ROWCOUNT;
        END

        IF @Channel = 'Yumbi'
        BEGIN
            -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, ID, NetAmount, DiscountAmount, CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingYumbi
                (SSBranchId, TillNo, ShiftNo, TransactionDate, ID, NetAmount, DiscountAmount, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, d.ID, d.NetAmount, d.DiscountAmount, GETDATE()
            FROM #sl_yu c JOIN [PumpIT].dbo.BRN_Yumbi d ON d.SSBranchId = @BranchId AND d.ID = c.ID;
            SET @Inserted = @@ROWCOUNT;

            INSERT INTO @W ([Action], KeyJson, NewJson, PoolKey, PoolPrior, PoolNew)
            SELECT 'insert', (SELECT d.ID AS ID FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT d.NetAmount AS NetAmount, d.DiscountAmount AS DiscountAmount FOR JSON PATH, WITHOUT_ARRAY_WRAPPER), k.KeyJson,
                   CONCAT('{"IsSelectedForDailyBanking":', CASE WHEN d.IsSelectedForDailyBanking IS NULL THEN 'null' ELSE '0' END, '}'),
                   '{"IsSelectedForDailyBanking":1}'
            FROM #sl_yu c JOIN #sl_k k ON k.Seq = c.Seq
            JOIN [PumpIT].dbo.BRN_Yumbi d ON d.SSBranchId = @BranchId AND d.ID = c.ID ORDER BY c.Seq;

            UPDATE d SET d.IsSelectedForDailyBanking = 1
            FROM [PumpIT].dbo.BRN_Yumbi d JOIN #sl_yu c ON d.SSBranchId = @BranchId AND d.ID = c.ID
            WHERE ISNULL(d.IsSelectedForDailyBanking, 0) = 0;
            SET @Marked = @@ROWCOUNT;
        END

        IF @Channel = 'YumbiVoucher'
        BEGIN
            -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, RedemptionDate, RedeemedAmount, CustomerName,
            --        CustomerReference, VoucherType, CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingYumbiVoucher
                (SSBranchId, TillNo, ShiftNo, TransactionDate, RedemptionDate, RedeemedAmount, CustomerName,
                 CustomerReference, VoucherType, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, d.RedemptionDate, d.RedeemedAmount, d.CustomerName,
                   d.CustomerReference, d.VoucherType, GETDATE()
            FROM #sl_yv c JOIN [PumpIT].dbo.BRN_YumbiVoucher d
              ON d.SSBranchId = @BranchId AND d.RedemptionDate = c.RedAt AND d.CustomerReference = c.CustRef;
            SET @Inserted = @@ROWCOUNT;

            INSERT INTO @W ([Action], KeyJson, NewJson, PoolKey, PoolPrior, PoolNew)
            SELECT 'insert', (SELECT CONVERT(VARCHAR(23), d.RedemptionDate, 126) AS RedemptionDate, d.CustomerReference AS CustomerReference
                              FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT d.RedeemedAmount AS RedeemedAmount FOR JSON PATH, WITHOUT_ARRAY_WRAPPER), k.KeyJson,
                   '{"IsSelectedForDailyBanking":0}', '{"IsSelectedForDailyBanking":1}'
            FROM #sl_yv c JOIN #sl_k k ON k.Seq = c.Seq
            JOIN [PumpIT].dbo.BRN_YumbiVoucher d
              ON d.SSBranchId = @BranchId AND d.RedemptionDate = c.RedAt AND d.CustomerReference = c.CustRef ORDER BY c.Seq;

            UPDATE d SET d.IsSelectedForDailyBanking = 1
            FROM [PumpIT].dbo.BRN_YumbiVoucher d JOIN #sl_yv c
              ON d.SSBranchId = @BranchId AND d.RedemptionDate = c.RedAt AND d.CustomerReference = c.CustRef
            WHERE d.IsSelectedForDailyBanking = 0;
            SET @Marked = @@ROWCOUNT;
        END

        IF @Channel = 'Infinity'
        BEGIN
            -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, CardHolder, RewardReference, RedeemReference,
            --        RewardPurchaseValue, TerminalReference, TerminalQuantity, RedeemPointsValue,
            --        TransactionDate_Infinity, CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingInfinity
                (SSBranchId, TillNo, ShiftNo, TransactionDate, CardHolder, RewardReference, RedeemReference,
                 RewardPurchaseValue, TerminalReference, TerminalQuantity, RedeemPointsValue, TransactionDate_Infinity, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, d.Cardholder, d.RewardReference, d.RedeemReference,
                   d.RewardPurchaseValue, d.TerminalReference, d.TerminalQuantity, CONVERT(MONEY, d.RedeemPointsValue),
                   d.TransactionDate, GETDATE()
            FROM #sl_in c JOIN [PumpIT].dbo.BRN_Infinity d
              ON d.SSBranchId = @BranchId AND d.TransactionDate = c.TxDate AND d.Cardholder = c.Cardholder
             AND d.RewardPurchaseValue = c.Rpv AND d.RewardReference = c.RewardRef AND d.RedeemReference = c.RedeemRef
             AND d.TerminalReference = c.TermRef AND d.TerminalQuantity = c.TermQty;
            SET @Inserted = @@ROWCOUNT;

            INSERT INTO @W ([Action], KeyJson, NewJson, PoolKey, PoolPrior, PoolNew)
            SELECT 'insert', (SELECT d.Cardholder AS CardHolder, d.RedeemReference AS RedeemReference, d.RewardReference AS RewardReference
                              FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT CONVERT(MONEY, d.RedeemPointsValue) AS RedeemPointsValue FOR JSON PATH, WITHOUT_ARRAY_WRAPPER), k.KeyJson,
                   CONCAT('{"IsSelectedForDailyBanking":', CASE WHEN d.IsSelectedForDailyBanking IS NULL THEN 'null' ELSE '0' END, '}'),
                   '{"IsSelectedForDailyBanking":1}'
            FROM #sl_in c JOIN #sl_k k ON k.Seq = c.Seq
            JOIN [PumpIT].dbo.BRN_Infinity d
              ON d.SSBranchId = @BranchId AND d.TransactionDate = c.TxDate AND d.Cardholder = c.Cardholder
             AND d.RewardPurchaseValue = c.Rpv AND d.RewardReference = c.RewardRef AND d.RedeemReference = c.RedeemRef
             AND d.TerminalReference = c.TermRef AND d.TerminalQuantity = c.TermQty
            ORDER BY c.Seq;

            UPDATE d SET d.IsSelectedForDailyBanking = 1
            FROM [PumpIT].dbo.BRN_Infinity d JOIN #sl_in c
              ON d.SSBranchId = @BranchId AND d.TransactionDate = c.TxDate AND d.Cardholder = c.Cardholder
             AND d.RewardPurchaseValue = c.Rpv AND d.RewardReference = c.RewardRef AND d.RedeemReference = c.RedeemRef
             AND d.TerminalReference = c.TermRef AND d.TerminalQuantity = c.TermQty
            WHERE ISNULL(d.IsSelectedForDailyBanking, 0) = 0;
            SET @Marked = @@ROWCOUNT;
        END

        IF @Channel = 'Zapper'
        BEGIN
            -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, UniqueField, Reference1, Status, NettTurnover,
            --        FeeTotal, ProcessingFeeVAT, VoucherFee, CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingZapper
                (SSBranchId, TillNo, ShiftNo, TransactionDate, UniqueField, Reference1, [Status], NettTurnover,
                 FeeTotal, ProcessingFeeVAT, VoucherFee, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, d.UniqueField, d.Reference1, d.[Status], d.NettTurnover,
                   d.FeeTotal, d.ProcessingFeeVAT, d.VoucherFee, GETDATE()
            FROM #sl_za c JOIN [PumpIT].dbo.BRN_Zapper d
              ON d.SSBranchId = @BranchId AND d.TransactionDate = c.TxDate AND d.UniqueField = c.UniqueField;
            SET @Inserted = @@ROWCOUNT;

            INSERT INTO @W ([Action], KeyJson, NewJson, PoolKey, PoolPrior, PoolNew)
            SELECT 'insert', (SELECT d.UniqueField AS UniqueField FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT d.NettTurnover AS NettTurnover FOR JSON PATH, WITHOUT_ARRAY_WRAPPER), k.KeyJson,
                   '{"IsSelectedForDailyBanking":0}', '{"IsSelectedForDailyBanking":1}'
            FROM #sl_za c JOIN #sl_k k ON k.Seq = c.Seq
            JOIN [PumpIT].dbo.BRN_Zapper d
              ON d.SSBranchId = @BranchId AND d.TransactionDate = c.TxDate AND d.UniqueField = c.UniqueField ORDER BY c.Seq;

            UPDATE d SET d.IsSelectedForDailyBanking = 1
            FROM [PumpIT].dbo.BRN_Zapper d JOIN #sl_za c
              ON d.SSBranchId = @BranchId AND d.TransactionDate = c.TxDate AND d.UniqueField = c.UniqueField
            WHERE d.IsSelectedForDailyBanking = 0;
            SET @Marked = @@ROWCOUNT;
        END

        IF @Channel = 'Debtors'
        BEGIN
            -- The next line number on this cashup's accounts leg, taken under lock.
            DECLARE @NextItem INT = (SELECT ISNULL(MAX(x.ItemNo), 0)
                                     FROM [PumpIT].dbo.BRN_DailyBankingCustomers x WITH (UPDLOCK, HOLDLOCK)
                                     WHERE x.SSBranchId = @BranchId AND x.TillNo = @TillNo AND x.ShiftNo = @ShiftNo
                                       AND x.TransactionDate = @At);

            UPDATE c SET ItemNo = n.ItemNo
            FROM #sl_de c
            JOIN (SELECT Seq, @NextItem + ROW_NUMBER() OVER (ORDER BY Seq) AS ItemNo FROM #sl_de) n ON n.Seq = c.Seq;

            -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, CustomerCode, ItemNo, FuelAmount, PaymentAmount (0),
            --        Imported (0), Reference1 (the POS ref), Reference2 (the POS description), CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingCustomers
                (SSBranchId, TillNo, ShiftNo, TransactionDate, CustomerCode, ItemNo, FuelAmount, PaymentAmount, Imported,
                 Reference1, Reference2, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, c.CustomerCode, c.ItemNo,
                   CONVERT(MONEY, d.TOTAL), 0, 0, RTRIM(d.REF), CONVERT(NVARCHAR(50), d.[DESC]), GETDATE()
            FROM #sl_de c JOIN [PumpIT].dbo.DBF_INVHISTH_CUSTOMER d
              ON d.SSBranchId = @BranchId AND d.DRC = c.DRC AND d.LOGFILE = c.LOGFILE AND d.[TYPE] = c.[TYPE] AND d.REF = c.REF
             AND d.[DATE] = c.[DATE] AND d.CNUM = c.CNUM AND d.[LOCATION] = c.[LOCATION] AND d.EOD_CNTR = c.EOD_CNTR;
            SET @Inserted = @@ROWCOUNT;

            INSERT INTO @W ([Action], KeyJson, NewJson, PoolKey, PoolPrior, PoolNew)
            SELECT 'insert', (SELECT c.CustomerCode AS CustomerCode, c.ItemNo AS ItemNo,
                                     RTRIM(d.REF) AS Reference1 FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT CONVERT(MONEY, d.TOTAL) AS FuelAmount FOR JSON PATH, WITHOUT_ARRAY_WRAPPER), k.KeyJson,
                   '{"IsSelectedForBanking":0}', '{"IsSelectedForBanking":1}'
            FROM #sl_de c JOIN #sl_k k ON k.Seq = c.Seq
            JOIN [PumpIT].dbo.DBF_INVHISTH_CUSTOMER d
              ON d.SSBranchId = @BranchId AND d.DRC = c.DRC AND d.LOGFILE = c.LOGFILE AND d.[TYPE] = c.[TYPE] AND d.REF = c.REF
             AND d.[DATE] = c.[DATE] AND d.CNUM = c.CNUM AND d.[LOCATION] = c.[LOCATION] AND d.EOD_CNTR = c.EOD_CNTR
            ORDER BY c.Seq;

            UPDATE d SET d.IsSelectedForBanking = 1
            FROM [PumpIT].dbo.DBF_INVHISTH_CUSTOMER d JOIN #sl_de c
              ON d.SSBranchId = @BranchId AND d.DRC = c.DRC AND d.LOGFILE = c.LOGFILE AND d.[TYPE] = c.[TYPE] AND d.REF = c.REF
             AND d.[DATE] = c.[DATE] AND d.CNUM = c.CNUM AND d.[LOCATION] = c.[LOCATION] AND d.EOD_CNTR = c.EOD_CNTR
            WHERE d.IsSelectedForBanking = 0;
            SET @Marked = @@ROWCOUNT;
        END

        IF @Channel = 'Suppliers'
        BEGIN
            -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, SupplierCode, DocumentNo (the POS ref),
            --        SupplierAmount, Imported (0), Reference (the POS description), CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingSuppliers
                (SSBranchId, TillNo, ShiftNo, TransactionDate, SupplierCode, DocumentNo, SupplierAmount, Imported,
                 Reference, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, c.SupplierCode, CONVERT(NVARCHAR(20), d.REF), CONVERT(MONEY, d.TOTAL), 0,
                   CONVERT(NVARCHAR(50), d.[DESC]), GETDATE()
            FROM #sl_su c JOIN [PumpIT].dbo.DBF_POSTR_SUPPLIER d
              ON d.SSBranchId = @BranchId AND d.[DATE] = c.[DATE] AND d.[DESC] = c.[DESC] AND d.REF = c.REF
             AND d.[LOCATION] = c.[LOCATION] AND d.EOD_CNTR = c.EOD_CNTR;
            SET @Inserted = @@ROWCOUNT;

            INSERT INTO @W ([Action], KeyJson, NewJson, PoolKey, PoolPrior, PoolNew)
            SELECT 'insert', (SELECT c.SupplierCode AS SupplierCode, CONVERT(NVARCHAR(20), d.REF) AS DocumentNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT CONVERT(MONEY, d.TOTAL) AS SupplierAmount FOR JSON PATH, WITHOUT_ARRAY_WRAPPER), k.KeyJson,
                   '{"IsSelectedForBanking":0}', '{"IsSelectedForBanking":1}'
            FROM #sl_su c JOIN #sl_k k ON k.Seq = c.Seq
            JOIN [PumpIT].dbo.DBF_POSTR_SUPPLIER d
              ON d.SSBranchId = @BranchId AND d.[DATE] = c.[DATE] AND d.[DESC] = c.[DESC] AND d.REF = c.REF
             AND d.[LOCATION] = c.[LOCATION] AND d.EOD_CNTR = c.EOD_CNTR
            ORDER BY c.Seq;

            UPDATE d SET d.IsSelectedForBanking = 1
            FROM [PumpIT].dbo.DBF_POSTR_SUPPLIER d JOIN #sl_su c
              ON d.SSBranchId = @BranchId AND d.[DATE] = c.[DATE] AND d.[DESC] = c.[DESC] AND d.REF = c.REF
             AND d.[LOCATION] = c.[LOCATION] AND d.EOD_CNTR = c.EOD_CNTR
            WHERE d.IsSelectedForBanking = 0;
            SET @Marked = @@ROWCOUNT;
        END

        IF @Channel = 'Expenses'
        BEGIN
            -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, TransactionNo, SupplierCode,
            --        ExpenseAmount (the request's lines, summed), Imported (0), CreateDateTime
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingExpenses
                (SSBranchId, TillNo, ShiftNo, TransactionDate, TransactionNo, SupplierCode, ExpenseAmount, Imported, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, d.TransactionNo, d.SupplierCode, ISNULL(s.Amount, 0), 0, GETDATE()
            FROM #sl_ex c JOIN [PumpIT].dbo.BRN_Transaction d
              ON d.SSBranchId = @BranchId AND d.TransactionNo = c.TransactionNo AND d.TypeCode = c.TypeCode
            OUTER APPLY (SELECT CONVERT(MONEY, SUM(ISNULL(y.PurchaseAmount, 0))) AS Amount FROM [PumpIT].dbo.BRN_TransactionLine y
                         WHERE y.SSBranchId = d.SSBranchId AND y.TransactionNo = d.TransactionNo AND y.TypeCode = d.TypeCode) s;
            SET @Inserted = @@ROWCOUNT;

            INSERT INTO @W ([Action], KeyJson, NewJson, PoolKey, PoolPrior, PoolNew)
            SELECT 'insert', (SELECT d.TransactionNo AS TransactionNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT ISNULL(s.Amount, 0) AS ExpenseAmount, d.SupplierCode AS SupplierCode FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   k.KeyJson,
                   (SELECT 0 AS Posted, c.PriorShift AS ShiftNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES),
                   (SELECT 1 AS Posted, @ShiftNo AS ShiftNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)
            FROM #sl_ex c JOIN #sl_k k ON k.Seq = c.Seq
            JOIN [PumpIT].dbo.BRN_Transaction d
              ON d.SSBranchId = @BranchId AND d.TransactionNo = c.TransactionNo AND d.TypeCode = c.TypeCode
            OUTER APPLY (SELECT CONVERT(MONEY, SUM(ISNULL(y.PurchaseAmount, 0))) AS Amount FROM [PumpIT].dbo.BRN_TransactionLine y
                         WHERE y.SSBranchId = d.SSBranchId AND y.TransactionNo = d.TransactionNo AND y.TypeCode = d.TypeCode) s
            ORDER BY c.Seq;

            -- UPDATE Posted (0 -> 1) and ShiftNo, as dbo.sp_IsSelectedForDailyBanking_DailyBanking_Update does
            UPDATE d SET d.Posted = 1, d.ShiftNo = @ShiftNo
            FROM [PumpIT].dbo.BRN_Transaction d JOIN #sl_ex c
              ON d.SSBranchId = @BranchId AND d.TransactionNo = c.TransactionNo AND d.TypeCode = c.TypeCode
            WHERE d.Posted = 0;
            SET @Marked = @@ROWCOUNT;
        END

        IF @Channel = 'StaffShorts'
        BEGIN
            -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, EmployeeCode, AmountShort, Imported (0),
            --        Note, Reason, StaffShortsNo, CreateDateTime — the short's own, as the legacy copies them
            INSERT INTO [PumpIT].dbo.BRN_DailyBankingEmployees
                (SSBranchId, TillNo, ShiftNo, TransactionDate, EmployeeCode, AmountShort, Imported, Note, Reason,
                 StaffShortsNo, CreateDateTime)
            SELECT @BranchId, @TillNo, @ShiftNo, @At, c.EmployeeCode, d.AmountShort, 0, d.Note, d.Reason,
                   d.StaffShortsNo, GETDATE()
            FROM #sl_ss c JOIN [PumpIT].dbo.BRN_StaffShorts d ON d.SSBranchId = @BranchId AND d.StaffShortsNo = c.StaffShortsNo;
            SET @Inserted = @@ROWCOUNT;

            INSERT INTO @W ([Action], KeyJson, NewJson, PoolKey, PoolPrior, PoolNew)
            SELECT 'insert', (SELECT c.EmployeeCode AS EmployeeCode, c.StaffShortsNo AS StaffShortsNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   (SELECT c.Amount AS AmountShort FOR JSON PATH, WITHOUT_ARRAY_WRAPPER), k.KeyJson,
                   '{"IsSelectedForDailyBanking":0}', '{"IsSelectedForDailyBanking":1}'
            FROM #sl_ss c JOIN #sl_k k ON k.Seq = c.Seq ORDER BY c.Seq;

            -- UPDATE IsSelectedForDailyBanking (0 -> 1), as dbo.sp_IsSelectedForDailyBanking_DailyBanking_Update does
            UPDATE d SET d.IsSelectedForDailyBanking = 1
            FROM [PumpIT].dbo.BRN_StaffShorts d JOIN #sl_ss c ON d.SSBranchId = @BranchId AND d.StaffShortsNo = c.StaffShortsNo
            WHERE ISNULL(d.IsSelectedForDailyBanking, 0) = 0 AND d.Approved = 1 AND d.IsDeclined = 0;
            SET @Marked = @@ROWCOUNT;

            SET @Posted = (SELECT STRING_AGG(CONVERT(NVARCHAR(12), StaffShortsNo), ', ') WITHIN GROUP (ORDER BY Seq) FROM #sl_ss);

            /* The staff short's own trail: posted, to this cashup. */
            INSERT INTO agora.StaffShortEvent
                (BranchId, StaffShortsNo, [Event], EmployeeCode, Amount, ReasonCode, TransactionDate, TillNo, ShiftNo,
                 WriteMode, SaveRef, WritesJson, Note, CreatedAt, CreatedBy)
            SELECT @BranchId, c.StaffShortsNo, 'posted', c.EmployeeCode, c.Amount, a.ReasonCode, @TransactionDate, @TillNo, @ShiftNo,
                   'live', @Ref,
                   (SELECT JSON_QUERY((SELECT 'insert' AS [action], 'BRN_DailyBankingEmployees' AS [table],
                                              JSON_QUERY((SELECT @BranchId AS SSBranchId, @TillNo AS TillNo, @ShiftNo AS ShiftNo,
                                                                 CONVERT(CHAR(10), @TransactionDate, 23) AS TransactionDate,
                                                                 c.EmployeeCode AS EmployeeCode FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS [key],
                                              JSON_QUERY((SELECT c.Amount AS AmountShort, c.StaffShortsNo AS StaffShortsNo
                                                          FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS new
                                       FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS leg,
                           JSON_QUERY((SELECT 'update' AS [action], 'BRN_StaffShorts' AS [table],
                                              JSON_QUERY((SELECT @BranchId AS SSBranchId, c.StaffShortsNo AS StaffShortsNo
                                                          FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS [key],
                                              JSON_QUERY('{"IsSelectedForDailyBanking":0}') AS prior,
                                              JSON_QUERY('{"IsSelectedForDailyBanking":1}') AS new
                                       FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS short
                    FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                   @Note, @Now, @UserId
            FROM #sl_ss c
            OUTER APPLY (SELECT TOP 1 x.ReasonCode FROM agora.StaffShort x
                         WHERE x.BranchId = @BranchId AND x.StaffShortsNo = c.StaffShortsNo) a
            ORDER BY c.Seq;
        END

        /* Every pool row ticked became exactly one leg row and one mark, or
           nothing is kept. */
        IF @IsPool = 1 AND (@Inserted <> (SELECT COUNT(*) FROM #sl_k) OR @Marked <> @Inserted)
            THROW 51000, 'AGORA:CASH_POOL_MOVED:A pool row changed while the save ran, so the lines written did not match the rows ticked. Nothing was written.', 1;

        /* The header's totals, from the one place they are computed — which,
           inside this transaction, already sees the lines just written. */
        SELECT @Actual = t.ActualTotal, @Fnb = t.FNB
        FROM agora.vw_CashupTotals t
        WHERE t.BranchId = @BranchId AND t.TillNo = @TillNo AND t.ShiftNo = @ShiftNo AND t.TransactionAt = @At;

        SELECT @ZRead = CASE WHEN ISNULL(@Zreads, 0) = 1 THEN @ZSales ELSE h.ClosingBalance END
        FROM [PumpIT].dbo.BRN_DailyBanking h
        WHERE h.SSBranchId = @BranchId AND h.TillNo = @TillNo AND h.ShiftNo = @ShiftNo AND h.TransactionDate = @At;

        SET @Variance = ISNULL(@Actual, 0) - ISNULL(@ZRead, 0);

        UPDATE h
           SET h.ClosingBalance   = @ZRead,
               h.EmployeeAmount   = @Variance,
               h.CreditCardAmount = CASE WHEN @Channel = 'FNB' THEN ISNULL(@Fnb, 0) ELSE h.CreditCardAmount END,
               h.CardAmount1      = CASE WHEN @Channel = 'FNB' THEN ISNULL(@Fnb, 0) ELSE h.CardAmount1 END,
               h.Note             = CASE WHEN @Note IS NOT NULL THEN @Note ELSE h.Note END
        FROM [PumpIT].dbo.BRN_DailyBanking h
        WHERE h.SSBranchId = @BranchId AND h.TillNo = @TillNo AND h.ShiftNo = @ShiftNo AND h.TransactionDate = @At
          AND (   ISNULL(h.ClosingBalance, -922337203685477) <> ISNULL(@ZRead, -922337203685477)
               OR ISNULL(h.EmployeeAmount, -922337203685477) <> @Variance
               OR (@Channel = 'FNB' AND (ISNULL(h.CreditCardAmount, -922337203685477) <> ISNULL(@Fnb, 0)
                                         OR ISNULL(h.CardAmount1, -922337203685477) <> ISNULL(@Fnb, 0)))
               OR (@Note IS NOT NULL AND ISNULL(CONVERT(NVARCHAR(MAX), h.Note), N'') <> @Note));

        SET @HeaderChanged = CASE WHEN @@ROWCOUNT > 0 OR @Created = 1 THEN 1 ELSE 0 END;

        /* Agora's record of the cashup. */
        IF @CashupId IS NULL
        BEGIN
            INSERT INTO agora.Cashup (BranchId, TransactionDate, TillNo, ShiftNo, EmployeeCode, [Status],
                                      LastSavedAt, LastSavedBy, CreatedAt, CreatedBy)
            SELECT @BranchId, @TransactionDate, @TillNo, @ShiftNo, LTRIM(RTRIM(h.EmployeeCode)), 'open',
                   @Now, @UserId, @Now, @UserId
            FROM [PumpIT].dbo.BRN_DailyBanking h
            WHERE h.SSBranchId = @BranchId AND h.TillNo = @TillNo AND h.ShiftNo = @ShiftNo AND h.TransactionDate = @At;

            SET @CashupId = SCOPE_IDENTITY();
            SET @State = 'open';
        END

        SET @Moved = CASE WHEN @Inserted + @Updated > 0 OR @HeaderChanged = 1 THEN 1 ELSE 0 END;

        UPDATE agora.Cashup
           SET [Status]     = CASE WHEN [Status] = 'confirmed' AND @Moved = 1 THEN 'open' ELSE [Status] END,
               LastSavedAt  = CASE WHEN @Moved = 1 THEN @Now ELSE LastSavedAt END,
               LastSavedBy  = CASE WHEN @Moved = 1 THEN @UserId ELSE LastSavedBy END,
               UpdatedAt = @Now, UpdatedBy = @UserId
         WHERE BranchId = @BranchId AND Id = @CashupId;

        IF @Created = 1
            INSERT INTO agora.CashupEvent (BranchId, CashupId, TransactionDate, TillNo, ShiftNo, [Event], ZRead,
                                           WriteMode, SaveRef, Note, CreatedAt, CreatedBy)
            VALUES (@BranchId, @CashupId, @TransactionDate, @TillNo, @ShiftNo, 'created', @ZSales,
                    'live', @Ref, CONCAT('Header written from Z-read EOD ', @ZEod, ', ', LEFT(@ZEmployee, 11), '.'), @Now, @UserId);

        IF @Moved = 1
        BEGIN
            DECLARE @Writes NVARCHAR(MAX) = (
                SELECT
                    JSON_QUERY((
                        SELECT CASE WHEN @Created = 1 THEN 'insert' ELSE 'update' END AS [action],
                               JSON_QUERY((SELECT p.EmployeeCode, p.ClosingBalance, p.CreditCardAmount, p.CardAmount1,
                                                  p.EmployeeAmount, p.DayEndNo, p.Note
                                           FROM @HPrior p FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS prior,
                               JSON_QUERY((SELECT h.EmployeeCode, h.ClosingBalance, h.CreditCardAmount, h.CardAmount1,
                                                  h.EmployeeAmount, h.DayEndNo, CONVERT(NVARCHAR(MAX), h.Note) AS Note
                                           FROM [PumpIT].dbo.BRN_DailyBanking h
                                           WHERE h.SSBranchId = @BranchId AND h.TillNo = @TillNo AND h.ShiftNo = @ShiftNo
                                             AND h.TransactionDate = @At
                                           FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS new
                        FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS header,
                    JSON_QUERY(ISNULL((
                        SELECT w.[Action] AS [action], @Channel AS leg, @LegTable AS [table],
                               JSON_QUERY(w.KeyJson) AS [key], JSON_QUERY(w.PriorJson) AS prior, JSON_QUERY(w.NewJson) AS new,
                               JSON_QUERY(CASE WHEN w.PoolKey IS NULL THEN NULL ELSE (
                                   SELECT @PoolTable AS [table], JSON_QUERY(w.PoolKey) AS [key],
                                          JSON_QUERY(w.PoolPrior) AS prior, JSON_QUERY(w.PoolNew) AS new
                                   FOR JSON PATH, WITHOUT_ARRAY_WRAPPER) END) AS pool
                        FROM @W w ORDER BY w.Seq
                        FOR JSON PATH, INCLUDE_NULL_VALUES), '[]')) AS lines
                FOR JSON PATH, WITHOUT_ARRAY_WRAPPER);

            INSERT INTO agora.CashupEvent (BranchId, CashupId, TransactionDate, TillNo, ShiftNo, [Event], Channel, Lines,
                                           ZRead, ActualTotal, Variance, WriteMode, SaveRef, WritesJson, Note,
                                           CreatedAt, CreatedBy)
            VALUES (@BranchId, @CashupId, @TransactionDate, @TillNo, @ShiftNo, 'saved', @Channel, @Inserted + @Updated,
                    @ZRead, @Actual, @Variance, 'live', @Ref, @Writes, @Note, @Now, @UserId);

            IF @State = 'confirmed'
                INSERT INTO agora.CashupEvent (BranchId, CashupId, TransactionDate, TillNo, ShiftNo, [Event],
                                               ZRead, ActualTotal, Variance, SaveRef, Note, CreatedAt, CreatedBy)
                VALUES (@BranchId, @CashupId, @TransactionDate, @TillNo, @ShiftNo, 'unconfirmed',
                        @ZRead, @Actual, @Variance, @Ref, 'A save moved the figures that were confirmed.', @Now, @UserId);
        END

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT)                                                       AS Ok,
        CASE WHEN @Moved = 1 THEN 'CASH_CASHUP_SAVED' ELSE 'CASH_CASHUP_UNCHANGED' END AS Code,
        CONVERT(NVARCHAR(400), CASE
            WHEN @Moved = 0 THEN 'Nothing changed — PumpIT already holds exactly this.'
            ELSE CONCAT(CASE WHEN @Created = 1 THEN 'Cashup created in PumpIT. ' ELSE '' END,
                        CASE WHEN @Channel = 'FNB'
                             THEN CONCAT(@Inserted, ' card line(s) added, ', @Updated, ' corrected, ', @Unchanged, ' already right. ')
                             WHEN @Channel = 'StaffShorts'
                             THEN CONCAT(@Inserted, ' staff short(s) posted to this cashup''s pump shortages (', @Posted, '). ')
                             WHEN @IsPool = 1
                             THEN CONCAT(@Inserted, ' line(s) added from the ', @PoolName, ' and marked banked there. ')
                             ELSE '' END,
                        'Variance now ', FORMAT(@Variance, 'N2'), '.',
                        CASE WHEN @State = 'confirmed' THEN ' The figures moved, so the confirmation was withdrawn — confirm again.' ELSE '' END)
            END)                                                             AS Message,
        @CashupId                                                            AS Id,
        @Inserted                                                            AS Inserted,
        @Updated                                                             AS Updated,
        @Unchanged                                                           AS Unchanged,
        @ZRead                                                               AS ZRead,
        @Actual                                                              AS ActualTotal,
        @Variance                                                            AS Variance,
        @Ref                                                                 AS SaveRef;
END
