<?php

namespace Modules\Recon\Services;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunLine;
use RuntimeException;

/**
 * The seam between the AUTO RECON procedures and the screen.
 *
 * The procedures are ZP's logic, ported and now owned by Agora. This class
 * does three things and deliberately no more:
 *
 *  1. **Builds the argument list per area.** The five previews do not take the
 *     same arguments, and every optional one exists because the customer's
 *     configuration is ambiguous somewhere. Config declares them; this
 *     resolves and validates them.
 *  2. **Notices a refusal.** Each procedure answers a missing or unusable
 *     BRN_AutoReconCriteria row with a one-row result set carrying an `Error`
 *     column rather than a THROW. That is not a shape ProcedureService knows
 *     about, so it is recognised here and raised, not rendered as an empty
 *     grid — "no rows" and "this branch has no rule configured" are opposite
 *     answers and the screen must not confuse them.
 *  3. **Normalises the answer.** Five different column sets become one row
 *     shape, so the grid, the counts and the stored run all speak one
 *     vocabulary.
 *
 * What it does NOT do is decide anything. Every figure on the screen comes out
 * of the procedure; nothing is recomputed here. If a rule ever exists in both
 * places the procedure is right and this is the bug.
 */
class ReconService
{
    public function __construct(protected ProcedureService $procedures) {}

    /** @return array<string, array<string, mixed>> */
    public function areas(): array
    {
        return config('recon.areas');
    }

    /** @return array<string, mixed> */
    public function area(string $area): array
    {
        $definition = config("recon.areas.{$area}");

        if ($definition === null) {
            throw new RuntimeException("Unknown reconciliation area [{$area}].");
        }

        return $definition + ['key' => $area];
    }

    /**
     * The options this area's procedure accepts, each with its declaration.
     *
     * The two extraction overrides apply to every area — they exist so a
     * corrected configuration can be tried WITHOUT editing the customer's
     * BRN_AutoReconCriteria — so they are appended rather than repeated
     * against all five.
     *
     * @return array<string, array<string, mixed>>
     */
    public function optionsFor(string $area): array
    {
        $names = array_merge(
            $this->area($area)['options'] ?? [],
            ['BankStartOverride', 'BankLenOverride'],
        );

        $declared = config('recon.options');

        return collect($names)
            ->mapWithKeys(fn (string $name) => [$name => $declared[$name] + ['name' => $name]])
            ->all();
    }

    /**
     * Run a preview and record it.
     *
     * The run is stored whether or not anybody acts on it. A preview nobody
     * executes is still the answer to "what did the estate look like on the
     * 4th, under these rules" — which is a question the legacy exe cannot
     * answer at all, because it keeps nothing.
     *
     * @param  array<string, scalar|null>  $options
     * @param  string|null  $note  what the person called this run, so they can
     *                             find it again — "August ABSA, second attempt"
     */
    public function preview(
        string $area,
        int $branchId,
        Carbon $from,
        Carbon $to,
        array $options = [],
        ?string $groupRef = null,
        ?string $note = null,
    ): ReconRun {
        $definition = $this->area($area);
        $params = $this->parameters($area, $branchId, $from, $to, $options);

        $started = microtime(true);
        $rows = $this->procedures->call($definition['procedure'], $params);
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        $this->guardAgainstRefusal($rows, $definition);

        $lines = $rows->values()->map(fn (object $row, int $i) => $this->line($area, $row, $i + 1));

        return $this->record($area, $branchId, $from, $to, $definition, $params, $lines, $elapsed, $groupRef, $note);
    }

    /**
     * Execute a run: stamp what it proposed, and record what was stamped.
     *
     * The stamp mode is read from config and PASSED IN rather than looked up
     * by the procedure, so there is exactly one place in the codebase that
     * decides whether Agora writes to the customer's estate.
     *
     * @return array{status: object, lines: Collection<int, object>}
     */
    public function commit(ReconRun $run): array
    {
        $sets = $this->procedures->callSets('usp_Recon_Commit', [
            'RunId' => $run->Id,
            'BranchId' => $run->BranchId,
            'StampMode' => (string) config('recon.stamp_mode'),
            'UserId' => auth()->id(),
        ]);

        $status = ($sets[0] ?? collect())->first();

        // callSets() rather than write(): the procedure returns its status row
        // AND a row per candidate saying what happened to it, and a caller
        // that could not see the second set would have no way to tell the
        // operator why four of forty were skipped. The refusal contract is
        // still honoured — a THROW inside the procedure arrives here as an
        // AgoraProcException before this line runs.
        if ($status === null || ! (bool) $status->Ok) {
            throw new AgoraProcException(
                $status->Code ?? 'REFUSED',
                $status->Message ?? 'The reconciliation was declined without saying why.',
                'usp_Recon_Commit',
            );
        }

        return ['status' => $status, 'lines' => $sets[1] ?? collect()];
    }

    /**
     * Undo a reconciliation Agora executed — exactly, and nothing else.
     *
     * The reason is required by the procedure, not by a form rule: it is the
     * only record of why a reconciliation was undone, and a reversal without
     * one is a gap in the trail the ledger exists to keep.
     */
    public function reverse(ReconRun $run, string $reason): object
    {
        return $this->procedures->write('usp_Recon_Reverse', [
            'BranchId' => $run->BranchId,
            'RunId' => $run->Id,
            'BatchId' => null,
            'Reason' => $reason,
            'UserId' => auth()->id(),
        ]);
    }

    /**
     * Tick or untick proposals on a run.
     *
     * Eloquent rather than a procedure: this is the operator's working state,
     * not a business rule. Nothing about a tick is true or false — what the
     * tick MEANS is decided at commit, where the procedure re-checks every
     * row anyway.
     *
     * @param  array<int, int>  $lineIds
     */
    public function select(ReconRun $run, array $lineIds): int
    {
        $run->lines()->getRelated()->newQuery()
            ->where('BranchId', $run->BranchId)
            ->where('RunId', $run->Id)
            ->update(['Selected' => false]);

        if ($lineIds === []) {
            return 0;
        }

        return $run->lines()->getRelated()->newQuery()
            ->where('BranchId', $run->BranchId)
            ->where('RunId', $run->Id)
            // Only a row that could actually reconcile can be ticked. A tick
            // on anything else would be a promise the commit has to break.
            ->where('WouldReconcile', true)
            ->where('CommitState', 'pending')
            ->whereIn('Id', $lineIds)
            ->update(['Selected' => true]);
    }

    /**
     * Throw away previews, and say how many.
     *
     * The rule that a committed run is never discarded lives in the procedure,
     * not here — a preview is a record of a read and clearing it destroys
     * nothing, but a committed run is the only record of what was stamped. The
     * procedure refuses with AGORA:RUN_COMMITTED, which reaches the caller as
     * an AgoraProcException with the code intact.
     */
    public function discard(int $branchId, ?string $area = null, ?int $runId = null): int
    {
        $status = $this->procedures->write('usp_Recon_DiscardRuns', [
            'BranchId' => $branchId,
            'ReconArea' => $area,
            'RunId' => $runId,
            'UserId' => auth()->id(),
        ]);

        return (int) $status->Id;
    }

    /**
     * The rows behind one proposal.
     *
     * A proposal is an aggregate — two bank lines totalling R15,137.40 against
     * one deposit — and the questions asked immediately after it are all about
     * the constituents. The ported previews cannot answer those: they return
     * the aggregate, and their bodies are ZP's validated logic, not ours to
     * change into answering something else. So this calls a drill procedure.
     *
     * It replays the run's OWN arguments rather than the current defaults. The
     * extraction positions come from BRN_AutoReconCriteria, which the customer
     * edits, and the readings (@BatchKey, @MatchMode, @MopsConvention) decide
     * which reference a line resolves to — so a drill run with anything else
     * could show lines the aggregate above it never counted.
     *
     * The run is passed rather than read off the line's relation. Lazy loading
     * is disabled outside production, so reaching through `$line->run` here
     * would be a violation waiting for the first caller that did not eager
     * load — and every caller already has the run in hand.
     *
     * @return array{bank: Collection<int, object>, mops: Collection<int, object>}
     */
    public function drill(ReconRun $run, ReconRunLine $line): array
    {
        $replayed = ['RuleOrder', 'BatchKey', 'MatchMode', 'MopsConvention',
            'BankStartOverride', 'BankLenOverride'];

        $params = [
            'ReconArea' => $run->ReconArea,
            'BranchId' => $run->BranchId,
            'FromDate' => $run->FromDate->toDateString(),
            'ToDate' => $run->ToDate->endOfDay()->toDateTimeString(),
            'KeyRef' => $line->KeyRef,
            'KeyRef2' => $line->KeyRef2,
            'BankLineId' => $line->BankLineId,
            'WindowFrom' => $line->WindowFrom?->toDateTimeString(),
            'WindowTo' => $line->WindowTo?->toDateTimeString(),
            // Null on an ordinary row; on a paired one it is the reference the
            // deposits are actually under, and looking for them under the
            // bank's would return nothing.
            'MopsKeyRef' => $line->MopsKeyRef,
            // Only CashBags fills this, and only on a proposal that IS one
            // deposit. It is what lets an orphan be drilled at all.
            'MopsSourceId' => $line->MopsSourceId,
        ];

        foreach (array_intersect_key($run->params(), array_flip($replayed)) as $name => $value) {
            $params[$name] = $value;
        }

        /*
         * Two calls, not one.
         *
         * The drill is split because `INSERT INTO @t EXEC` captures only the
         * first result set, and usp_Recon_Commit has to capture BOTH sides to
         * know what it is stamping. Splitting it is what makes the rows the
         * operator looked at and the rows that get stamped come out of the
         * same extraction rather than two copies of it.
         */
        // DrillBank has no use for the deposit-side reference and would reject
        // an argument it does not declare.
        $bankParams = $params;
        unset($bankParams['MopsKeyRef'], $bankParams['MopsSourceId']);

        return [
            'bank' => $this->procedures->call('usp_Recon_DrillBank', $bankParams),
            'mops' => $this->procedures->call('usp_Recon_DrillMops', $params),
        ];
    }

    /**
     * The two sides of one proposal, whatever state it is in.
     *
     * A COMMITTED line cannot be drilled. The drill is how a PROPOSAL is
     * derived, so both halves filter to what is still outstanding —
     * `ReconState = 1` on the bank and `ReconBatchNoPumpIT = 0` on the
     * deposit — and committing sets exactly those columns. Expanding a row
     * that reconciled perfectly therefore returned nothing on both sides, and
     * the panel said "Nothing on the statement carries this reference" and
     * "Nothing was declared against this reference" about a batch that had
     * just been stamped successfully. Reported by Ryan, 7 September 2026.
     *
     * So a committed line is read from `agora.ReconMatch` instead, which is
     * the record the commit wrote of exactly which rows it touched and for how
     * much. That is better than widening the drill to include reconciled rows:
     * the drill would return everything sharing the key and the window — the
     * same confusion that made usp_Recon_Commit skip 127 batches — whereas
     * ReconMatch names the batch's own rows and cannot drift as the estate
     * moves underneath it.
     *
     * The shapes match the drill's exactly, so the panel renders unchanged.
     *
     * @return array{bank: Collection<int, object>, mops: Collection<int, object>}
     */
    public function sides(ReconRun $run, ReconRunLine $line): array
    {
        if (! $line->isCommitted()) {
            return $this->drill($run, $line);
        }

        $schema = config('agora.schema');
        $connection = DB::connection(config('agora.connections.app'));

        /*
         * Joined back to the bank view for the narrative, which ReconMatch has
         * no column for. The view is a plain read over the table and carries
         * no ReconState filter of its own, so it still returns a line that has
         * since been stamped — which is the whole point here.
         *
         * The extraction window comes off the RUN LINE rather than being
         * recomputed, so the marked characters in the narrative are the ones
         * this proposal actually matched on.
         */
        $bank = $connection->select("
            SELECT l.BankStatementLineID,
                   l.LineDate,
                   l.Description,
                   m.Amount,
                   CASE WHEN ? = 'ABSA' THEN RIGHT(RTRIM(l.Description), 2) END AS Leg,
                   ? AS UsedBankStart,
                   ? AS UsedBankLen
            FROM [{$schema}].[ReconMatch] m
            JOIN [{$schema}].[vw_BankStatementLine] l
              ON l.BankStatementLineID = m.SourceId AND l.BranchId = m.BranchId
            WHERE m.RunLineId = ? AND m.Side = 'bank'
            ORDER BY l.LineDate, l.BankStatementLineID
        ", [$run->ReconArea, $line->UsedBankStart, $line->UsedBankLen, $line->Id]);

        /*
         * The deposit side has no single id across the family, so the commit
         * stored its key as JSON. Read back out of it here rather than
         * re-querying the source table, which would have to guess which of the
         * BRN_DailyBanking* tables and would find nothing anyway now that
         * ReconBatchNoPumpIT is set.
         */
        $mops = $connection->select("
            SELECT m.SourceDate,
                   JSON_VALUE(m.SourceKeyJson, '$.ref') AS SourceRef,
                   JSON_VALUE(m.SourceKeyJson, '$.key') AS Detail,
                   m.Amount
            FROM [{$schema}].[ReconMatch] m
            WHERE m.RunLineId = ? AND m.Side = 'mops'
            ORDER BY m.SourceDate, m.Id
        ", [$line->Id]);

        return [
            'bank' => collect($bank),
            'mops' => collect($mops),
        ];
    }

    /**
     * Build the argument list, named, in the order the procedure declares.
     *
     * Only arguments this area actually takes are sent: passing @MatchMode to
     * the ABSA procedure is an error, not a harmless extra.
     *
     * @param  array<string, scalar|null>  $options
     * @return array<string, scalar|null>
     */
    protected function parameters(string $area, int $branchId, Carbon $from, Carbon $to, array $options): array
    {
        $params = [
            'BranchId' => $branchId,
            'FromDate' => $from->toDateString(),
            'ToDate' => $to->endOfDay()->toDateTimeString(),
        ];

        foreach ($this->optionsFor($area) as $name => $declaration) {
            $value = $options[$name] ?? $declaration['default'];

            // A null override is the procedure's own default — send nothing
            // rather than an explicit NULL, so the default stays the
            // procedure's business.
            if ($value === null || $value === '') {
                continue;
            }

            $params[$name] = match ($declaration['type'] ?? 'choice') {
                'int' => (int) $value,
                'bool' => (int) (bool) $value,
                default => (string) $value,
            };
        }

        // The overrides only mean anything as a pair: a start with no length
        // extracts to the end of the narrative, which is not what the person
        // setting it intended.
        if (isset($params['BankStartOverride']) !== isset($params['BankLenOverride'])) {
            unset($params['BankStartOverride'], $params['BankLenOverride']);
        }

        return $params;
    }

    /**
     * A procedure that cannot run says so in its result set, not by throwing.
     *
     * `SELECT 'No BRN_AutoReconCriteria row for ...' AS Error, @BranchId AS BranchId`
     * — one row, an Error column, and nothing else. Rendering that as a grid
     * would show the operator an empty table and let them conclude the branch
     * is fully reconciled.
     *
     * @param  Collection<int, object>  $rows
     * @param  array<string, mixed>  $definition
     */
    protected function guardAgainstRefusal(Collection $rows, array $definition): void
    {
        $first = $rows->first();

        if ($first !== null && property_exists($first, 'Error')) {
            throw new ReconPreviewRefused(
                (string) $first->Error,
                $definition['procedure'],
                (array) $first,
            );
        }
    }

    /**
     * One procedure row, in the shape ReconRunLine stores.
     *
     * Every column is read with a null coalesce because the five procedures
     * genuinely return different sets — CashBags in `contains` mode has no
     * RulesInGroup and no bag reference column at all, SmartATM has a window
     * instead of a date. Naming the differences here is what lets one grid
     * render all five.
     *
     * @return array<string, mixed>
     */
    protected function line(string $area, object $row, int $lineNo): array
    {
        $get = fn (string ...$names) => collect($names)
            ->map(fn ($n) => $row->{$n} ?? null)
            ->first(fn ($v) => $v !== null);

        return [
            'LineNo' => $lineNo,
            'ReconArea' => $area,

            // FNB separates batched from standalone lines; CashBags reports
            // which matching mode produced the row. Both land here.
            'Population' => $get('Population', 'MatchMode'),

            // The reference the two sides were joined on, whatever it is
            // called in this area.
            'KeyRef' => $this->text($get('BatchRef', 'SlipRef', 'BagRef', 'TerminalRef')),
            'KeyRef2' => $this->text($get('MerchantRef')),

            'BankDate' => $get('BankDate'),
            'WindowFrom' => $get('WindowFrom'),
            'WindowTo' => $get('WindowTo'),
            'BankNarrative' => $this->text($get('BankNarrative'), 400),
            'DeviceRefs' => $this->text($get('DeviceRefs'), 400),
            'BankLineId' => $get('BankLineID', 'BankLineId'),
            'MopsSourceId' => $get('MopsSourceId'),

            'BankLines' => (int) ($row->BankLines ?? 0),
            'BankTotal' => $row->BankTotal ?? 0,
            'BankCC' => $row->BankCC ?? null,
            'BankDD' => $row->BankDD ?? null,

            'MopsTxns' => (int) ($row->MopsTxns ?? 0),
            'MopsTotal' => $row->MopsTotal ?? 0,
            'DiffAmount' => $row->Diff_MOPS_BANK ?? 0,

            'Outcome' => (string) ($row->Outcome ?? 'Unknown'),

            // Stored as the procedure decided it, never re-derived. The whole
            // argument with the legacy code is about how this bit is reached.
            'WouldReconcile' => (bool) ($row->WouldReconcile ?? false),

            'UsedProcessOrder' => $row->UsedProcessOrder ?? null,
            'UsedBankStart' => $row->UsedBankStart ?? null,
            'UsedBankLen' => $row->UsedBankLen ?? null,
            'RulesInGroup' => $row->RulesInGroup ?? null,
        ];
    }

    /** Trim and cap a legacy string, which may be padded CHAR or over-long. */
    protected function text(mixed $value, int $limit = 50): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : Str::limit($trimmed, $limit, '');
    }

    /**
     * Write the run and its lines.
     *
     * One transaction: a run whose header says 40 rows and whose lines table
     * holds 12 is worse than no run at all, because it looks like evidence.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, scalar|null>  $params
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    protected function record(
        string $area,
        int $branchId,
        Carbon $from,
        Carbon $to,
        array $definition,
        array $params,
        Collection $lines,
        int $elapsed,
        ?string $groupRef,
        ?string $note = null,
    ): ReconRun {
        return DB::transaction(function () use (
            $area, $branchId, $from, $to, $definition, $params, $lines, $elapsed, $groupRef, $note
        ) {
            $counts = $this->counts($lines);

            $run = ReconRun::create([
                'BranchId' => $branchId,
                'GroupRef' => $groupRef ?? (string) Str::uuid(),
                'ReconArea' => $area,
                'FromDate' => $from->toDateString(),
                'ToDate' => $to->toDateString(),
                'Status' => 'previewed',
                'StampMode' => config('recon.stamp_mode'),
                'ProcedureName' => config('agora.schema').'.'.$definition['procedure'],
                'ParamsJson' => json_encode($params),
                'PreviewMs' => $elapsed,
                // What the person called this run. Null where they did not
                // name it — the run list describes an unnamed run by its
                // period rather than showing a dash.
                'Note' => $note,
                'CreatedBy' => auth()->id(),
                'CreatedAt' => now(),
                ...$counts,
            ]);

            // insert() rather than a model save per row: a busy branch-month
            // is a few hundred proposals and each one would otherwise be its
            // own round trip.
            //
            // CHUNKED, because SQL Server refuses a statement carrying more
            // than 2,100 bound parameters and a multi-row insert binds every
            // column of every row. The local stub has four proposals and never
            // came close; one real branch-month of FNB is several hundred, and
            // the first real run died with "Tried to bind parameter number
            // 2101". The chunk is derived from the column count rather than
            // guessed, so adding a column to ReconRunLine cannot quietly move
            // the ceiling back under us.
            if ($lines->isNotEmpty()) {
                $rows = $lines->map(fn (array $line) => $line + [
                    'BranchId' => $branchId,
                    'RunId' => $run->Id,
                    'CreatedBy' => auth()->id(),
                    'CreatedAt' => now(),
                ]);

                $perRow = max(1, count($rows->first()));
                $model = $run->lines()->getRelated();

                foreach ($rows->chunk(intdiv(2000, $perRow)) as $chunk) {
                    $model->newQuery()->insert($chunk->all());
                }
            }

            /*
             * The last thing a preview does: join the orphans that are the
             * same reconciliation split in two by an extraction a character
             * out, and recompute the run's counts around them.
             *
             * Inside the transaction, because a run whose lines are written
             * but unpaired reports 31 findings where there are 20 — and its
             * header counts would disagree with its own rows.
             */
            $this->procedures->write('usp_Recon_PairNearReferences', [
                'RunId' => $run->Id,
                'BranchId' => $branchId,
            ]);

            /*
             * fresh(), not load().
             *
             * The pairing pass rewrites the RUN's own counts as well as its
             * lines — a merge changes TotalRows, MatchedRows and the orphan
             * counts. load() reloads the relation and leaves the model's own
             * attributes exactly as they were when it was inserted, so the
             * header would say 31 while the rows underneath it said 20.
             */
            return $run->fresh('lines');
        });
    }

    /**
     * The header counts, taken from the lines rather than from a second pass
     * over the database.
     *
     * MatchedRows counts WouldReconcile, not the word "Matched". They are the
     * same thing here and are NOT the same thing in the legacy procedure,
     * which is the distinction the whole rebuild turns on.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<string, int|float|string>
     */
    protected function counts(Collection $lines): array
    {
        $is = fn (string $prefix) => fn (array $l) => str_starts_with($l['Outcome'], $prefix);
        $matched = $lines->filter(fn (array $l) => $l['WouldReconcile']);

        return [
            'TotalRows' => $lines->count(),
            'MatchedRows' => $matched->count(),
            'MismatchRows' => $lines->filter($is('Amount mismatch'))->count(),
            'BankOnlyRows' => $lines->filter($is('Bank only'))->count(),
            'DepositOnlyRows' => $lines->filter($is('Deposit only'))->count(),
            'OtherRows' => $lines->reject(fn (array $l) => $l['WouldReconcile']
                || str_starts_with($l['Outcome'], 'Amount mismatch')
                || str_starts_with($l['Outcome'], 'Bank only')
                || str_starts_with($l['Outcome'], 'Deposit only'))->count(),

            'BankTotal' => (float) $lines->sum(fn (array $l) => (float) $l['BankTotal']),
            'MopsTotal' => (float) $lines->sum(fn (array $l) => (float) $l['MopsTotal']),
            'MatchedTotal' => (float) $matched->sum(fn (array $l) => (float) $l['BankTotal']),
        ];
    }
}
