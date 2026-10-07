<?php

namespace Modules\StockRecon\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\StockRecon\Models\StockReconRunLine;

/**
 * One item's chain on a run, as the single row the proposals show it as.
 *
 * Ryan, 7 Oct 2026: the shifts of a chain repeat down the table; collapse them
 * into one row that opens to its shifts, each of which opens to its detail,
 * with a bar under the first cell — a segment per shift, coloured as that
 * shift's outcome. This is the arithmetic of that row: the chain's first
 * opening and last closing, what moved through it, and what the run proposes.
 * The shifts themselves are the run lines, unchanged.
 */
final class ChainSummary
{
    /** @param  Collection<int, StockReconRunLine>  $lines  one chain, in date and shift order */
    private function __construct(public readonly string $key, public readonly Collection $lines) {}

    /**
     * @param  Collection<int, StockReconRunLine>  $lines  a run's lines, in the order they are shown
     * @return Collection<int, self> one per chain, in the order its first shift appears
     */
    public static function of(Collection $lines): Collection
    {
        return $lines
            ->groupBy(fn (StockReconRunLine $line) => $line->AreaNo.'|'.$line->StockItemNo)
            ->map(fn (Collection $chain, string $key) => new self($key, $chain->values()))
            ->values();
    }

    public function first(): StockReconRunLine
    {
        return $this->lines->first();
    }

    public function last(): StockReconRunLine
    {
        return $this->lines->last();
    }

    public function count(): int
    {
        return $this->lines->count();
    }

    public function from(): Carbon
    {
        return $this->first()->TransactionDate;
    }

    public function to(): Carbon
    {
        return $this->last()->TransactionDate;
    }

    public function issued(): float
    {
        return (float) $this->lines->sum(fn ($l) => (float) $l->QtyIssued);
    }

    public function pos(): float
    {
        return (float) $this->lines->sum(fn ($l) => (float) $l->QtyPOS);
    }

    /** The variance as counted, summed: where the Cumulative column ends. */
    public function variance(): float
    {
        return (float) $this->lines->sum(fn ($l) => (float) $l->QtyVar);
    }

    public function varianceNew(): float
    {
        return (float) $this->lines->sum(fn ($l) => (float) $l->QtyVarNew);
    }

    /** Openings set to the previous closing, summed. */
    public function breaks(): float
    {
        return (float) $this->lines->sum(fn ($l) => abs((float) $l->ChainBreak) > 0.0005 ? (float) $l->ChainBreak : 0.0);
    }

    /** Units the balancing moves, whichever way: Σ |amendment|. */
    public function moved(): float
    {
        return (float) $this->lines->sum(fn ($l) => abs($l->amendment()));
    }

    /** Shifts the press can write — the ones a chain tick box ticks. */
    public function writable(): int
    {
        return $this->lines->filter(fn ($l) => $l->isWritable())->count();
    }

    /**
     * What the chain comes to, in a word, and its tone:
     *   held back (and by what), N shifts to balance, already balanced in
     *   PumpIT (the balanced answer is what PumpIT holds), or no change.
     *
     * @return array{label: string, tone: string, title: string}
     */
    public function state(): array
    {
        $first = $this->first();

        if ($first->ChainBlocked) {
            // The shift that holds it, named — every other row says only
            // "another shift on this item blocks the chain".
            $reason = $this->lines->first(fn ($l) => ! in_array($l->outcomeKey(), ['blocked', 'dormant', 'clean'], true))
                ?? $first;

            // Held back, but hand amendments the client's rules forbid are
            // still put back to the counts — and those rows are ticked.
            $writes = $this->writable();

            return ['label' => 'Held back · '.$reason->outcomeLabel().($writes > 0 ? ' · '.$writes.' put back' : ''),
                'tone' => $reason->tone() === 'neutral' ? 'serious' : $reason->tone(),
                'title' => $reason->Outcome.($writes > 0 ? ' — '.$writes.' hand '.($writes === 1 ? 'amendment' : 'amendments').' put back to the counts.' : '')];
        }

        $amend = $this->lines->filter(fn ($l) => $l->WouldAmend)->count();

        if ($amend > 0) {
            $short = (float) $first->ChainNetVar < -0.0005;

            return ['label' => 'Balance '.$amend.' '.($amend === 1 ? 'shift' : 'shifts').($short ? ', short remains' : ''),
                'tone' => $short ? 'warn' : 'good',
                'title' => $short ? 'Balanced, and the chain total stays short: it is placed where the counts put it.' : 'Balances to nil.'];
        }

        $moves = $this->lines->contains(fn ($l) => abs((float) $l->QtyCloseNew - (float) $l->QtyClose) > 0.0005
            || abs((float) $l->QtyOpenNew - (float) $l->QtyOpen) > 0.0005);

        return $moves
            ? ['label' => 'Already balanced in PumpIT', 'tone' => 'neutral',
                'title' => 'The balanced answer is what PumpIT holds: head office balanced it by hand. Reset to original to balance it here.']
            : ['label' => 'No change', 'tone' => 'neutral', 'title' => 'Nothing to balance.'];
    }

    /** @return Collection<int, string> one tone per shift, in order — the bar under the first cell */
    public function segments(): Collection
    {
        return $this->lines->map(fn ($l) => $l->tone());
    }
}
