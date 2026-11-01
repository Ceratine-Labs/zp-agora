<?php

namespace Modules\StockRecon\Services;

use Illuminate\Support\Collection;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;

/** Explains saved procedure findings; never calculates or changes a reconciliation. */
class StockReconHelper
{
    /** @var array<string, array{label: string, lesson: string, help: string}> */
    public const TOPICS = [
        'overview' => ['label' => 'Where do I start?', 'lesson' => 'workflow', 'help' => 'Check the saved scope and incomplete days first. Choose an item below, read its evidence, then return to the run to review any intended action.'],
        'held' => ['label' => 'Why is a chain held?', 'lesson' => 'holds', 'help' => 'Use the saved hold and outcome, rather than assuming every exception blocks a chain. Resolve the stated source problem; changing a limit is not evidence that the count is correct.'],
        'issues' => ['label' => 'Issues and duplicates', 'lesson' => 'missing-issues', 'help' => 'An issue is stock supplied into the counting area. A missing-issue proposal and a duplicate removal change that quantity, separately from moving a count between shifts.'],
        'counts' => ['label' => 'Which counts am I reading?', 'lesson' => 'figures', 'help' => 'Original counts, the live counts captured at preview and this run’s proposed counts are different bases. A saved live count is not a fresh reading of PumpIT.'],
        'shorts' => ['label' => 'Why is a short left?', 'lesson' => 'residual', 'help' => 'Count balancing can move a difference between shifts while preserving the anchored total. A remaining short needs evidence; its quantity does not identify a responsible person.'],
        'posting' => ['label' => 'Was this actually written?', 'lesson' => 'commit-recovery', 'help' => 'Read the run status and each shift’s commit state. A proposed outcome, even one worded “removed” or “captured”, is not proof that it was written.'],
    ];

    /**
     * Routing choices from the saved preview, using existing model classifications.
     * One link per item/area; the evidence itself comes from DrillChain.
     *
     * @param  Collection<int, StockReconRunLine>  $lines
     * @return Collection<int, StockReconRunLine>
     */
    public function choices(Collection $lines, string $topic): Collection
    {
        return $lines->groupBy(fn (StockReconRunLine $line) => $line->AreaNo.'|'.$line->StockItemNo)
            ->filter(fn (Collection $chain) => $chain->contains(fn (StockReconRunLine $line) => match ($topic) {
                'held' => (bool) $line->ChainBlocked,
                'issues' => $line->DupTier !== null || $line->QtyIssuedNew !== null
                    || in_array($line->ExceptionCode, ['S1', 'S5', 'U1', 'U2', 'U3', 'U4'], true),
                'counts' => (bool) $line->FlagChainBroken || $line->CountDriftReason !== null
                    || $line->AuditCode !== null || (bool) $line->RestoresHand,
                'shorts' => $line->outcomeKey() === 'short' || $line->chainState() === 'Short',
                'posting' => (bool) $line->WouldAmend || in_array($line->CommitState, ['committed', 'skipped'], true),
                default => true,
            }))
            ->map(fn (Collection $chain) => $chain->first())
            ->values();
    }

    /**
     * @param  Collection<int, StockReconRunLine>  $lines
     * @return list<array{title: string, tone: string, terms: array<string, string>, lesson: string}>
     */
    public function explain(StockReconRun $run, Collection $lines): array
    {
        $cases = [];
        if ($lines->contains(fn (StockReconRunLine $line) => (bool) $line->ChainBlocked)) {
            $duplicateHold = $lines->contains(fn (StockReconRunLine $line) => (bool) $line->DupHold);
            $cases[] = $this->case('This chain was held in the saved calculation', 'serious', 'holds', [
                'What it means' => $duplicateHold
                    ? 'A possible-duplicate hold blocks count changes, issue changes and hand-amendment restoration on this chain. The saved outcome records the hold; no restoration exception bypasses it.'
                    : 'Ordinary balancing was held back. A separate hand-amendment restoration can still appear; the saved outcome explains what this run actually proposed.',
                'Review next' => 'Read the outcome and any recorded hold or skip reason below. Check the source counts, issue documents and POS links named by that evidence. Keep the hold in place while the cause is unresolved.',
            ]);
        }
        if ($lines->contains(fn (StockReconRunLine $line) => $line->DupTier === 'D2')) {
            $cases[] = $this->case('A possible duplicate needs source evidence', 'warn', 'duplicates', [
                'What it means' => 'The preview found a possible repeated issue. The quantities alone did not establish which issue was real. The saved hold and release reference describe the decision at preview time.',
                'Review next' => 'Check the issue book, delivery note and storeroom count. Open the chain’s duplicate case to review current release evidence. An authorised release needs a reason and a fresh preview; the helper does not release it.',
            ]);
        }
        if ($lines->contains(fn (StockReconRunLine $line) => $line->DupTier === 'D1')) {
            $cases[] = $this->case('The preview found a clear duplicate', 'warn', 'duplicates', [
                'What it means' => 'D1 identifies a clear duplicate finding. Read the proposed issue and saved outcome to see whether this run proposed removing it; the finding alone does not prove a write.',
                'Review next' => 'Compare the supporting pair of shifts in the duplicate case. Related count changes may require the duplicate correction in the same commit. Confirm the actual commit result afterwards.',
            ]);
        }
        if ($lines->contains(fn (StockReconRunLine $line) => $line->capturesIssue())) {
            $cases[] = $this->case('A missing issue was proposed', 'warn', 'missing-issues', [
                'What it means' => 'This run proposes increasing the issue quantity to account for a remaining over. That changes the issue total; it is separate from redistributing a count difference.',
                'Review next' => 'Compare the proposed issue with the issue sheet, receipts and POS evidence. Check the saved outcome and commit state before treating “Missing issue captured” as a completed write.',
            ]);
        }
        if ($lines->contains(fn (StockReconRunLine $line) => (bool) $line->FlagChainBroken
            || $line->CountDriftReason !== null || $line->AuditCode !== null || (bool) $line->RestoresHand)) {
            $cases[] = $this->case('Separate the count bases', 'neutral', 'chains', [
                'What it means' => 'A break compares an opening with the preceding closing. Count drift compares original and live counts saved at preview. An audit code is a separate check on amendments; drift alone does not prove a breach.',
                'Review next' => 'Compare original, live-at-preview and proposed counts below. Check the count sheet and handover record. Reset to original changes stock records and needs its own review; it is not a way to refresh this helper.',
            ]);
        }
        if ($lines->contains(fn (StockReconRunLine $line) => $line->outcomeKey() === 'short' || $line->chainState() === 'Short')) {
            $cases[] = $this->case('A shortage needs an explanation', 'warn', 'residual', [
                'What it means' => 'Count balancing alone cannot remove the segment’s anchored net short. The saved original and proposed variances show where the calculation left it.',
                'Review next' => 'Check counts, issue records, POS linkage and any approved waste treatment. A short by itself does not establish theft, responsibility or a staff charge.',
            ]);
        }
        $cases[] = $this->case('Check what this run actually did', 'neutral', 'commit-recovery', [
            'Run status' => $run->CommitKind === 'reset'
                ? 'This run records Reset to original'.($run->Status === 'reversed' ? ', since reversed' : '').'. Its line commit states refer to the reset operation. The saved balancing proposals were not the reset writes; their quantities and outcomes remain the earlier calculation.'
                : match ($run->Status) {
                    'committed' => 'This run has been committed. Read the shift commit states: the run may include unticked or skipped proposals. Use a fresh preview for further amendments.',
                    'reversed' => 'This run was reversed. Its proposals may be pending again; the saved calculation has not become a fresh source reading.',
                    'previewed' => 'This is a saved preview. Proposed outcomes are not evidence that a stock change was posted.',
                    default => 'This run is recorded as '.$run->Status.'. Read its result before treating any proposal as complete.',
                },
            'Review next' => $run->CommitKind === 'reset'
                ? 'Read the reset entries in the Audit trail for the exact before-and-after values and skips. Open the fresh preview created after the reset, or prepare another preview to read the current source. Do not use the old proposed balancing figures to verify reset writes.'
                : 'Return to Proposals to inspect the intended selection and actual results. Ticks on other pages remain selected; outcome and column filters can exclude rows. Any successful write commits the whole run. The helper has no amendment, reset, release or reversal controls.',
        ]);

        return $cases;
    }

    /**
     * @param  array<string, string>  $terms
     * @return array{title: string, tone: string, terms: array<string, string>, lesson: string}
     */
    private function case(string $title, string $tone, string $lesson, array $terms): array
    {
        return compact('title', 'tone', 'lesson', 'terms');
    }
}
