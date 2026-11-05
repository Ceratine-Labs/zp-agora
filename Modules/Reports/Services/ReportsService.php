<?php

namespace Modules\Reports\Services;

use App\Support\BusinessDate;
use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * All of this module's logic that is not in T-SQL — which is not much, and that
 * is the point.
 *
 * Every figure comes out of an `agora.usp_Reports_Grid*` procedure. This class
 * chooses which one, hands it the eight parameters, and returns the page and
 * the total. No filtering, no sorting and no arithmetic happens here: if it did,
 * the customer could no longer change a report by opening the procedure, which
 * is the whole reason the procedures exist (feature-rules §2).
 *
 * The procedures read the customer's estate through agora.vw_* and write
 * nothing, anywhere.
 */
class ReportsService
{
    public function __construct(protected ProcedureService $procedures) {}

    /**
     * One audit trail change, opened (2 Oct 2026): the row itself and what it
     * changed in context — the item's shifts in that stock recon run, or the
     * rows a bank recon batch stamped. Null when the change does not exist or
     * sits outside the sites the person may see.
     *
     * @param  array<int, int>  $allowed  the sites the person may see; [] = every site
     * @return array{change: object, context: Collection<int, object>}|null
     */
    public function auditChange(string $ledger, int $id, array $allowed): ?array
    {
        $sets = $this->procedures->callSets('usp_Reports_GetAuditChange', [
            'Ledger' => $ledger,
            'LedgerId' => $id,
            'AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed),
        ]);

        $change = ($sets[0] ?? collect())->first();

        return $change === null ? null : ['change' => $change, 'context' => $sets[1] ?? collect()];
    }

    /**
     * The `branches` request parameter, whichever shape it arrives in.
     *
     * The selector is `<select name="branches[]" multiple>`, so a submitted
     * form sends an ARRAY — and casting that to a string is an "Array to
     * string conversion", which is precisely how this 500'd on
     * `?branches[]=13&branches[]=18`. A hand-written or shortened link sends a
     * comma-separated string instead, and that has to keep working: it is what
     * somebody pastes into a message.
     *
     * It lives here rather than in the controller because the view needs the
     * same answer to tick the right options, and the two had grown their own
     * copies of the same `explode()` — so the bug existed twice and would have
     * been fixed once.
     *
     * @return array<int, int>
     */
    public static function branchIds(mixed $value): array
    {
        return self::branchSelection($value)['ids'];
    }

    /**
     * Keep a rejected selection distinct from an empty one: an empty branch
     * list means every site to the procedure, so discarding an invalid id
     * must not turn an explicit selection into an unrestricted report.
     *
     * @return array{ids: array<int, int>, hasSelection: bool}
     */
    public static function branchSelection(mixed $value): array
    {
        $values = match (true) {
            is_array($value) => $value,
            $value === null => [],
            is_string($value) => explode(',', $value),
            default => [$value],
        };

        $ids = [];
        $hasSelection = false;

        foreach ($values as $id) {
            if ($id === null || (is_string($id) && trim($id) === '')) {
                continue;
            }

            $hasSelection = true;
            if (! is_int($id) && ! is_string($id)) {
                continue;
            }

            $id = filter_var(trim((string) $id), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 2147483647],
            ]);
            if ($id !== false) {
                $ids[] = $id;
            }
        }

        return ['ids' => array_values(array_unique($ids)), 'hasSelection' => $hasSelection];
    }

    /**
     * The registry, as an array PHPStan can see the shape of.
     *
     * `config()` returns mixed, and mixed flowing into collect() means every
     * template downstream is unresolvable — level 6 is right to complain,
     * because at that point nothing here is checked at all.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function registry(): array
    {
        /** @var array<string, array<string, mixed>> $reports */
        $reports = config('reports.reports', []);

        return $reports;
    }

    /**
     * Every report, grouped as the Today menu groups them.
     *
     * A plain array rather than a Collection of Collections. Laravel's
     * `groupBy()` produces a nested generic that PHPStan cannot reconcile with
     * any declaration of it — the error reads "should return X but returns X",
     * which is template invariance across the two Collection boundaries and not
     * a fact about this code. Building the grouping here is three lines, says
     * exactly what comes out, and is checked.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function catalogue(): array
    {
        $grouped = [];

        foreach ($this->registry() as $key => $report) {
            $grouped[(string) ($report['group'] ?? 'Reports')][] = $report + ['key' => $key];
        }

        return $grouped;
    }

    /**
     * One report's definition, or null when the slug is not one of ours.
     *
     * @return array<string, mixed>|null
     */
    public function definition(string $key): ?array
    {
        $report = $this->registry()[$key] ?? null;

        return $report === null ? null : $report + ['key' => $key];
    }

    /**
     * Run a report and return its page, its total and the parameters it ran
     * with — the last so the screen can render what it actually asked for
     * rather than what it thinks it asked for. An optional third result set
     * supplies full-scope queue summaries independently of the paged rows.
     *
     * @param  array<string, mixed>  $input
     * @return array{rows: Collection<int, object>, total: int, params: array<string, mixed>, summaries: Collection<string, object>, summariesAvailable: bool}
     */
    public function run(string $key, array $input = []): array
    {
        $report = $this->definition($key);

        if ($report === null) {
            throw new ReportNotFound($key);
        }

        $params = $this->parametersFor($key, $input);

        if ($key === 'my-queue') {
            $requested = is_array($input['queue_keys'] ?? null) ? $input['queue_keys'] : [];
            $params['QueueKeys'] = implode(',', array_intersect(array_keys(MyQueueAccess::TYPES), $requested));
        }

        $sets = $this->procedures->callSets($report['procedure'], $params);

        return [
            'rows' => $sets[0] ?? collect(),
            // Result set 2 is one row, (TotalRows BIGINT), by contract. A
            // procedure that has lost it is a bug in the procedure, not
            // something to paper over with a count of the page.
            'total' => (int) (($sets[1] ?? collect())->first()->TotalRows ?? 0),
            'params' => $params,
            'summaries' => ($sets[2] ?? collect())->keyBy(fn (object $row): string => (string) $row->QueueKey),
            // An old procedure without this result set has unknown totals,
            // unlike a present empty set, which confirms no queues in scope.
            'summariesAvailable' => array_key_exists(2, $sets),
        ];
    }

    /**
     * The effective scope, including defaults, without running the report.
     * A refused report still needs to show the scope the person asked for.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function parametersFor(string $key, array $input = []): array
    {
        $report = $this->definition($key);

        if ($report === null) {
            throw new ReportNotFound($key);
        }

        return $this->parameters($report, $input);
    }

    /**
     * The eight parameters, cleaned. The procedure defends itself against all
     * of this as well — it has to, because the customer will call it from SSMS
     * — so this is about giving the screen sensible values, not about safety.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function parameters(array $report, array $input): array
    {
        /** @var array<int, mixed> $given */
        $given = is_array($input['branch_ids'] ?? null) ? $input['branch_ids'] : [];

        $branches = collect($given)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()                 // 0 is not a branch; see the procedures.
            ->unique()
            ->values();

        /** @var array<int, array<string, mixed>> $columns */
        $columns = is_array($report['columns'] ?? null) ? $report['columns'] : [];

        $sortable = collect($columns)->pluck('sort')->filter()->all();
        $sort = $input['sort'] ?? null;
        $sort = in_array($sort, $sortable, true) ? $sort : null;
        $sortAsc = (int) (($input['dir'] ?? 'asc') !== 'desc');
        $from = $this->date($input['from'] ?? null);
        $to = $this->date($input['to'] ?? null);

        /** @var array<string, mixed> $scope */
        $scope = is_array($report['scope'] ?? null) ? $report['scope'] : [];

        // Mirror each procedure's defaults in the visible scope. Do not clamp
        // explicit dates: SQL owns range refusals and any report-specific rules.
        if (in_array($scope['date_mode'] ?? null, ['range', 'single'], true)) {
            $to ??= BusinessDate::today()->toDateString();
            if ($scope['date_mode'] === 'single') {
                $from = null;
            } else {
                $from ??= ($scope['month_start'] ?? false)
                    ? Carbon::parse($to)->startOfMonth()->toDateString()
                    : Carbon::parse($to)->subDays((int) ($scope['lookback_days'] ?? 0))->toDateString();
            }
        } elseif (($scope['date_mode'] ?? null) === 'none') {
            $from = $to = null;
        }

        if (($report['key'] ?? null) === 'my-queue' && $sort === null) {
            $sort = 'AgeDays';
            $sortAsc = 0;
        }

        return [
            'BranchIds' => $branches->isEmpty() ? null : $branches->implode(','),
            'DateFrom' => $from,
            'DateTo' => $to,
            'Search' => trim((string) ($input['search'] ?? '')) ?: null,
            // An unknown sort uses the report default rather than an
            // unrecognised @SortColumn silently ignored by the procedure.
            'SortColumn' => $sort,
            'SortAsc' => $sortAsc,
            'Page' => max(1, (int) ($input['page'] ?? 1)),
            'PageSize' => (int) config('reports.page_size'),
        ];
    }

    /** A date the user typed, or null so the procedure applies its own default. */
    protected function date(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
