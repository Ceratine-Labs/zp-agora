<?php

namespace Modules\Reports\Services;

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
        $ids = match (true) {
            is_array($value) => $value,
            $value === null => [],
            default => explode(',', (string) $value),
        };

        return collect($ids)
            // A nested array is not a branch id, and array_map('intval', …)
            // over one is the same conversion error in a different costume.
            ->reject(fn (mixed $id) => is_array($id) || is_object($id))
            ->map(fn (mixed $id) => (int) trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();
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
     * rather than what it thinks it asked for.
     *
     * @param  array<string, mixed>  $input
     * @return array{rows: Collection<int, object>, total: int, params: array<string, mixed>}
     */
    public function run(string $key, array $input = []): array
    {
        $report = $this->definition($key);

        if ($report === null) {
            throw new ReportNotFound($key);
        }

        $params = $this->parameters($report, $input);

        $sets = $this->procedures->callSets($report['procedure'], $params);

        return [
            'rows' => $sets[0] ?? collect(),
            // Result set 2 is one row, (TotalRows BIGINT), by contract. A
            // procedure that has lost it is a bug in the procedure, not
            // something to paper over with a count of the page.
            'total' => (int) (($sets[1] ?? collect())->first()->TotalRows ?? 0),
            'params' => $params,
        ];
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

        return [
            'BranchIds' => $branches->isEmpty() ? null : $branches->implode(','),
            'DateFrom' => $this->date($input['from'] ?? null),
            'DateTo' => $this->date($input['to'] ?? null),
            'Search' => trim((string) ($input['search'] ?? '')) ?: null,
            // A sort column the procedure does not know falls back to its
            // natural order rather than being passed through — an unrecognised
            // @SortColumn is silently ignored by the CASE, and silence is worse
            // than a default.
            'SortColumn' => in_array($sort, $sortable, true) ? $sort : null,
            'SortAsc' => (int) (($input['dir'] ?? 'asc') !== 'desc'),
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
