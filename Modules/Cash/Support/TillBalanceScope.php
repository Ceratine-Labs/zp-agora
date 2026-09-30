<?php

namespace Modules\Cash\Support;

use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What the Till balance screen is answering: one site, a few days, and —
 * optionally — one person's cashups.
 *
 * Read from the request in ONE place, because the page and the press both read
 * it, and a press that parsed the scope differently from the page would move
 * money between cashups the person was not looking at.
 *
 * THE SITE IS CHECKED HERE, as CashupScope checks it: in the branch workspace
 * the pinned site wins whatever arrived; in head office an id the person may
 * not see is treated as no site at all. It reaches the procedures as a named
 * argument, so nothing else stands between a hand-edited `branch_id` and
 * another site's cashups.
 *
 * The period is clamped to cash.tillbalance.max_days, ending on its last day.
 */
final class TillBalanceScope
{
    public function __construct(
        public readonly ?int $branchId,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $employeeCode,
    ) {}

    public static function fromRequest(Request $request, BranchContext $context): self
    {
        $days = max(1, (int) config('cash.tillbalance.default_days', 7));
        $max = max(1, (int) config('cash.tillbalance.max_days', 62));

        $to = self::date($request->input('date_to')) ?? Carbon::yesterday()->toDateString();
        $from = self::date($request->input('date_from')) ?? Carbon::parse($to)->subDays($days - 1)->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) >= $max) {
            $from = Carbon::parse($to)->subDays($max - 1)->toDateString();
        }

        $employee = trim((string) $request->input('employee', ''));

        return new self(
            branchId: self::site($request, $context),
            from: $from,
            to: $to,
            employeeCode: $employee !== '' && mb_strlen($employee) <= 11 ? $employee : null,
        );
    }

    public function isChosen(): bool
    {
        return $this->branchId !== null;
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
            'employee' => $this->employeeCode,
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
