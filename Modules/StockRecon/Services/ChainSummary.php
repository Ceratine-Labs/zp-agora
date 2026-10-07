<?php

namespace Modules\StockRecon\Services;

use App\Support\Format;
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

        $verdict = self::verdict(
            (float) $first->ChainNetVar,
            $this->lines->filter(fn ($l) => $l->WouldAmend)->count(),
            $this->lines->contains(fn ($l) => abs((float) $l->QtyCloseNew - (float) $l->QtyClose) > 0.0005
                || abs((float) $l->QtyOpenNew - (float) $l->QtyOpen) > 0.0005),
        );

        return ['label' => $verdict['label'], 'tone' => $verdict['tone'], 'title' => $verdict['note']];
    }

    /**
     * What an UNBLOCKED chain comes to — the one wording the chain row and the
     * chain panel share (Ryan, 7 Oct 2026, on ROOIBOS TEA BAG: "it says
     * balanceable but it shows -2 and i can't do anything with it").
     * "Balanceable" only ever meant "nothing holds it back"; a chain whose
     * total is short with no over anywhere has NOTHING to balance — balancing
     * moves a short onto an over, it never removes one — so it says that.
     *
     * @return array{label: string, tone: string, hint: ?string, note: string}
     */
    public static function verdict(float $total, int $writes, bool $moves): array
    {
        $short = $total < -0.0005;
        $units = Format::n(abs($total), 3);

        return match (true) {
            $writes > 0 && $short => ['label' => 'Balance '.$writes.' '.($writes === 1 ? 'shift' : 'shifts').', short remains',
                'tone' => 'warn', 'hint' => 'a short of '.$units.' stays',
                'note' => 'Balanced; a real short of '.$units.' stays, on the shifts the counts put it on.'],
            $writes > 0 => ['label' => 'Balance '.$writes.' '.($writes === 1 ? 'shift' : 'shifts'),
                'tone' => 'good', 'hint' => 'to nil', 'note' => 'Balances to nil. Amend writes it.'],
            $moves => ['label' => 'Already balanced in PumpIT', 'tone' => 'neutral', 'hint' => 'Reset to original to balance it here',
                'note' => 'The balanced answer is what PumpIT holds: head office balanced it by hand. Reset to original to balance it here.'],
            $short => ['label' => 'Short '.$units.' · nothing to balance', 'tone' => 'warn', 'hint' => 'a real loss: no over to set it against',
                'note' => 'A real loss of '.$units.'. Balancing only moves a short onto an over, and there is no over on this chain, '
                    .'so each short stays on the shift that counted it. Nothing for Amend to write.'],
            default => ['label' => 'No change', 'tone' => 'neutral', 'hint' => null, 'note' => 'Nothing to balance.'],
        };
    }

    /** @return Collection<int, string> one tone per shift, in order — the bar under the first cell */
    public function segments(): Collection
    {
        return $this->lines->map(fn ($l) => $l->tone());
    }
}
