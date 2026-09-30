/* ============================================================================
   agora.usp_Cash_LoadCardAmounts

   WHAT IT IS FOR
   "Load card amounts" on the Daily Banking screen: what the card terminal says
   one till's shift took by card, batch by batch — the FNB leg of the cashup,
   proposed from the terminal's own batch file rather than typed.

   THE PORT. dbo.sp_Load_BranchCreditCardAmount is what the legacy client runs
   when a cashup is opened (on 1,970 of 6,789 August 2026 FNB lines the leg was
   written within five seconds of the header). It is a READ: it sums
   DBF_EFTBAT_NEWCREDITCARD, the lines with a batch number and a POS reference,
   by EOD, log file, operator, batch, terminal and merchant (characters 8–13
   of MERCHID) for the Z-read's EOD, log file and operator and the Z-read's
   terminal. The client then writes each row to BRN_DailyBankingFNB (BatchNo,
   MerchantNo, Amount) and their sum to the header's CreditCardAmount and
   CardAmount1 — which agree with the FNB leg on 4,425 of 4,439 August 2026
   cashups.

   Here the Z-read is the one agora.vw_CashZread allocates to this till and
   shift that day — the effective allocation — and the sums come from
   agora.vw_CashupCardBatch. Every Z-read on the cashup contributes its own
   batches (in 2026 every till-shift-day has exactly one).

   Nothing is written. Each proposed line says what saving it would do to the
   leg as it stands:

     new         no such batch on the leg yet — saving adds it
     same        on the leg already, for this amount — saving leaves it
     changed     on the leg for a different amount — saving corrects it
     reconciled  on the leg for a different amount and already reconciled
                 (ReconBatchNoPumpIT <> 0) — saving is refused, naming the batch

   A line on the leg that the batch file no longer carries is returned too
   (Action 'not in the file'), because saving never removes a line.

   Result sets:
     1  the lines, proposed and existing
     2  one row: the Z-reads found, their EOD / log file / operator, and the
        proposed total

   READ-ONLY.

     EXEC agora.usp_Cash_LoadCardAmounts @BranchId = 23, @TransactionDate = '2026-05-11', @TillNo = 13, @ShiftNo = 1;

   Refusals:
     AGORA:CASH_CASHUP_KEY   site, day, till and shift are all required
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_LoadCardAmounts]
    @BranchId         INT,
    @TransactionDate  DATE,
    @TillNo           INT,
    @ShiftNo          INT
AS
BEGIN
    SET NOCOUNT ON;

    IF @BranchId IS NULL OR @TransactionDate IS NULL OR @TillNo IS NULL OR @ShiftNo IS NULL
        THROW 51000, 'AGORA:CASH_CASHUP_KEY:A cashup is one site, one day, one till and one shift. Give all four.', 1;

    DECLARE @At DATETIME = @TransactionDate;

    /* The Z-read(s) this till and shift carry that day. */
    SELECT z.EodCounter, z.LogFile, z.PosUserId, z.TerminalNo, z.Location, z.EmployeeCode, z.Sales
    INTO #lc_z
    FROM agora.vw_CashZread z
    WHERE z.BranchId = @BranchId AND z.ZReadAt = @At AND z.TillNo = @TillNo AND z.ShiftNo = @ShiftNo;

    /* What the batch file says, summed exactly as the legacy sums it. */
    SELECT
        LEFT(b.BatchNo, 11)          AS BatchNo,
        LEFT(b.MerchantNo, 6)        AS MerchantNo,
        CONVERT(MONEY, SUM(b.Amount)) AS Amount,
        SUM(b.CardLines)             AS CardLines,
        MAX(z.EodCounter)            AS EodCounter,
        MAX(z.LogFile)               AS LogFile,
        MAX(z.PosUserId)             AS PosUserId,
        MAX(z.TerminalNo)            AS TerminalNo
    INTO #lc_p
    FROM #lc_z z
    JOIN agora.vw_CashupCardBatch b
      ON b.BranchId = @BranchId
     AND b.EodCounter = z.EodCounter
     AND b.LogFile = z.LogFile
     AND b.PosUserId = z.PosUserId
     AND b.TerminalNo = z.TerminalNo
    GROUP BY LEFT(b.BatchNo, 11), LEFT(b.MerchantNo, 6);

    /* The FNB leg as it stands. */
    SELECT
        g.Key1                     AS BatchNo,
        g.Key2                     AS MerchantNo,
        g.Amount,
        ISNULL(g.ReconBatchNo, 0)  AS ReconBatchNo
    INTO #lc_e
    FROM agora.vw_CashupLeg g
    WHERE g.BranchId = @BranchId AND g.TillNo = @TillNo AND g.ShiftNo = @ShiftNo AND g.TransactionAt = @At
      AND g.Channel = 'FNB';

    SELECT
        ISNULL(p.BatchNo, e.BatchNo)         AS BatchNo,
        ISNULL(p.MerchantNo, e.MerchantNo)   AS MerchantNo,
        p.Amount                             AS Amount,
        p.CardLines,
        e.Amount                             AS OnLegAmount,
        e.ReconBatchNo,
        CONVERT(NVARCHAR(20), CASE
            WHEN p.BatchNo IS NULL                      THEN 'not in the file'
            WHEN e.BatchNo IS NULL                      THEN 'new'
            WHEN ABS(e.Amount - p.Amount) < 0.005       THEN 'same'
            WHEN e.ReconBatchNo <> 0                    THEN 'reconciled'
            ELSE 'changed' END)              AS [Action],
        p.EodCounter, p.LogFile, p.PosUserId, p.TerminalNo
    FROM #lc_p p
    FULL OUTER JOIN #lc_e e ON e.BatchNo = p.BatchNo AND e.MerchantNo = p.MerchantNo
    ORDER BY ISNULL(p.MerchantNo, e.MerchantNo), ISNULL(p.BatchNo, e.BatchNo);

    SELECT
        (SELECT COUNT(*) FROM #lc_z)                                           AS Zreads,
        (SELECT MAX(EodCounter) FROM #lc_z)                                    AS EodCounter,
        (SELECT STRING_AGG(LogFile, ', ') FROM #lc_z)                          AS LogFiles,
        (SELECT STRING_AGG(PosUserId, ', ') FROM #lc_z)                        AS PosUserIds,
        (SELECT COUNT(*) FROM #lc_p)                                           AS Lines,
        (SELECT CONVERT(MONEY, ISNULL(SUM(Amount), 0)) FROM #lc_p)             AS Total,
        (SELECT CONVERT(MONEY, ISNULL(SUM(ISNULL(Amount, 0)), 0)) FROM #lc_e)             AS OnLegTotal;

    DROP TABLE #lc_e;
    DROP TABLE #lc_p;
    DROP TABLE #lc_z;
END
