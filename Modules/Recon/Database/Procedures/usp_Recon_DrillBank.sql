/* ============================================================================
   agora.usp_Recon_DrillBank

   The bank statement lines behind one proposal.

   ONE result set, always. That is the whole reason the drill is two procedures
   rather than one: `INSERT INTO @t EXEC` captures only the first result set,
   and agora.usp_Recon_Commit has to capture BOTH sides to know what it is
   stamping. A combined procedure could be read by the screen and not by the
   thing that writes.

   It also means the rows the operator looked at and the rows that get stamped
   come out of the SAME extraction. A second copy of this logic that resolved a
   reference even slightly differently would stamp lines nobody ever saw, which
   is the failure mode the whole rebuild exists to remove.

   READ-ONLY. Uses the shared inline fn_Recon_DrillBank extraction.

   @BatchKey, @MatchMode and @MopsConvention must be the values the RUN used —
   ReconRun.ParamsJson stores them for exactly this. The criteria resolution
   below is identical to the ported previews': every rule for the branch and
   area, most specific filter first, one resolved PER BANK LINE by OUTER APPLY.
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_DrillBank]
    @ReconArea         nvarchar(20),
    @BranchId          int,
    @FromDate          datetime,
    @ToDate            datetime,
    @KeyRef            nvarchar(50)  = NULL,
    @KeyRef2           nvarchar(50)  = NULL,
    @BankLineId        bigint        = NULL,
    @WindowFrom        datetime      = NULL,
    @WindowTo          datetime      = NULL,
    @RuleOrder         varchar(12)   = 'specific',
    @BatchKey          varchar(10)   = 'numeric',
    @MatchMode         varchar(10)   = 'contains',
    @MopsConvention    varchar(10)   = 'length',
    @BankStartOverride int           = NULL,
    @BankLenOverride   int           = NULL,
    /* Off for the screen, which mirrors the preview and so shows only
       outstanding lines. ON for agora.usp_Recon_Commit, which has to be able
       to SEE a line something else has reconciled since the preview in order
       to say so. Filtered out, such a line looks like a line that never
       existed, and the commit reports "the two sides no longer balance" when
       the truth is "something else got there first". Same rows either way;
       the difference is whether the reason it gives is precise. */
    @IncludeReconciled bit           = 0
AS
BEGIN
    SET NOCOUNT ON;

    /* The canonical inline extraction is shared with run validity. Column
       order and ordering stay identical for existing INSERT EXEC callers. */
    SELECT BankStatementLineID, LineDate, Description, Amount, ExtractedRef, ExtractedRef2,
           Leg, UsedProcessOrder, UsedBankStart, UsedBankLen, ReconState, ReconBatchNo
    FROM agora.fn_Recon_DrillBank(
        @ReconArea, @BranchId, @FromDate, @ToDate, @KeyRef, @KeyRef2,
        @BankLineId, @WindowFrom, @WindowTo, @RuleOrder, @BatchKey, @MatchMode,
        @MopsConvention, @BankStartOverride, @BankLenOverride, @IncludeReconciled
    )
    ORDER BY LineDate, BankStatementLineID;
END
