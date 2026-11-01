/* Read-only, conservative validity of saved previews, including PumpIT stamps.
   A fully invalid run has no own processed evidence and every saved proposal
   still has the same matching bank count, amount and extraction provenance,
   now entirely in the known reconciled state (2). Selection is immaterial.
   Missing/changed/unknown rows never prove invalidity. Bank source IDs were
   not stored for aggregate proposals, so this describes CURRENT matching
   bank evidence, not an invented historical identity or deposit guarantee.
   No nested INSERT EXEC: both the grid and locked archive consume this set. */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_RunValidity]
    @RunIdsJson nvarchar(max),
    @InvalidOnly bit = 0
AS
BEGIN
    SET NOCOUNT ON;
    IF ISJSON(@RunIdsJson) <> 1 OR LEFT(LTRIM(@RunIdsJson), 1) <> '[' OR @RunIdsJson IS NULL
        THROW 51000, 'AGORA:BAD_RUN_IDS:Choose a list of run numbers.', 1;
    IF EXISTS (SELECT 1 FROM OPENJSON(@RunIdsJson)
               WHERE [type] NOT IN (1,2) OR TRY_CONVERT(bigint, [value]) IS NULL OR TRY_CONVERT(bigint, [value]) <= 0)
        THROW 51000, 'AGORA:BAD_RUN_IDS:Every selected run must be a positive whole run number.', 1;

    SELECT r.Id AS RunId, r.BranchId, r.ReconArea, r.FromDate, r.ToDate, r.Status,
           r.TotalRows AS SavedRows,
           CASE WHEN ISJSON(r.ParamsJson) = 1 THEN r.ParamsJson ELSE N'{}' END AS ParamsJson,
           CONVERT(bit, CASE WHEN r.ParamsJson IS NULL OR ISJSON(r.ParamsJson) = 1 THEN 1 ELSE 0 END) AS ParamsValid,
           CONVERT(bit, CASE WHEN r.Status IN ('committed','reversed','closed') OR ISNULL(r.CommittedRows,0)>0
               OR EXISTS (SELECT 1 FROM agora.ReconBatch b WHERE b.BranchId=r.BranchId AND b.RunId=r.Id)
               OR EXISTS (SELECT 1 FROM agora.ReconMatch m WHERE m.BranchId=r.BranchId AND m.RunId=r.Id)
               OR EXISTS (SELECT 1 FROM agora.ReconStamp s WHERE s.BranchId=r.BranchId AND s.RunId=r.Id)
               OR EXISTS (SELECT 1 FROM agora.ReconAllocation a WHERE a.BranchId=r.BranchId AND a.RunId=r.Id)
               THEN 1 ELSE 0 END) AS IsHistory
    INTO #ValidityRuns
    FROM agora.ReconRun r
    WHERE r.Id IN (SELECT CONVERT(bigint,[value]) FROM OPENJSON(@RunIdsJson));

    /* Materialise eligible proposals before extracting. History and incomplete
       previews do not scan source data; nothing here reads deposit tables. */
    SELECT r.RunId, r.BranchId, r.ReconArea, r.FromDate, r.ToDate,
           ISNULL(JSON_VALUE(r.ParamsJson,'$.RuleOrder'),'specific') AS RuleOrder,
           ISNULL(JSON_VALUE(r.ParamsJson,'$.BatchKey'),'numeric') AS BatchKey,
           ISNULL(JSON_VALUE(r.ParamsJson,'$.MatchMode'),'contains') AS MatchMode,
           ISNULL(JSON_VALUE(r.ParamsJson,'$.MopsConvention'),'length') AS MopsConvention,
           TRY_CONVERT(int,JSON_VALUE(r.ParamsJson,'$.BankStartOverride')) AS BankStartOverride,
           TRY_CONVERT(int,JSON_VALUE(r.ParamsJson,'$.BankLenOverride')) AS BankLenOverride,
           l.Id AS RunLineId, l.KeyRef, l.KeyRef2, l.BankLineId, l.WindowFrom, l.WindowTo,
           l.BankLines, l.BankTotal, l.BankCC, l.BankDD,
           l.UsedProcessOrder, l.UsedBankStart, l.UsedBankLen, l.RulesInGroup
    INTO #ValidityLines
    FROM #ValidityRuns r
    JOIN agora.ReconRunLine l ON l.RunId=r.RunId AND l.BranchId=r.BranchId
    WHERE r.Status='previewed' AND r.IsHistory=0 AND r.ParamsValid=1
      AND r.ReconArea IN ('ABSA','FNB','CashBags','CashMachine','SmartATM')
      AND l.WouldReconcile=1 AND l.CommitState='pending' AND l.BankLines>0
      AND (r.ReconArea<>'SmartATM' OR l.KeyRef2 IS NOT NULL)
      AND (l.BankLineId IS NOT NULL OR (l.KeyRef IS NOT NULL AND l.UsedProcessOrder IS NOT NULL
           AND l.UsedBankStart>0 AND l.UsedBankLen>0 AND l.RulesInGroup>0));

    /* An Invalid-only caller needs no partial/current diagnosis. A run with
       even one ineligible saved proposal cannot pass the whole-run proof, so
       discard it BEFORE touching bank source rows. This does not establish
       invalidity: the remaining candidates still undergo every live check. */
    IF ISNULL(@InvalidOnly,0)=1
    BEGIN
        SELECT r.RunId
        INTO #PotentialInvalidRuns
        FROM #ValidityRuns r
        JOIN #ValidityLines l ON l.RunId=r.RunId
        CROSS APPLY (SELECT COUNT(*) AS ActualRows FROM agora.ReconRunLine original
                     WHERE original.RunId=r.RunId AND original.BranchId=r.BranchId) n
        WHERE r.SavedRows>0 AND n.ActualRows=r.SavedRows
        GROUP BY r.RunId,r.SavedRows
        HAVING COUNT(*)=r.SavedRows;

        DELETE l FROM #ValidityLines l
        WHERE NOT EXISTS (SELECT 1 FROM #PotentialInvalidRuns p WHERE p.RunId=l.RunId);
    END

    /* The drill deliberately retains its existing validation behaviour.
       Broken current criteria must instead leave the overview usable and the
       affected preview protected. Materialise this guard before calling the
       function: SQL can otherwise evaluate SUBSTRING before a WHERE filter. */
    DELETE l
    FROM #ValidityLines l
    WHERE EXISTS (
        SELECT 1 FROM agora.vw_AutoReconCriteria c
        WHERE c.BranchId=l.BranchId AND c.BankReconArea=l.ReconArea
          AND ((CASE WHEN c.FILTER_EndPosition>=c.FILTER_StartPosition AND c.FILTER_StartPosition>0
                     THEN c.FILTER_EndPosition-c.FILTER_StartPosition+1 ELSE c.FILTER_EndPosition END)<0
            OR ISNULL(l.BankLenOverride,CASE WHEN c.BANK_EndPosition>=c.BANK_StartPosition
                     THEN c.BANK_EndPosition-c.BANK_StartPosition+1 ELSE c.BANK_EndPosition END)<0
            OR (CASE WHEN c.BANK_StartPosition2>0 AND c.BANK_EndPosition2>=c.BANK_StartPosition2
                     THEN c.BANK_EndPosition2-c.BANK_StartPosition2+1 ELSE c.BANK_EndPosition2 END)<0)
    );

    SELECT l.RunId,
           SUM(CASE WHEN p.MatchesSnapshot=1 AND b.Reconciled=l.BankLines THEN 1 ELSE 0 END) AS InvalidRows,
           SUM(CASE WHEN p.MatchesSnapshot=1 AND b.Outstanding=l.BankLines THEN 1 ELSE 0 END) AS CurrentRows
    INTO #ValidityProof
    FROM #ValidityLines l
    CROSS APPLY (
        SELECT COUNT_BIG(*) AS BankLines, COUNT(b.Amount) AS KnownAmounts, SUM(b.Amount) AS BankTotal,
               SUM(CASE WHEN b.Leg='CC' THEN b.Amount ELSE 0 END) AS BankCC,
               SUM(CASE WHEN b.Leg='DD' THEN b.Amount ELSE 0 END) AS BankDD,
               SUM(CASE WHEN b.ReconState=2 THEN 1 ELSE 0 END) AS Reconciled,
               SUM(CASE WHEN b.ReconState=1 THEN 1 ELSE 0 END) AS Outstanding,
               MIN(b.UsedProcessOrder) AS UsedProcessOrder,
               MIN(b.UsedBankStart) AS UsedBankStart, MIN(b.UsedBankLen) AS UsedBankLen,
               COUNT(DISTINCT b.UsedProcessOrder) AS RulesInGroup,
               COUNT(b.UsedProcessOrder) AS KnownRules, COUNT(b.UsedBankStart) AS KnownStarts,
               COUNT(b.UsedBankLen) AS KnownLengths
        FROM agora.fn_Recon_DrillBank(l.ReconArea,l.BranchId,CONVERT(datetime,l.FromDate),
             DATEADD(second,-1,DATEADD(day,1,CONVERT(datetime,l.ToDate))),
             l.KeyRef,l.KeyRef2,l.BankLineId,l.WindowFrom,l.WindowTo,l.RuleOrder,
             l.BatchKey,l.MatchMode,l.MopsConvention,l.BankStartOverride,l.BankLenOverride,1) b
    ) b
    CROSS APPLY (SELECT CASE WHEN b.BankLines=l.BankLines AND b.KnownAmounts=l.BankLines AND b.BankTotal=l.BankTotal
          AND (l.ReconArea<>'ABSA' OR (l.BankCC IS NOT NULL AND l.BankDD IS NOT NULL
               AND b.BankCC=l.BankCC AND b.BankDD=l.BankDD))
          AND (l.BankLineId IS NOT NULL OR (b.UsedProcessOrder=l.UsedProcessOrder
               AND b.UsedBankStart=l.UsedBankStart AND b.UsedBankLen=l.UsedBankLen
               AND b.RulesInGroup=l.RulesInGroup AND b.KnownRules=l.BankLines
               AND b.KnownStarts=l.BankLines AND b.KnownLengths=l.BankLines))
          THEN 1 ELSE 0 END AS MatchesSnapshot) p
    GROUP BY l.RunId;

    SELECT r.RunId,
           CONVERT(nvarchar(12), CASE WHEN r.IsHistory=1 THEN 'history'
               WHEN r.Status<>'previewed' OR n.ActualRows<>r.SavedRows THEN 'review'
               WHEN n.ActualRows>0 AND n.ActualRows=r.SavedRows AND p.InvalidRows=n.ActualRows THEN 'invalid'
               WHEN p.InvalidRows>0 THEN 'partial'
               WHEN n.ActualRows>0 AND n.ActualRows=r.SavedRows AND p.CurrentRows=n.ActualRows THEN 'current'
               ELSE 'review' END) AS Validity,
           CONVERT(int,CASE WHEN r.IsHistory=0 THEN ISNULL(p.InvalidRows,0) END) AS InvalidRows,
           CONVERT(int,CASE WHEN r.IsHistory=0 THEN n.ActualRows-ISNULL(p.InvalidRows,0) END) AS RemainingRows,
           CONVERT(bit,CASE WHEN r.Status='previewed' AND r.IsHistory=0 AND n.ActualRows>0
               AND n.ActualRows=r.SavedRows AND p.InvalidRows=n.ActualRows THEN 1 ELSE 0 END) AS IsInvalid
    FROM #ValidityRuns r
    LEFT JOIN #ValidityProof p ON p.RunId=r.RunId
    CROSS APPLY (SELECT COUNT(*) AS ActualRows FROM agora.ReconRunLine l
                 WHERE l.RunId=r.RunId AND l.BranchId=r.BranchId) n
    /* Mode 1 returns exactly the invalid subset, never a cheaper substitute
       for the ordinary page's partial/current/history classifications. */
    WHERE ISNULL(@InvalidOnly,0)=0 OR (r.Status='previewed' AND r.IsHistory=0
          AND n.ActualRows>0 AND n.ActualRows=r.SavedRows AND p.InvalidRows=n.ActualRows);
END
