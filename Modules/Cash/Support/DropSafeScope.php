<?php

namespace Modules\Cash\Support;

use App\Support\BranchContext;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What the Drop safe screens are about: one site, the days whose collections
 * are listed, and — when a bag is being corrected — that bag.
 *
 * Read from the request in ONE place, because the page and every press read
 * it, and a press that parsed the site differently from the page would write a
 * bag into a safe the person was not looking at.
 *
 * THE SITE IS CHECKED HERE, as TillBalanceScope checks it: in the branch
 * workspace the pinned site wins whatever arrived; in head office an id the
 * person may not see is treated as no site at all. It reaches the procedures as
 * a named argument, so nothing else stands between a hand-edited `branch_id`
 * and another site's safe.
 *
 * What is IN the safe is not scoped by the days — a bag stays in it until it
 * is collected, however long that takes (PumpIT's own collection list has no
 * date limit either). The days bound the collections listed beside it.
 */
final class DropSafeScope
{
    public function __construct(
        public readonly ?int $branchId,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $bagNo = null,
    ) {}

    public static function fromRequest(Request $request, BranchContext $context): self
    {
        $days = max(1, (int) config('cash.dropsafe.default_days', 14));
        $max = max(1, (int) config('cash.dropsafe.max_days', 93));

        $to = self::date($request->input('date_to')) ?? BusinessDate::today()->toDateString();
        $from = self::date($request->input('date_from')) ?? Carbon::parse($to)->subDays($days - 1)->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) >= $max) {
            $from = Carbon::parse($to)->subDays($max - 1)->toDateString();
        }

        $bag = trim((string) $request->input('bag', ''));

        return new self(
            branchId: self::site($request, $context),
            from: $from,
            to: $to,
            bagNo: $bag !== '' && mb_strlen($bag) <= 20 ? $bag : null,
        );
    }

    public function isChosen(): bool
    {
        return $this->branchId !== null;
    }

    public function withBag(?string $bagNo): self
    {
        return new self($this->branchId, $this->from, $this->to, $bagNo);
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
            'date_from' => $this->from,
            'date_to' => $this->to,
            'bag' => $this->bagNo,
        ], fn ($value) => $value !== null && $value !== '');
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
