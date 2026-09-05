<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Recon — the run ledger (slot 13).
 *
 * The legacy AUTO RECON has no memory. It walks bank lines, stamps some of
 * them, and leaves nothing behind saying what it did — which is exactly why
 * question 3.7 of docs/pumpit-auto-recon-findings.md ("who stamped the
 * historical reconciliations?") could not be answered from the schema, only
 * guessed at from 1,570 orphaned bank lines. Nothing in PumpIT records which
 * path reconciled a line, when, on whose authority, or against what rule.
 *
 * These two tables are that memory, and they are the reason Agora's recon is
 * a different product rather than the same executable with a nicer skin:
 *
 *   ReconRun      one press of Preview — area, branch, dates, the exact
 *                 parameters, the procedure that answered, and the counts it
 *                 came back with.
 *   ReconRunLine  every proposal the preview produced, matched or not. The
 *                 rows the operator will NOT act on are kept too: a deposit
 *                 with no bank line is a finding, and the legacy screen never
 *                 showed it at all (finding 4).
 *
 * Persisting a preview is what makes the second press mean something. The
 * legacy exe previews and executes from two separate walks of the data, so
 * what it stamps is not necessarily what was on the screen when the operator
 * decided; here Execute can only act on rows this run already recorded.
 *
 * The tables that carry a committed reconciliation — the allocated batch, the
 * per-side match and the stamp record — arrive with the execute work, once
 * the write path onto the customer's estate has an explicit go-ahead. They
 * are not scaffolded ahead of it: five unused tables in a production database
 * are five tables somebody has to reason about.
 *
 * Everything read here lives in PumpIT and is reached through `agora.vw_*`,
 * read-only, by three-part name. Nothing in this module writes outside the
 * Agora database.
 *
 * BranchId is the branch being reconciled, never the group entity: a run is
 * always about one site's statement. Runs launched together across many
 * branches share a GroupRef, which is how "reconcile every branch for August"
 * stays one action without a NULL branch anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A run is a preview first and an execution second, and it stays a row
         * either way. A preview nobody acts on is still evidence — it is the
         * record that on this date, with these rules, the estate looked like
         * this. That is the artefact the legacy exe never produced.
         */
        MigrationHelper::table('ReconRun', function (Blueprint $table) {
            // Shared by every run launched from one press across many branches.
            $table->uuid('GroupRef');

            $table->string('ReconArea', 20);
            $table->date('FromDate');
            $table->date('ToDate');

            // previewing | previewed | committed | reversed | failed
            $table->string('Status', 20)->default('previewing');

            // journal = Agora records the reconciliation and emits the stamp
            // set for the customer's DBA. live = Agora writes the stamp into
            // the customer's estate itself. See config('recon.stamp_mode').
            $table->string('StampMode', 20)->default('journal');

            /*
             * The procedure that produced the rows, and the arguments it was
             * given. Rule 3.4 says a grid names its procedure so the customer
             * can open the thing they are allowed to change; a run stores it
             * so a result read six months later can be reproduced exactly.
             * Extraction positions come from BRN_AutoReconCriteria, which is
             * configuration the customer edits — the same procedure over the
             * same dates does not have to return the same rows next week.
             */
            $table->string('ProcedureName', 128);
            $table->text('ParamsJson')->nullable();

            $table->integer('TotalRows')->default(0);
            $table->integer('MatchedRows')->default(0);
            $table->integer('MismatchRows')->default(0);
            $table->integer('BankOnlyRows')->default(0);
            $table->integer('DepositOnlyRows')->default(0);
            $table->integer('OtherRows')->default(0);

            MigrationHelper::money($table, 'BankTotal');
            MigrationHelper::money($table, 'MopsTotal');
            MigrationHelper::money($table, 'MatchedTotal');

            $table->integer('PreviewMs')->nullable();

            $table->dateTime('CommittedAt', 0)->nullable();
            $table->integer('CommittedBy')->nullable();
            $table->integer('CommittedRows')->default(0);
            MigrationHelper::money($table, 'CommittedTotal');

            $table->dateTime('ReversedAt', 0)->nullable();
            $table->integer('ReversedBy')->nullable();
            $table->string('ReversalReason', 300)->nullable();

            // A run that failed says why in the operator's language; the
            // AgoraProcException code is kept beside it so support can match
            // on something stable.
            $table->string('FailureCode', 40)->nullable();
            $table->string('FailureMessage', 400)->nullable();

            $table->string('Note', 400)->nullable();
        });
        MigrationHelper::rowVersion('ReconRun');

        /*
         * One row per proposal, normalised across the five areas.
         *
         * The five previews do not return the same columns — ABSA has a
         * merchant and a CC/DD split, SmartATM has a terminal and a trading
         * window, CashBags works line by line rather than batch by batch — so
         * the shared spine below carries what every area has and the
         * area-specific columns are nullable rather than pushed into a JSON
         * blob. A blob would be unqueryable, and "show me every CashBags line
         * whose bag reference matched more than one statement line" is a
         * question the customer will ask.
         */
        MigrationHelper::table('ReconRunLine', function (Blueprint $table) {
            $table->unsignedBigInteger('RunId');

            // The procedure's own ordering, preserved. Its ORDER BY puts
            // matched first and orphans last, and that order is a considered
            // part of the answer.
            $table->integer('LineNo');

            $table->string('ReconArea', 20);

            // FNB: batched | standalone. CashBags: contains | position.
            // Null where the area has one population.
            $table->string('Population', 20)->nullable();

            // The reference the two sides were joined on — a batch number, a
            // slip number, a bag number or a terminal id, depending on area.
            $table->string('KeyRef', 50)->nullable();
            // The second half of a composite key. ABSA and FNB match on
            // (batch, merchant); nothing else has one.
            $table->string('KeyRef2', 50)->nullable();

            $table->date('BankDate')->nullable();
            $table->dateTime('WindowFrom', 0)->nullable();
            $table->dateTime('WindowTo', 0)->nullable();
            $table->string('BankNarrative', 400)->nullable();
            $table->string('DeviceRefs', 400)->nullable();

            // CashBags proposes per bank line rather than per batch, so it is
            // the one area with a single statement line behind a row.
            $table->bigInteger('BankLineId')->nullable();

            $table->integer('BankLines')->default(0);
            MigrationHelper::money($table, 'BankTotal');
            // ABSA settles a batch as the sum of its credit and debit legs
            // (confirmed by ZP, 14 Aug 2026). Only the net decides the match;
            // the split is kept so the operator can see what it is made of.
            MigrationHelper::money($table, 'BankCC', true);
            MigrationHelper::money($table, 'BankDD', true);

            $table->integer('MopsTxns')->default(0);
            MigrationHelper::money($table, 'MopsTotal');
            MigrationHelper::money($table, 'DiffAmount');

            $table->string('Outcome', 60);

            /*
             * The whole point of the rebuild.
             *
             * In the legacy procedures the decision is `IF @CurrAmount <>
             * @MOPSAmount`, which is UNKNOWN when there is no deposit and so
             * falls to the ELSE — the MATCHED branch (finding 2, critical).
             * Here it is a stored bit that is 1 only when both sides exist and
             * the totals are equal, and Commit refuses any row where it is 0.
             * A missing deposit cannot reach the matched path because there is
             * no path from 0 to a stamp.
             */
            $table->boolean('WouldReconcile')->default(false);

            // Which BRN_AutoReconCriteria row fired, and the positions it
            // resolved to. Surfaced on the grid because finding 11 — branch 7
            // extracting from past the end of its own narratives — would have
            // been visible on first glance instead of taking an investigation.
            $table->integer('UsedProcessOrder')->nullable();
            $table->integer('UsedBankStart')->nullable();
            $table->integer('UsedBankLen')->nullable();
            // > 1 means one batch was assembled from lines that resolved to
            // different rules, which is worth a second look before stamping.
            $table->integer('RulesInGroup')->nullable();

            // The operator's tick. The legacy exe is all-or-nothing; here the
            // proposal and the decision to act on it are separate facts.
            $table->boolean('Selected')->default(false);

            // pending | committed | skipped | blocked
            $table->string('CommitState', 20)->default('pending');
            $table->integer('ReconBatchNo')->nullable();
            $table->string('BlockReason', 200)->nullable();
        });
        MigrationHelper::naturalKey('ReconRunLine', ['BranchId', 'RunId', 'LineNo']);

        $this->indexes();
        $this->views();

        MigrationHelper::recordVersion(
            '1.1',
            'Recon: the run ledger — previews, proposals, batches, per-side matches and the '
            .'stamp record that the legacy AUTO RECON never kept.'
        );
    }

    /**
     * The reads these tables exist to serve, in index form.
     *
     * Every one is branch-first because BranchScope puts BranchId in the WHERE
     * of every query the application issues; an index that does not lead with
     * it is an index the optimiser will not use.
     */
    protected function indexes(): void
    {
        $schema = config('agora.schema');

        foreach ([
            // The run history grid: this branch, this area, newest first.
            "CREATE INDEX [IX_ReconRun_Area] ON [{$schema}].[ReconRun] ([BranchId], [ReconArea], [FromDate], [ToDate]) INCLUDE ([Status], [MatchedRows], [MatchedTotal]);",
            // "Show me the whole of last night's run across every branch."
            "CREATE INDEX [IX_ReconRun_Group] ON [{$schema}].[ReconRun] ([GroupRef]);",
            // The proposal grid, in the procedure's own order.
            "CREATE INDEX [IX_ReconRunLine_Run] ON [{$schema}].[ReconRunLine] ([BranchId], [RunId], [LineNo]);",
            // "Which runs proposed anything for batch 125?" — the question
            // asked when a figure is disputed months later.
            "CREATE INDEX [IX_ReconRunLine_Key] ON [{$schema}].[ReconRunLine] ([BranchId], [ReconArea], [KeyRef]);",
        ] as $statement) {
            DB::statement($statement);
        }
    }

    /**
     * The legacy estate, read-only, by three-part name.
     *
     * The ported procedures live on Agora but every row they read lives in
     * PumpIT, so each one comes through a view here — the pattern Core
     * established with `vw_Branch`, and the reason Agora has to sit on the
     * same instance as PumpIT.
     *
     * Two rules, both deliberate:
     *
     *  - **`SSBranchId` becomes `BranchId`.** One name for the branch across
     *    the whole application, so nothing has to remember which estate a
     *    given row came from.
     *  - **Every other column keeps its legacy name**, underscores and all.
     *    `BANK_StartPosition` is ugly, but it is what the customer greps for
     *    in SSMS and what the findings document cites. Renaming it would make
     *    the ported procedures stop being a readable diff against the
     *    originals ZP validated, and that diff is the evidence the logic is
     *    the same logic.
     *
     * Columns are enumerated rather than `SELECT *`: a view over a star binds
     * its column list at creation and does not notice an ALTER TABLE, so a
     * legacy table gaining a column silently produces a view that is wrong
     * about its own shape.
     */
    protected function views(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        $views = [
            // The bank side of every area. Type is the channel; IDState = 1
            // means the line was never classified (75,305 rows) and every
            // recon screen filters it out, so it is exposed rather than
            // filtered here — a view that hides rows is a view that makes an
            // unreconciled total unexplainable.
            'vw_BankStatementLine' => "
                SELECT l.BankStatementLineID,
                       l.SSBranchId AS BranchId,
                       l.LineDate,
                       l.Description,
                       l.Amount,
                       l.Type,
                       l.IDState,
                       l.ReconState,
                       l.ReconBatchNo
                FROM [{$erp}].dbo.RCN_BankStatementLinesPumpIT l",

            // The extraction rules. Read in full, never TOP 1: the live
            // procedures read one row per branch, which is why the six
            // zero-match FNB branches cannot be fixed by configuration alone
            // (finding 8).
            'vw_AutoReconCriteria' => "
                SELECT c.AutoReconId,
                       c.SSBranchId AS BranchId,
                       c.BankReconArea,
                       c.ProcessOrder,
                       c.BANK_StartPosition,
                       c.BANK_EndPosition,
                       c.BANK_StartPosition2,
                       c.BANK_EndPosition2,
                       c.MOPS_StartPosition,
                       c.MOPS_EndPosition,
                       c.FILTER_Value,
                       c.FILTER_StartPosition,
                       c.FILTER_EndPosition
                FROM [{$erp}].dbo.BRN_AutoReconCriteria c",

            // The MOPS side, one view per area. ReconBatchNoPumpIT is the live
            // recon stamp; the plain ReconBatchNo column on these tables is 0
            // on all 1.38 million rows and is deliberately not exposed — it is
            // dead, and a query that filters on it returns nothing, silently.
            'vw_DailyBankingABSA' => "
                SELECT a.SSBranchId AS BranchId,
                       a.TransactionDate,
                       a.BatchNumber,
                       a.MerchantNumber,
                       a.TransactionAmount,
                       a.ReconBatchNoPumpIT
                FROM [{$erp}].dbo.BRN_DailyBankingABSA a",

            'vw_DailyBankingFNB' => "
                SELECT f.SSBranchId AS BranchId,
                       f.TransactionDate,
                       f.BatchNo,
                       f.MerchantNo,
                       f.Amount,
                       f.ReconBatchNoPumpIT
                FROM [{$erp}].dbo.BRN_DailyBankingFNB f",

            'vw_DailyBankingSmartATM' => "
                SELECT s.SSBranchId AS BranchId,
                       s.TerminalId,
                       s.TraceNo,
                       s.UniqueNo,
                       s.DepositDateTime,
                       s.Deposited,
                       s.ReconBatchNoPumpIT
                FROM [{$erp}].dbo.BRN_DailyBankingSmartATM s",

            'vw_DailyBankingCashBags' => "
                SELECT b.DailyBankingCashBagID,
                       b.SSBranchId AS BranchId,
                       b.TransactionDate,
                       b.CashBagNo,
                       b.CashBagAmount,
                       b.ReconBatchNoPumpIT
                FROM [{$erp}].dbo.BRN_DailyBankingCashBags b",

            // CashMachine reconciles against Deposita, not against a table of
            // its own — the one area whose two sides are named differently.
            'vw_DailyBankingDeposita' => "
                SELECT d.SSBranchId AS BranchId,
                       d.TransactionDate,
                       d.SlipNo,
                       d.DepositaAmount,
                       d.ReconBatchNoPumpIT
                FROM [{$erp}].dbo.BRN_DailyBankingDeposita d",

            // The device feed. SmartATM's deposit row carries one date while
            // this table's row carries the real timestamp, and the two do not
            // agree — question 3.6, still open with ZP.
            'vw_SmartATM' => "
                SELECT s.SSBranchId AS BranchId,
                       s.TerminalId,
                       s.TraceNo,
                       s.UniqueNo,
                       s.DepositDateTime
                FROM [{$erp}].dbo.BRN_SmartATM s",

            // A cash bag's reference is its collection's DBagNo, falling back
            // to its own CashBagNo. Bag level and collection level are
            // different grains, so the procedure aggregates before it joins.
            'vw_DropSafe' => "
                SELECT d.SSBranchId AS BranchId,
                       d.BagNo,
                       d.CollectionId
                FROM [{$erp}].dbo.BRN_DropSafe d",

            'vw_DropSafeCollection' => "
                SELECT c.SSBranchId AS BranchId,
                       c.CollectionId,
                       c.DBagNo
                FROM [{$erp}].dbo.BRN_DropSafe_Collection c",
        ];

        foreach ($views as $name => $body) {
            DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[{$name}] AS".$body.';');
        }
    }

    /**
     * Live and staging are forward-only; this runs against the local sandbox
     * only. Never rely on it to undo something in production.
     */
    public function down(): void
    {
        $schema = config('agora.schema');

        foreach ([
            'vw_BankStatementLine', 'vw_AutoReconCriteria', 'vw_DailyBankingABSA',
            'vw_DailyBankingFNB', 'vw_DailyBankingSmartATM', 'vw_DailyBankingCashBags',
            'vw_DailyBankingDeposita', 'vw_SmartATM', 'vw_DropSafe', 'vw_DropSafeCollection',
        ] as $view) {
            DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[{$view}];");
        }

        foreach (['ReconRunLine', 'ReconRun'] as $table) {
            MigrationHelper::drop($table);
        }
    }
};
