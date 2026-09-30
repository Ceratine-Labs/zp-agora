<?php

namespace Modules\Cash\Support;

use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What the ZREAD Allocation screen is answering: one site, one period, one POS
 * location or all of them, and whether the allocated Z-reads are shown too.
 *
 * Read from the request in ONE place, because four things read it — the page,
 * the grid's procedure arguments, the extract, and every press on the run bar
 * — and a scope that two of them parse differently is a press that acts on a
 * different period from the one on the screen.
 *
 * THE SITE IS CHECKED HERE, not merely parsed. It reaches the procedures as a
 * named argument rather than through GridService's branch handling, so nothing
 * else stands between a hand-edited `branch_id` and another site's till
 * readings. In the branch workspace the pinned site wins whatever arrived; in
 * head office an id the person may not see is treated as no site at all.
 *
 * The parameter names are deliberately NOT the grid's own (`from`, `to`,
 * `branches`): the grid's scope form carries every other query parameter
 * through as a hidden field, and only a name it does not own survives a search
 * or a header filter.
 */
final class ZreadScope
{
    public function __construct(
        public readonly ?int $branchId,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $location,
        public readonly string $show,
    ) {}

    public static function fromRequest(Request $request, BranchContext $context): self
    {
        $days = max(1, (int) config('cash.zread.default_days', 10));

        $to = self::date($request->input('date_to')) ?? Carbon::yesterday()->toDateString();
        $from = self::date($request->input('date_from'))
            ?? Carbon::parse($to)->subDays($days - 1)->toDateString();

        $location = strtoupper(trim((string) $request->input('location', '')));
        $location = in_array($location, (array) config('cash.zread.locations', []), true) ? $location : null;

        return new self(
            branchId: self::site($request, $context),
            from: $from,
            to: $to,
            location: $location,
            show: $request->input('show') === 'all' ? 'all' : 'open',
        );
    }

    /** A site has been chosen and may be read. */
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
            'location' => $this->location,
            'show' => $this->show === 'all' ? 'all' : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** The same scope for one day — what a single Z-read's press acts on. */
    public function forDay(string $date, ?string $location): self
    {
        return new self($this->branchId, $date, $date, $location, $this->show);
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
