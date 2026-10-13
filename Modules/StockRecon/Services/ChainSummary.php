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
            // "another shift on this item blocks the chain". Failing a row
            // with a state of its own, the first that says what blocks it.
            $reason = $this->lines->first(fn ($l) => ! in_array($l->outcomeKey(), ['blocked', 'dormant', 'clean'], true))
                ?? $this->lines->first(fn ($l) => ! str_starts_with((string) $l->Outcome, 'Held back: another shift'))
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
            self::overLeft($this->lines),
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
    public static function verdict(float $total, int $writes, bool $moves, ?float $overLeft = null): array
    {
        $short = $total < -0.0005;
        $over = $total > 0.0005;
        $units = Format::n(abs($total), 3);
        // The over is CAPTURED as the missing issue (13 Oct 2026) when the run
        // proposes it: nothing is left over on any shift afterwards. A run
        // previewed before then left it, and reads as it did.
        $captured = $over && $overLeft !== null && $overLeft <= 0.0005;

        return match (true) {
            $captured && $writes > 0 => ['label' => 'Balance '.$writes.' '.($writes === 1 ? 'shift' : 'shifts').' · issue of '.$units.' captured',
                'tone' => 'good', 'hint' => 'the over recorded as issued',
                'note' => 'Balances to nil: the over of '.$units.' is recorded as the issue the shift never got '
                    .'(QtyIssued raised, the prior kept, reversible). Amend writes it.'],
            $captured => ['label' => 'Missing issue already in PumpIT', 'tone' => 'neutral', 'hint' => 'nothing to write',
                'note' => 'The over of '.$units.' is covered by an issue already keyed in PumpIT. Nothing for Amend to write.'],
            $writes > 0 && $short => ['label' => 'Balance '.$writes.' '.($writes === 1 ? 'shift' : 'shifts').', short remains',
                'tone' => 'warn', 'hint' => 'a short of '.$units.' stays',
                'note' => 'Balanced; a real short of '.$units.' stays, on the shifts the counts put it on.'],
            /* A chain that received less than it sold (7 Oct 2026): it balances
               around the over, and the over stays on the shifts that carry
               it — the missing issue. */
            $writes > 0 && $over => ['label' => 'Balance '.$writes.' '.($writes === 1 ? 'shift' : 'shifts').', over '.$units.' remains',
                'tone' => 'crit', 'hint' => 'an unrecorded issue of '.$units.' stays',
                'note' => 'Balanced; an over of '.$units.' stays on the shifts that sold or held stock nobody issued. '
                    .'No count can remove it: capture the missing issue there.'],
            $over && ! $moves => ['label' => 'Over '.$units.' · capture the missing issue', 'tone' => 'crit',
                'hint' => 'an unrecorded issue: nothing to balance',
                'note' => 'An over of '.$units.' with no short to set against it: stock was sold or held that was never issued. '
                    .'Capture the missing issue on the shift that carries it.'],
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

    /**
     * What is still over on a chain's shifts after the run's proposal.
     *
     * @template TShift of object
     *
     * @param  Collection<int, TShift>  $shifts  each with QtyVarNew
     */
    public static function overLeft(Collection $shifts): float
    {
        return (float) $shifts->sum(fn ($s) => max(0.0, (float) $s->QtyVarNew));
    }

    /** @return Collection<int, string> one tone per shift, in order — the bar under the first cell */
    public function segments(): Collection
    {
        return $this->lines->map(fn ($l) => $l->tone());
    }

    /**
     * Which shifts balance against which (ZRP, 7 Oct 2026: "you can clearly
     * see that items should be balancing … identification of the rows").
     *
     * Read straight off the amendments. Moving a closing moves variance
     * between that shift and the next, so two neighbouring shifts balance
     * together exactly where the closing between them is amended; a run of
     * them joined that way is one group, and a closing left as counted ends
     * it. A dormant shift inside a group moves with it and is marked with it.
     * Groups of one are not marked: nothing balanced against them. A blocked
     * chain has no amendments, so it has no groups.
     *
     * Keyed by run line id: the group's letter (A, B … per chain), its colour
     * (1–5, the series tokens), the other active shifts in it, and what the
     * group leaves after balancing.
     *
     * @return array<int, array{letter: string, series: int, with: string, net: float}>
     */
    public function balanceGroups(): array
    {
        return self::groupsOfShifts($this->lines);
    }

    /**
     * The same, for any one chain's shifts in order — run lines here, the
     * chain drill's rows on the panel behind a shift. Each needs Id,
     * TransactionDate, ShiftNo, IsDormant, AmendClose and QtyVarNew.
     *
     * @template TShift of object
     *
     * @param  Collection<int, TShift>  $shifts
     * @return array<int, array{letter: string, series: int, with: string, net: float}>
     */
    public static function groupsOfShifts(Collection $shifts): array
    {
        $groups = [];
        $current = [];

        foreach ($shifts as $shift) {
            if ($current !== [] && abs((float) end($current)->AmendClose) < 0.0005) {
                $groups[] = $current;
                $current = [];
            }
            $current[] = $shift;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        $marked = [];
        $n = 0;

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            $letter = chr(65 + $n % 26).($n >= 26 ? (string) intdiv($n, 26) : '');
            $series = $n % 5 + 1;
            $net = (float) array_sum(array_map(fn (object $s) => (float) $s->QtyVarNew, $group));
            $n++;

            foreach ($group as $shift) {
                $with = collect($group)
                    ->filter(fn (object $s) => (int) $s->Id !== (int) $shift->Id && ! $s->IsDormant)
                    ->map(fn (object $s) => Carbon::parse($s->TransactionDate)->format('d M').' shift '.$s->ShiftNo)
                    ->implode(', ');

                $marked[(int) $shift->Id] = ['letter' => $letter, 'series' => $series, 'with' => $with, 'net' => $net];
            }
        }

        return $marked;
    }

    /**
     * The group's tag as a sentence, for the hover.
     *
     * @param  array{letter: string, series: int, with: string, net: float}  $group
     */
    public static function balanceNote(array $group): string
    {
        $units = Format::n(abs($group['net']), 3);

        return 'Group '.$group['letter'].' — balances with '.($group['with'] !== '' ? $group['with'] : 'the shifts beside it').'. '
            .match (true) {
                $group['net'] > 0.0005 => 'Together they leave an over of '.$units.': the missing issue, on the shift that carries it.',
                $group['net'] < -0.0005 => 'Together they leave a short of '.$units.'.',
                default => 'Together they come to nil.',
            };
    }
}
