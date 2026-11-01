<?php

namespace Modules\StockRecon\Services;

use Illuminate\Support\Collection;
use Modules\StockRecon\Models\StockReconRunLine;

/** The saved lines behind the preview procedure's header aggregates. */
class ProposalMetric
{
    private const EPSILON = 0.005;

    public const LABELS = [
        'amended' => 'Shifts to amend',
        'balance' => 'Chains to balance',
        'reported' => 'Chains reported',
        'unrecorded' => 'Unrecorded issue',
        'over-before' => 'Shifts over before',
        'over-after' => 'Shifts over after',
        'short-before' => 'Shifts short before',
        'short-after' => 'Shifts short after',
        'dormant' => 'Dormant shifts',
        'openings' => 'Openings repaired',
        'audit' => 'Hand balancing breaches',
    ];

    /** @param Collection<int, StockReconRunLine> $lines
     *  @return Collection<int, StockReconRunLine> One representative per chain for chain metrics.
     */
    public function records(Collection $lines, string $metric): Collection
    {
        if (in_array($metric, ['balance', 'reported', 'unrecorded'], true)) {
            return $lines->groupBy(fn ($line) => json_encode([$line->AreaNo, $line->StockItemNo]))
                ->filter(fn ($chain) => match ($metric) {
                    'balance' => $chain->contains(fn ($line) => $line->WouldAmend && ! $line->ChainBlocked),
                    'reported' => $chain->contains(fn ($line) => $line->ChainBlocked),
                    'unrecorded' => (float) $chain->max('ChainNetVar') > self::EPSILON,
                })
                ->map(function ($chain) {
                    $line = clone $chain->first();
                    $line->setAttribute('metricChainTotal', $chain->max('ChainNetVar'));
                    $line->setAttribute('metricSellPrice', $chain->max('SellPrice'));

                    return $line;
                })->values();
        }

        return $lines->filter(fn ($line) => match ($metric) {
            // The header counts RowMoves or IssueWrite. A missing-issue write
            // can also set WouldAmend, but is not included in AmendedRows.
            'amended' => $line->CountsMove || $line->removesDuplicate(),
            'over-before' => (float) $line->QtyVar > self::EPSILON,
            'over-after' => (float) $line->QtyVarNew > self::EPSILON,
            'short-before' => (float) $line->QtyVar < -self::EPSILON,
            'short-after' => (float) $line->QtyVarNew < -self::EPSILON,
            'dormant' => $line->IsDormant,
            'openings' => $line->FlagChainBroken && (! $line->ChainBlocked || $line->RestoresHand),
            'audit' => $line->AuditCode !== null,
            default => false,
        })->values();
    }

    public function isChain(string $metric): bool
    {
        return in_array($metric, ['balance', 'reported', 'unrecorded'], true);
    }
}
