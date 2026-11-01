<?php

namespace Tests\Unit\StockRecon;

use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Services\StockReconHelper;
use PHPUnit\Framework\TestCase;

class HelperTest extends TestCase
{
    public function test_questions_use_saved_findings_and_keep_an_item_in_different_areas_separate(): void
    {
        $lines = collect([
            $this->line(['Id' => 1, 'AreaNo' => 1, 'StockItemNo' => 'A']),
            $this->line(['Id' => 2, 'AreaNo' => 1, 'StockItemNo' => 'A', 'ChainBlocked' => true, 'DupTier' => 'D2']),
            $this->line(['Id' => 3, 'AreaNo' => 2, 'StockItemNo' => 'A', 'CountDriftReason' => 'Opening']),
            $this->line(['Id' => 4, 'AreaNo' => 1, 'StockItemNo' => 'B', 'QtyVarNew' => '-2', 'ChainNetVar' => '-2']),
            $this->line(['Id' => 5, 'AreaNo' => 1, 'StockItemNo' => 'C', 'QtyIssuedNew' => '5', 'WouldAmend' => true]),
            $this->line(['Id' => 6, 'AreaNo' => 1, 'StockItemNo' => 'D', 'CommitState' => 'skipped']),
        ]);
        $helper = new StockReconHelper;
        foreach (['overview' => [1, 3, 4, 5, 6], 'held' => [1], 'issues' => [1, 5],
            'counts' => [3], 'shorts' => [4], 'posting' => [5, 6]] as $topic => $ids) {
            $this->assertSame($ids, $helper->choices($lines, $topic)->pluck('Id')->all(), $topic);
        }
    }

    public function test_a_duplicate_hold_never_becomes_permission_to_release_or_reset(): void
    {
        $run = (new StockReconRun)->forceFill(['Status' => 'previewed']);
        $line = $this->line(['DupTier' => 'D2', 'DupHold' => true, 'ChainBlocked' => true, 'CountDriftReason' => 'Opening']);
        $before = $line->getAttributes();
        $cases = (new StockReconHelper)->explain($run, collect([$line]));
        $text = json_encode($cases, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Keep the hold in place', $text);
        $this->assertStringContainsString('blocks count changes, issue changes and hand-amendment restoration', $text);
        $this->assertStringNotContainsString('A separate hand-amendment restoration can still appear', $text);
        $this->assertStringContainsString('An authorised release needs a reason and a fresh preview', $text);
        $this->assertStringContainsString('Reset to original changes stock records and needs its own review', $text);
        $this->assertStringContainsString('not evidence that a stock change was posted', $text);
        $this->assertSame($before, $line->getAttributes());
    }

    public function test_issue_proposals_and_committed_runs_are_explained_without_claiming_every_row_was_written(): void
    {
        $run = (new StockReconRun)->forceFill(['Status' => 'committed']);
        $cases = (new StockReconHelper)->explain($run, collect([
            $this->line(['DupTier' => 'D1', 'QtyIssuedNew' => '0']),
            $this->line(['QtyIssuedNew' => '5', 'CommitState' => 'skipped']),
        ]));
        $titles = array_column($cases, 'title');
        $this->assertContains('The preview found a clear duplicate', $titles);
        $this->assertContains('A missing issue was proposed', $titles);
        $text = json_encode($cases, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('the run may include unticked or skipped proposals', $text);
        $this->assertStringContainsString('Use a fresh preview for further amendments', $text);
        $this->assertStringNotContainsString('Press Reset', $text);
    }

    public function test_a_short_explanation_keeps_responsibility_separate_from_the_quantity(): void
    {
        $run = (new StockReconRun)->forceFill(['Status' => 'reversed']);
        $text = json_encode((new StockReconHelper)->explain($run, collect([
            $this->line(['ChainNetVar' => '-5', 'QtyVarNew' => '-5']),
        ])), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('cannot remove the segment', $text);
        $this->assertStringContainsString('does not establish theft, responsibility or a staff charge', $text);
        $this->assertStringContainsString('has not become a fresh source reading', $text);
    }

    public function test_a_reset_commit_does_not_claim_the_retained_balancing_proposal_was_written(): void
    {
        $run = (new StockReconRun)->forceFill(['Status' => 'committed', 'CommitKind' => 'reset']);
        $line = $this->line(['QtyOpenOrig' => '10', 'QtyOpenNew' => '14', 'QtyCloseNew' => '7', 'CommitState' => 'committed']);
        $cases = (new StockReconHelper)->explain($run, collect([$line]));
        $posting = $cases[array_key_last($cases)]['terms'];
        $this->assertStringContainsString('This run records Reset to original', $posting['Run status']);
        $this->assertStringContainsString('balancing proposals were not the reset writes', $posting['Run status']);
        $this->assertStringContainsString('line commit states refer to the reset operation', $posting['Run status']);
        $this->assertStringContainsString('Audit trail', $posting['Review next']);
        $this->assertStringContainsString('fresh preview created after the reset', $posting['Review next']);
        $this->assertSame('14', $line->QtyOpenNew, 'Explaining a reset must not rewrite the saved proposal.');
    }

    /** @param array<string, mixed> $attributes */
    private function line(array $attributes): StockReconRunLine
    {
        return (new StockReconRunLine)->forceFill($attributes + [
            'Id' => 1, 'AreaNo' => 1, 'StockItemNo' => 'A', 'QtyVarNew' => '0',
            'ChainNetVar' => '0', 'QtyIssuedNew' => null, 'DupTier' => null,
            'Outcome' => 'No change', 'CommitState' => 'pending',
        ]);
    }
}
