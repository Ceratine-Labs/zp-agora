<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — the balancing ledger (slot 14).
 *
 * WHAT THE LEGACY DOES AND DOES NOT KEEP. `dbo.sp_UpdateAUTOStockReconBalancing`
 * finds pairs of shifts whose variances cancel exactly, opens a cursor, calls
 * `sp_UpdateStockReconLine_QtyOpen_xQtyClose` on each, and returns
 * `SELECT '' AS ErrorMesage`. Nothing anywhere records which counts it moved,
 * by how much, on whose authority, or what they were before — and because it
 * writes through `_Original` columns it can be re-run, so the amendments
 * compound with nothing to say they have.
 *
 * These three tables are that memory:
 *
 *   StockReconRun         one press of Preview — branch, area, window, the
 *                         exact caps, the procedure that answered, and the
 *                         before/after shape of the whole result.
 *   StockReconRunLine     one row per shift per stock item: what was counted,
 *                         what the method proposes, every flag that would stop
 *                         it, and the operator's tick.
 *   StockReconAmendment   what a commit actually did, with the PRIOR values,
 *                         so it can be put back exactly.
 *
 * Everything read lives in PumpIT and is reached through the `agora.vw_Stock*`
 * views Reports already created (v1__22_reports_views.php) — read-only, by
 * three-part name. Nothing here writes outside the Agora database unless
 * config('stockrecon.stamp_mode') is 'live', which it is not.
 *
 * SLOT 14 is "Stock" in the domain order. A future `Stock` module shares the
 * slot and not the filename; the slot orders the run, it does not name the
 * owner.
 *
 * BranchId is the site being balanced, never the group entity: a chain is
 * always one site's stock item. Runs launched together across sites share a
 * GroupRef, which is how "balance every site for August" stays one action
 * without a NULL branch anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A run is a preview first and a commit second, and it stays a row
         * either way. A preview nobody acts on is still the answer to "what
         * did this branch's counts look like in August, under these caps" —
         * which is a question the legacy procedure cannot answer at all.
         */
        MigrationHelper::table('StockReconRun', function (Blueprint $table) {
            // Shared by every run launched from one press across many sites.
            $table->uuid('GroupRef');

            // NULL is every area at the branch — the same reading @AreaNo = 0
            // has in the legacy procedure, made explicit so a stored run does
            // not depend on knowing that 0 is a sentinel.
            $table->integer('AreaNo')->nullable();

            $table->date('FromDate');
            $table->date('ToDate');

            // previewing | previewed | committed | reversed | failed
            $table->string('Status', 20)->default('previewing');

            // journal = the amendments are recorded here and PumpIT is not
            // touched. live = the commit also updates the recon line.
            // See config('stockrecon.stamp_mode').
            $table->string('StampMode', 20)->default('journal');

            $table->string('ProcedureName', 128);
            $table->text('ParamsJson')->nullable();

            /*
             * The shape of the answer, before and after.
             *
             * Held on the header rather than recounted from the lines on every
             * read: the run screen, the runs grid and the hub all want these,
             * and three different SUM(CASE ...) passes over a few thousand
             * lines is three chances to disagree about what "short" means.
             */
            $table->integer('TotalRows')->default(0);
            $table->integer('DormantRows')->default(0);
            $table->integer('ChainCount')->default(0);
            $table->integer('BalanceableChains')->default(0);
            $table->integer('BlockedChains')->default(0);
            $table->integer('AmendedRows')->default(0);

            $table->integer('OverRowsBefore')->default(0);
            $table->integer('OverRowsAfter')->default(0);
            $table->integer('ShortRowsBefore')->default(0);
            $table->integer('ShortRowsAfter')->default(0);

            // Quantities, not money: a stock count is units and the units are
            // fractional (a butchery item is weighed). Four places, because
            // QtyVarAllowance and the recon line itself are floats and
            // rounding the comparison to two would invent variances.
            MigrationHelper::litres($table, 'OverUnitsBefore');
            MigrationHelper::litres($table, 'OverUnitsAfter');
            MigrationHelper::litres($table, 'ShortUnitsBefore');
            MigrationHelper::litres($table, 'ShortUnitsAfter');
            MigrationHelper::litres($table, 'UnitsAmended');

            // What the balancing CANNOT touch, which is the finding worth more
            // than the balancing: the net over across every chain in the
            // window, and what it is worth at the sell price on the line.
            MigrationHelper::litres($table, 'NetOverUnits');
            MigrationHelper::money($table, 'NetOverValue');
            // The residual short after balancing, at sell price. The invariant
            // says this is the floor, so it is the number that goes to a
            // branch — never the pre-balance short, which is mostly artefact.
            MigrationHelper::money($table, 'ShortValueAfter');

            $table->integer('PreviewMs')->nullable();

            $table->dateTime('CommittedAt', 0)->nullable();
            $table->integer('CommittedBy')->nullable();
            $table->integer('CommittedRows')->default(0);
            MigrationHelper::litres($table, 'CommittedUnits');

            $table->dateTime('ReversedAt', 0)->nullable();
            $table->integer('ReversedBy')->nullable();
            $table->string('ReversalReason', 300)->nullable();

            $table->string('FailureCode', 40)->nullable();
            $table->string('FailureMessage', 400)->nullable();

            // What the person called this run. An unnamed run is described by
            // its area and period rather than by a dash.
            $table->string('Note', 400)->nullable();
        });
        MigrationHelper::rowVersion('StockReconRun');

        /*
         * One row per shift per stock item — a LINE of the recon, in the
         * legacy's own vocabulary.
         *
         * The natural key of the source row (branch, date, shift, area, item)
         * is carried in full rather than an id, because STK_StockReconLine has
         * no surrogate key of its own: that composite IS the row, and it is
         * what the commit has to name to write anything back.
         */
        MigrationHelper::table('StockReconRunLine', function (Blueprint $table) {
            $table->unsignedBigInteger('RunId');

            // The procedure's own ordering, preserved: area, item, date,
            // shift — which is the order a chain reads in.
            $table->integer('LineNo');

            $table->integer('AreaNo');
            $table->string('StockItemNo', 10);
            $table->date('TransactionDate');
            $table->integer('ShiftNo');

            // The price on the recon line, not on the master: it is what the
            // shift was selling at, and the master's has since moved.
            MigrationHelper::money($table, 'SellPrice', true);

            // As counted. Four places for the same reason as above.
            MigrationHelper::litres($table, 'QtyOpen');
            MigrationHelper::litres($table, 'QtyIssued');
            MigrationHelper::litres($table, 'QtyClose');
            MigrationHelper::litres($table, 'QtyPOS');
            MigrationHelper::litres($table, 'QtyVar');

            // As balanced. QtyOpenNew is the previous row's QtyCloseNew — the
            // chain constraint, restated — and is stored rather than derived
            // so the screen and the commit cannot disagree about it.
            MigrationHelper::litres($table, 'QtyOpenNew');
            MigrationHelper::litres($table, 'QtyCloseNew');
            MigrationHelper::litres($table, 'QtyVarNew');
            // The amendment itself: QtyCloseNew - QtyClose. Signed.
            MigrationHelper::litres($table, 'AmendClose');

            /*
             * A shift whose opening equals its closing with nothing issued and
             * nothing sold did not trade. It is a sign-off with no movement
             * behind it, and treating it as a shift in its own right is what
             * puts a charge on somebody who was never at the till — 261 of the
             * 929 dormant shifts in the Elephant Coast export were left
             * carrying a short by hand balancing, R29,805 against shifts that
             * never traded.
             *
             * The rule is that opening and closing stay equal TO EACH OTHER,
             * not that they stay at their original values: the opening IS the
             * previous closing, so pinning it would break the chain.
             */
            $table->boolean('IsDormant')->default(false);

            // A running count of ACTIVE shifts. On an active row it is that
            // row's own position; on a dormant row it is the position of the
            // active shift before it — which is the single expression that
            // makes a dormant row inherit the right amendment from one join,
            // and makes a run of them move as a block.
            $table->integer('ActiveSeq')->default(0);
            $table->integer('ActiveLen')->default(0);

            // T for this chain: the total variance across the window. Fixed by
            // the choice of dates and unchanged by any amendment, which is why
            // it is the number that decides whether a chain can be balanced
            // at all.
            MigrationHelper::litres($table, 'ChainNetVar');

            /*
             * Every condition that stops a chain being written, one bit each.
             *
             * Stored per row and rolled up per chain into ChainBlocked, so one
             * bad shift blocks its whole chain and a chain is never balanced
             * half way around a fault.
             */
            $table->boolean('FlagNetOver')->default(false);
            $table->boolean('FlagSoldMoreThanOnHand')->default(false);
            $table->boolean('FlagCloseExceedsOnHand')->default(false);
            $table->boolean('FlagIssueWentNowhere')->default(false);
            $table->boolean('FlagBigAmendment')->default(false);
            $table->boolean('FlagPctAmendment')->default(false);
            $table->boolean('FlagNegativeClose')->default(false);
            $table->boolean('FlagShortChain')->default(false);
            $table->boolean('FlagDormantMoved')->default(false);
            /*
             * THE ONE THE METHOD NOTE DOES NOT HAVE, and the one real data
             * needed.
             *
             * The whole method rests on Open(i) = Close(i-1). On branch 18's
             * August, 880 of 14,483 lines break that in the SOURCE — the
             * opening count simply is not the previous closing. Balancing a
             * chain whose links are already broken is redistributing a
             * quantity along a path that does not exist, and it is also what
             * made the note's own dormant-shift guard fire 144 times on the
             * first real run. With this flag the guard fires zero times and
             * the invariant holds exactly. Measured 8 September 2026.
             */
            $table->boolean('FlagChainBroken')->default(false);

            // The per-chain maximum of every flag above.
            $table->boolean('ChainBlocked')->default(false);

            // Which exception class this line falls into, if any — the same
            // vocabulary the exception report uses. Null is a clean line.
            $table->string('ExceptionCode', 4)->nullable();

            // Plain language, for the person reading the grid rather than the
            // bits. Written by the procedure, never re-derived in PHP.
            $table->string('Outcome', 80);

            /*
             * Would this line be amended if the run were committed?
             *
             * A stored bit, not a comparison made at commit time. It is 1 only
             * when the chain is unblocked AND the amendment is non-zero — so
             * there is no path from "blocked" to "written", in the same way
             * there is no path from WouldReconcile = 0 to a bank stamp.
             */
            $table->boolean('WouldAmend')->default(false);

            // The operator's tick. Defaults TRUE, unlike bank recon's: the ask
            // is the least interaction that is still safe, and the safety is
            // the confirmation before the press, not an empty grid the person
            // has to fill in. A tick is only ever written on a row that
            // WouldAmend, so a ticked row can always be honoured.
            $table->boolean('Selected')->default(true);

            // pending | committed | skipped | blocked
            $table->string('CommitState', 20)->default('pending');
            $table->string('BlockReason', 200)->nullable();
        });
        MigrationHelper::naturalKey('StockReconRunLine', ['BranchId', 'RunId', 'LineNo']);

        /*
         * What a commit did, per amended line, with what was there before.
         *
         * This is the table that makes the reversal exact and the whole
         * difference from the legacy, which can undo nothing. It is written in
         * BOTH stamp modes: in journal mode it is the worklist an admin
         * applies by hand and the evidence for turning live on; in live mode
         * it is additionally the only record of what the UPDATE replaced.
         */
        MigrationHelper::table('StockReconAmendment', function (Blueprint $table) {
            $table->unsignedBigInteger('RunId');
            $table->unsignedBigInteger('RunLineId');

            // The source row, named in full — STK_StockReconLine has no
            // surrogate key, so this composite is the only way to address it.
            $table->integer('AreaNo');
            $table->string('StockItemNo', 10);
            $table->date('TransactionDate');
            $table->integer('ShiftNo');

            // Prior, then written. Both sides of both columns, because a
            // reversal has to put back a pair and "it was the original value"
            // stops being true the moment a second run touches the row.
            MigrationHelper::litres($table, 'PriorQtyOpen');
            MigrationHelper::litres($table, 'PriorQtyClose');
            MigrationHelper::litres($table, 'NewQtyOpen');
            MigrationHelper::litres($table, 'NewQtyClose');

            // journal | live — per amendment, not per run: a run committed in
            // journal mode and a later one committed live must be tellable
            // apart six months from now without reading the config history.
            $table->string('StampMode', 20);

            // active | reversed
            $table->string('State', 20)->default('active');
            $table->dateTime('ReversedAt', 0)->nullable();
            $table->integer('ReversedBy')->nullable();
        });
        // One amendment per line per run. A second row for the same line would
        // mean two "prior" values and no way to know which is the real one.
        MigrationHelper::naturalKey('StockReconAmendment', ['BranchId', 'RunId', 'RunLineId']);

        $this->indexes();

        MigrationHelper::recordVersion(
            '1.2',
            'Stock recon: the balancing ledger — previews, per-shift proposals, the flags that block a '
            .'chain, and the amendment record with prior values that makes a reversal exact.'
        );
    }

    /**
     * The reads these tables exist to serve, in index form.
     *
     * Branch-first throughout, because BranchScope puts BranchId in the WHERE
     * of every query the application issues and an index that does not lead
     * with it is an index the optimiser will not use.
     */
    protected function indexes(): void
    {
        $schema = config('agora.schema');

        foreach ([
            // The runs grid: this branch, this area, newest first.
            "CREATE INDEX [IX_StockReconRun_Area] ON [{$schema}].[StockReconRun] ([BranchId], [AreaNo], [FromDate], [ToDate]) INCLUDE ([Status], [AmendedRows], [NetOverValue]);",
            // "Show me everything last night's press did, across every site."
            "CREATE INDEX [IX_StockReconRun_Group] ON [{$schema}].[StockReconRun] ([GroupRef]);",
            // The proposal table, in the procedure's own order.
            "CREATE INDEX [IX_StockReconRunLine_Run] ON [{$schema}].[StockReconRunLine] ([BranchId], [RunId], [LineNo]);",
            // "Which runs have ever proposed anything for this item?" — the
            // question asked when a charge is disputed months later.
            "CREATE INDEX [IX_StockReconRunLine_Item] ON [{$schema}].[StockReconRunLine] ([BranchId], [AreaNo], [StockItemNo], [TransactionDate]);",
            // The reversal, and "has this shift ever been amended before?"
            "CREATE INDEX [IX_StockReconAmendment_Line] ON [{$schema}].[StockReconAmendment] ([BranchId], [AreaNo], [StockItemNo], [TransactionDate], [ShiftNo]) INCLUDE ([State]);",
        ] as $statement) {
            DB::statement($statement);
        }
    }

    /**
     * Local sandbox only. Live and staging are forward-only — see CLAUDE.md.
     */
    public function down(): void
    {
        MigrationHelper::drop('StockReconAmendment');
        MigrationHelper::drop('StockReconRunLine');
        MigrationHelper::drop('StockReconRun');
    }
};
