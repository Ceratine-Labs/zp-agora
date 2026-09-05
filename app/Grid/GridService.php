<?php

namespace App\Grid;

use App\Exceptions\AgoraProcException;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Models\UserGridColumn;

/**
 * Turns a request into a grid.
 *
 * Everything a grid decides is decided once, here: which columns this person
 * has, which of the query string's parameters are real, which branches they may
 * actually see, and then one call to the source. A controller asks for a grid
 * and gets one; it does not get to reach past this and hand the source
 * something unchecked.
 *
 * Two rules shape the whole class.
 *
 * **Whitelist, then degrade.** A sort value the catalogue does not know, a page
 * size that is not offered, a filter on a column that does not exist — each is
 * dropped and the grid answers with its default. A grid is a read; a URL
 * somebody edited by hand should show them something, not a 500. The one place
 * that is not true is the branch list, which is checked against the user's
 * grants and silently narrowed, because that is authorisation and not
 * convenience.
 *
 * **The same query answers the page and the extract.** GridQuery is built once
 * and the exporter is handed the same object with a different page size. That
 * is the only way "the export is what the user is looking at"
 * (feature-rules §3.2) can be true rather than intended.
 */
final class GridService
{
    public function __construct(
        private GridRegistry $registry,
        private BranchContext $context,
    ) {}

    public function registry(): GridRegistry
    {
        return $this->registry;
    }

    /** Build the grid a screen renders. */
    public function build(GridDefinition $definition, Request $request, ?int $userId = null): GridResult
    {
        $state = $userId === null ? [] : UserGridColumn::layout($userId, $definition->key());
        $columns = GridColumnState::apply($state, $definition);
        $query = $this->query($definition, $request, $state);

        $refusal = null;

        try {
            $page = $definition->source()->page($query);
        } catch (AgoraProcException $e) {
            // A procedure that REFUSES has declined to answer this particular
            // question — a range it cannot usefully cover, a scope it will not
            // take. That is a message to the person, not a 500, and it is the
            // error state feature-rules proposed §C asks every grid to have.
            // Caught here so no controller has to remember to. Anything else —
            // a deadlock, a missing procedure, a type mismatch — is a fault and
            // is still a QueryException and still a 500, because it is one.
            $refusal = $e->getMessage();
            $page = new GridPage(rows: collect(), total: 0);
        }

        [$totals, $grand] = $this->totals($definition, $page);

        return new GridResult(
            definition: $definition,
            columns: $columns,
            rows: $page->rows,
            gridQuery: $query,
            total: $page->total,
            totals: $totals,
            totalsAreGrand: $grand,
            textSize: is_string($state['text_size'] ?? null) ? $state['text_size'] : 'normal',
            state: $state,
            baseUrl: $request->url(),
            query: $request->query(),
            ms: $page->ms,
            refusal: $refusal,
        );
    }

    /**
     * The cleaned question, built from the query string over the user's saved
     * defaults over the definition's.
     *
     * @param  array<string, mixed>  $state
     */
    public function query(GridDefinition $definition, Request $request, array $state = []): GridQuery
    {
        $p = fn (string $name): string => $this->param($definition, $name);

        /** @var array<int, int> $sizes */
        $sizes = config('grids.page_sizes', [25, 50, 100, 200]);
        $requested = (int) $request->query($p('size'), (string) ($state['page_size'] ?? $definition->pageSize()));
        $pageSize = in_array($requested, $sizes, true) ? $requested : $definition->pageSize();

        $sort = $request->query($p('sort'), is_string($state['sort'] ?? null) ? $state['sort'] : $definition->defaultSort());
        $sort = in_array($sort, $definition->sortValues(), true) ? (string) $sort : $definition->defaultSort();

        $direction = $request->query($p('dir'), is_string($state['dir'] ?? null) ? $state['dir'] : $definition->defaultDirection());
        $search = trim((string) $request->query($p('q'), '')) ?: null;

        return new GridQuery(
            branchIds: $this->branchIds($definition, $request),
            from: $this->date($request->query($p('from'))),
            to: $this->date($request->query($p('to'))),
            search: $definition->searchable() ? $search : null,
            sort: $sort,
            ascending: $direction !== 'desc',
            page: max(1, (int) $request->query($p('page'), '1')),
            pageSize: $pageSize,
            filters: $this->filters($definition, $request),
        );
    }

    /**
     * The branches this run may read.
     *
     * In the branch workspace the site is pinned and whatever arrives in the
     * query string is ignored (feature-rules §3.3). In head office the ids are
     * CHECKED, not merely parsed: they become a CSV argument to a procedure,
     * which never passes through BranchScope, so nothing else in the request
     * would stop a hand-edited query string reading a site the caller is not
     * granted.
     *
     * @return array<int, int>
     */
    private function branchIds(GridDefinition $definition, Request $request): array
    {
        if ($this->context->workspace() === 'branch') {
            return array_values(array_filter([$this->context->id()]));
        }

        if (! $definition->branchSelector()) {
            return [];
        }

        $given = $request->query($this->param($definition, 'branches'));

        $ids = match (true) {
            is_array($given) => $given,
            $given === null => [],
            default => explode(',', (string) $given),
        };

        return collect($ids)
            // A nested array is not a branch id, and array_map('intval', …)
            // over one is an "Array to string conversion" in a different
            // costume — the exact 500 the Reports selector shipped with.
            ->reject(fn (mixed $id) => is_array($id) || is_object($id))
            ->map(fn (mixed $id) => (int) trim((string) $id))
            ->filter()
            ->unique()
            ->filter(fn (int $id) => $this->context->maySee($id))
            ->values()
            ->all();
    }

    /**
     * The header filters that arrived, cleaned against the catalogue.
     *
     * A source that cannot answer a filter gets none, rather than the grid
     * quietly filtering in PHP: that would put the matching semantics in two
     * places, which is precisely what feature-rules §3.2 tells us cost ZP.
     *
     * @return array<int, array<string, mixed>>
     */
    private function filters(GridDefinition $definition, Request $request): array
    {
        if (! $definition->filterable()) {
            return [];
        }

        $submitted = $request->query($this->param($definition, 'f'));

        if (! is_array($submitted)) {
            return [];
        }

        $filters = [];

        foreach ($definition->filters() as $key => $filter) {
            if (! array_key_exists($key, $submitted)) {
                continue;
            }

            $normalised = $filter->normalise($submitted[$key]);

            if ($normalised !== null) {
                $filters[] = $normalised;
            }
        }

        return $filters;
    }

    /**
     * The footer totals, and whether they are the whole answer or just this
     * page.
     *
     * A source that returns grand totals wins. Where it does not, the page is
     * added up — and the footer says so, because "total of the 50 rows you can
     * see" and "total of the 12 480 rows that match" are different numbers and
     * a footer that does not distinguish them is a figure somebody will quote.
     *
     * @return array{0: array<string, float|null>, 1: bool}
     */
    private function totals(GridDefinition $definition, GridPage $page): array
    {
        $keys = $definition->totals();

        if ($keys === []) {
            return [[], false];
        }

        $grand = array_intersect_key($page->totals, array_flip($keys));

        if ($grand !== []) {
            return [array_map(fn (mixed $v) => is_numeric($v) ? (float) $v : null, $grand), true];
        }

        $totals = [];

        foreach ($keys as $key) {
            $sum = null;

            foreach ($page->rows as $row) {
                $value = $row->{$key} ?? null;

                if (is_numeric($value)) {
                    $sum = ($sum ?? 0.0) + (float) $value;
                }
            }

            $totals[$key] = $sum;
        }

        return [$totals, false];
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            // A date nobody can parse is no date. The procedure applies its own
            // default window, which is a better answer than a 500 for somebody
            // who mistyped a URL.
            return null;
        }
    }

    /** Two grids on one screen do not share query-string names. See GridResult::param(). */
    private function param(GridDefinition $definition, string $name): string
    {
        $key = $definition->key();
        $position = strrpos($key, ':');

        return $position === false ? $name : substr($key, $position + 1).'_'.$name;
    }
}
