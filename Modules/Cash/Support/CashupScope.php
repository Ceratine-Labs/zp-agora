<?php

namespace Modules\Cash\Support;

use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What the Daily Banking screen is answering: one site, one trading day, and —
 * once one is picked — one till and shift, which is one cashup.
 *
 * Read from the request in ONE place, because the page, the card load, and
 * every press (save, confirm, close) read it, and a press that parsed the
 * scope differently from the page would act on a cashup the person was not
 * looking at.
 *
 * THE SITE IS CHECKED HERE, as ZreadScope checks it: in the branch workspace
 * the pinned site wins whatever arrived; in head office an id the person may
 * not see is treated as no site at all. It reaches the procedures as a named
 * argument, so nothing else stands between a hand-edited `branch_id` and
 * another site's cashups.
 */
final class CashupScope
{
    public function __construct(
        public readonly ?int $branchId,
        public readonly string $date,
        public readonly ?int $tillNo,
        public readonly ?int $shiftNo,
    ) {}

    public static function fromRequest(Request $request, BranchContext $context): self
    {
        return new self(
            branchId: self::site($request, $context),
            date: self::date($request->input('date')) ?? Carbon::yesterday()->toDateString(),
            tillNo: self::number($request->input('till')),
            shiftNo: self::number($request->input('shift')),
        );
    }

    public function isChosen(): bool
    {
        return $this->branchId !== null;
    }

    /** One cashup is named: site, day, till and shift. */
    public function isOne(): bool
    {
        return $this->branchId !== null && $this->tillNo !== null && $this->shiftNo !== null;
    }

    /**
     * The scope as query parameters, for a link or a redirect.
     *
     * @return array<string, string|int>
     */
    public function query(): array
    {
        return array_filter([
            'branch_id' => $this->branchId,
            'date' => $this->date,
            'till' => $this->tillNo,
            'shift' => $this->shiftNo,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** The same site and day, with a different cashup — or none. */
    public function withCashup(?int $tillNo, ?int $shiftNo): self
    {
        return new self($this->branchId, $this->date, $tillNo, $shiftNo);
    }

    /**
     * The procedures' own names for the one cashup.
     *
     * @return array{BranchId: int|null, TransactionDate: string, TillNo: int|null, ShiftNo: int|null}
     */
    public function key(): array
    {
        return [
            'BranchId' => $this->branchId,
            'TransactionDate' => $this->date,
            'TillNo' => $this->tillNo,
            'ShiftNo' => $this->shiftNo,
        ];
    }

    private static function site(Request $request, BranchContext $context): ?int
    {
        if ($context->isBranchWorkspace() && $context->id() !== null) {
            return $context->id();
        }

        $given = $request->input('branch_id');

        if (! is_scalar($given) || (int) $given < 1) {
            return null;
        }

        return $context->maySee((int) $given) ? (int) $given : null;
    }

    private static function number(mixed $value): ?int
    {
        if (! is_scalar($value) || trim((string) $value) === '' || ! ctype_digit(trim((string) $value))) {
            return null;
        }

        return (int) $value;
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
