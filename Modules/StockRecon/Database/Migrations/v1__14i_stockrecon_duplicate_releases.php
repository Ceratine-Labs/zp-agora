<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — a possible duplicate issue holds its chain back (slot 14i).
 *
 * Ryan, 5 Oct 2026 (PM decision 01a10b21, task 01a0f610-5ac0): a chain that
 * carries a POSSIBLE duplicate issue (D2) is held back from the commit until
 * either the site corrects the issue in PumpIT — the next preview no longer
 * finds the candidate — or somebody RELEASES it here with a typed reason.
 * Since 30 Sep 2026 a commit reverses count moves the client's pack forbids,
 * and 59 of 134 duplicate candidates had been hidden by exactly such moves:
 * committing those chains would have put the short back on staff pay.
 *
 *   StockReconDuplicateRelease  NEW. One row per release of one D2 shift.
 *     AreaNo, StockItemNo,       The shift released — the grain of a recon
 *     TransactionDate, ShiftNo   line, so a release outlives the run it was
 *                                pressed on and every later preview reads it.
 *     QtyIssued                  The issue AS KEYED when it was released. A
 *                                release matches only while PumpIT still holds
 *                                that issue: re-key it and the hold returns.
 *     TwinDate, TwinShift,       What the preview said about it at the time:
 *     DupReason                  the shift with the identical issue, and why it
 *                                was possible rather than clear.
 *     Reason                     Why it was released. Required; the procedure
 *                                refuses one too short to be checked later.
 *     RunId, RunLineId           The run and line it was released from.
 *                                Provenance only — the release is not the run's.
 *     CreatedAt, CreatedBy       WHO released it, and when.
 *     WithdrawnAt, WithdrawnBy,  A release is withdrawn, never deleted — it is
 *     WithdrawNote               a transactional entry (feature-rules §4). A
 *                                withdrawn release matches nothing.
 *
 *   Its natural key is the STANDING release: one per shift where WithdrawnAt
 *   IS NULL, as a filtered unique index. MigrationHelper::naturalKey() has no
 *   vocabulary for a filter, and an unfiltered key on the shift would refuse
 *   the second release of a shift whose first was withdrawn — which is the
 *   history the table exists to keep. The recon override table set the
 *   precedent (v1__13i); BranchId leads it, as every key in Agora does.
 *
 *   StockReconRunLine gains
 *     DupHold        1 on every line of a chain an unreleased D2 holds back —
 *                    as ChainBlocked is, and set by the same stage. Nothing on
 *                    such a chain is written: not the balancing, not a hand
 *                    amendment put back (stage 7d), not a clear duplicate on
 *                    its own (7f).
 *     DupReleaseId   On a D2 line: the release that stood when the run was
 *                    made, so the run says who released it and why.
 *
 * Agora's own tables. Nothing in the customer's estate changes, and no new
 * write to PumpIT is added: a release only lets the commit do what it already
 * did before D2 held anything back. v1__14ph deploys the procedures that read
 * and write this.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        MigrationHelper::table('StockReconDuplicateRelease', function (Blueprint $table) {
            $table->integer('AreaNo');
            $table->string('StockItemNo', 10);
            $table->date('TransactionDate');
            $table->integer('ShiftNo');
            $table->decimal('QtyIssued', 18, 3);
            $table->date('TwinDate')->nullable();
            $table->integer('TwinShift')->nullable();
            $table->string('DupReason', 80)->nullable();
            $table->string('Reason', 400);
            $table->bigInteger('RunId')->nullable();
            $table->bigInteger('RunLineId')->nullable();
            $table->dateTime('WithdrawnAt', 0)->nullable();
            $table->integer('WithdrawnBy')->nullable();
            $table->string('WithdrawNote', 400)->nullable();
        });

        // The natural key: one STANDING release per shift. See the docblock.
        DB::statement("
            CREATE UNIQUE INDEX [UX_StockReconDuplicateRelease_Natural]
                ON [{$schema}].[StockReconDuplicateRelease] ([BranchId], [AreaNo], [StockItemNo], [TransactionDate], [ShiftNo])
                WHERE [WithdrawnAt] IS NULL;
        ");

        DB::statement("
            ALTER TABLE [{$schema}].[StockReconRunLine]
                ADD [DupHold]      BIT    NOT NULL CONSTRAINT [DF_StockReconRunLine_DupHold] DEFAULT 0,
                    [DupReleaseId] BIGINT NULL;
        ");

        // Enumerated, not a star — a view binds its column list at creation
        // (see v1__14b), so the new columns are invisible until it is redefined.
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconRunLine] AS".$this->runLineView($schema, true).';');

        MigrationHelper::recordVersion(
            '1.27',
            'Stock recon: a possible duplicate issue (D2) holds its chain back from the commit until the site '
            .'corrects it in PumpIT or somebody releases it with a reason (agora.StockReconDuplicateRelease).'
        );
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        $schema = config('agora.schema');

        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconRunLine] AS".$this->runLineView($schema, false).';');

        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] DROP CONSTRAINT IF EXISTS [DF_StockReconRunLine_DupHold];");
        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] DROP COLUMN IF EXISTS [DupHold];");
        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] DROP COLUMN IF EXISTS [DupReleaseId];");

        MigrationHelper::drop('StockReconDuplicateRelease');
    }

    /** v1__14h's definition plus the hold and the release. Nothing else changes. */
    private function runLineView(string $schema, bool $withHold): string
    {
        $hold = $withHold ? '
                   rl.DupHold,
                   rl.DupReleaseId,' : '';

        return "
            SELECT rl.BranchId,
                   rl.Id,
                   rl.RunId,
                   rl.[LineNo],
                   rl.AreaNo,
                   rl.StockItemNo,
                   rl.TransactionDate,
                   rl.ShiftNo,
                   rl.SellPrice,
                   rl.QtyOpen,
                   rl.QtyIssued,
                   rl.QtyClose,
                   rl.QtyPOS,
                   rl.QtyVar,
                   rl.QtyOpenNew,
                   rl.QtyCloseNew,
                   rl.QtyVarNew,
                   rl.AmendClose,
                   rl.IsDormant,
                   rl.ActiveSeq,
                   rl.ActiveLen,
                   rl.ChainNetVar,
                   rl.FlagNetOver,
                   rl.FlagSoldMoreThanOnHand,
                   rl.FlagCloseExceedsOnHand,
                   rl.FlagIssueWentNowhere,
                   rl.FlagBigAmendment,
                   rl.FlagPctAmendment,
                   rl.FlagNegativeClose,
                   rl.FlagShortChain,
                   rl.FlagDormantMoved,
                   rl.FlagChainBroken,
                   rl.ChainBlocked,
                   rl.ExceptionCode,
                   rl.Outcome,
                   rl.WouldAmend,
                   rl.Selected,
                   rl.CommitState,
                   rl.BlockReason,
                   rl.CreatedAt,
                   rl.CreatedBy,
                   rl.UpdatedAt,
                   rl.UpdatedBy,
                   rl.PrevClose,
                   rl.ChainBreak,
                   rl.QtyOpenNow,
                   rl.QtyCloseNow,
                   rl.QtyIssuedOrig,
                   rl.IssueMultiple,
                   rl.FlagPackOver,
                   rl.FlagNoPosLink,
                   rl.RestoresHand,
                   rl.FlagAuditConflict,
                   rl.AuditCode,
                   rl.BreakKept,
                   rl.DupTier,
                   rl.DupTwinDate,
                   rl.DupTwinShift,
                   rl.DupTwinVar,
                   rl.DupQty,
                   rl.DupReason,
                   rl.QtyIssuedNew,
                   rl.CountsMove,{$hold}
                   ISNULL(rl.ItemDescription, m.StockItemDescription) AS ItemDescription,
                   ISNULL(rl.POSCode,         m.POSCode)              AS POSCode,
                   ISNULL(rl.StockLocation,   m.StockLocation)        AS StockLocation,
                   ISNULL(rl.AreaDescription, a.AreaDescription)      AS AreaDescription,
                   m.UOMCode,
                   CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeCodes
                        ELSE se.EmployeeCodes END                     AS EmployeeCodes,
                   CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeNames
                        ELSE se.EmployeeNames END                     AS EmployeeNames,
                   CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeCount
                        ELSE ISNULL(se.EmployeeCount, 0) END          AS EmployeeCount
            FROM [{$schema}].StockReconRunLine rl
            OUTER APPLY (SELECT TOP 1 m.StockItemDescription, m.POSCode, m.StockLocation, m.UOMCode
                           FROM [{$schema}].vw_StockMaster m
                          WHERE m.BranchId = rl.BranchId AND m.StockItemNo = rl.StockItemNo
                          ORDER BY CASE WHEN m.AreaNo = rl.AreaNo THEN 0 ELSE 1 END, m.StockLocation) m
            LEFT JOIN [{$schema}].vw_StockArea a
                   ON a.BranchId = rl.BranchId AND a.AreaNo = rl.AreaNo
            LEFT JOIN [{$schema}].vw_StockReconShiftEmployee se
                   ON se.BranchId        = rl.BranchId
                  AND se.TransactionDate = rl.TransactionDate
                  AND se.ShiftNo         = rl.ShiftNo
                  AND se.AreaNo          = rl.AreaNo";
    }
};
